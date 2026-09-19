<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\WordPressRefusedException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;

/**
 * Ask for a copy of a site: a staging copy under a subdomain the platform
 * names, or a clone under a domain the customer names.
 *
 * Both are the same toolkit call and the same job. What differs is the
 * row that comes out of it: a staging copy belongs to its parent and is
 * the only kind that can be pushed back; a clone is a production site in
 * its own right. Nothing is copied here — the job does it, and the new
 * site row starts `installing` so a screen never shows a copy that does
 * not exist yet as one that does.
 */
final readonly class CopyWordPressSite
{
    public function __construct(
        private WordPressCopySupport $support,
        private CreateProvisioningJob $jobs,
    ) {}

    /**
     * @throws WordPressRefusedException
     */
    public function staging(WordPressSite $site, ?string $userId): WordPressSiteOperation
    {
        if (! $site->kind->canBeCopied()) {
            throw WordPressRefusedException::becauseTheSiteIsNotReady($site->domain, $site->kind->value);
        }

        if ($this->support->hasStagingCopy($site)) {
            throw WordPressRefusedException::becauseAStagingCopyAlreadyExists($site->domain);
        }

        return $this->copy($site, 'staging.'.$site->domain, WordPressSiteKind::Staging, WordPressOperationKind::CreateStaging, $userId);
    }

    /**
     * @throws WordPressRefusedException
     */
    public function clone(WordPressSite $site, string $targetDomain, ?string $userId): WordPressSiteOperation
    {
        if (! $site->kind->canBeCopied()) {
            throw WordPressRefusedException::becauseTheSiteIsNotReady($site->domain, $site->kind->value);
        }

        // The same rule an order applies: lower-cased, trimmed of dots and
        // whitespace, and it has to have a dot in it.
        $targetDomain = strtolower(trim($targetDomain, " \t\n\r\0\x0B."));

        if ($targetDomain === '' || ! str_contains($targetDomain, '.') || preg_match('/^[a-z0-9.-]{3,253}$/', $targetDomain) !== 1) {
            throw WordPressRefusedException::becauseTheDomainIsUnusable($targetDomain);
        }

        return $this->copy($site, $targetDomain, WordPressSiteKind::Clone, WordPressOperationKind::Clone, $userId);
    }

    /**
     * @throws WordPressRefusedException
     */
    private function copy(WordPressSite $site, string $targetDomain, WordPressSiteKind $kind, WordPressOperationKind $operationKind, ?string $userId): WordPressSiteOperation
    {
        $resolved = $this->support->resolve($site);

        if ($this->support->operationInFlight($site)) {
            throw WordPressRefusedException::becauseAnOperationIsInFlight($site->domain);
        }

        // A name any account already serves — a clone target typed by hand
        // or the `staging.` name of this site — is refused by name, never
        // left to the unique index.
        if (WordPressSite::query()->where('domain', $targetDomain)->whereNotIn('state', [WordPressSiteState::Removed->value, WordPressSiteState::Failed->value])->exists()) {
            throw WordPressRefusedException::becauseTheDomainIsAlreadyUsed($targetDomain);
        }

        $target = WordPressSite::query()->create([
            'customer_id' => $site->customer_id,
            'hosting_account_id' => $site->hosting_account_id,
            'service_id' => $site->service_id,
            'domain' => $targetDomain,
            'domain_id' => $kind === WordPressSiteKind::Staging ? $site->domain_id : null,
            'domain_source' => $site->domain_source,
            'kind' => $kind,
            'parent_site_id' => $site->getKey(),
            'state' => WordPressSiteState::Installing,
            // A subdomain of a name that resolves here resolves here; a
            // clone's name is the customer's to point, like any external one.
            'dns_ready' => $kind === WordPressSiteKind::Staging ? $site->dns_ready : false,
            'installed' => false,
            'ssl_status' => SslStatus::Unknown,
            'admin_username' => $site->admin_username,
            'admin_email' => $site->admin_email,
            'locale' => $site->locale,
        ]);

        $operation = WordPressSiteOperation::query()->create([
            'customer_id' => $site->customer_id,
            'wordpress_site_id' => $site->getKey(),
            'target_site_id' => $target->getKey(),
            'kind' => $operationKind,
            'state' => WordPressOperationState::Requested,
            'requested_by_user_id' => $userId,
        ]);

        $job = $this->jobs->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::CopyWordPressSite,
            idempotencyKey: 'copy-wordpress:'.$operation->getKey(),
            provider: $resolved['node']->panel->value,
            serviceId: $site->service_id,
            customerId: (string) $site->customer_id,
            payload: ['operation_id' => (string) $operation->getKey()],
        ));

        $operation->forceFill(['provisioning_job_id' => $job->getKey()])->save();

        RunProvisioningJob::dispatch((string) $job->getKey());

        return $operation->refresh();
    }
}
