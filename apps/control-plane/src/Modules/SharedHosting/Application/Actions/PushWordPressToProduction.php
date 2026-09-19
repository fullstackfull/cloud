<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressPushScope;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\WordPressRefusedException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;

/**
 * Overwrite the production site with its staging copy.
 *
 * The one act on the WordPress surface that destroys something a customer
 * wrote, and it is arranged like every other such act here: the
 * production domain typed back exactly, an impact plan written before the
 * toolkit is asked, nothing else running against either site. The impact
 * plan says the thing a push button never says by itself — that a
 * database push takes with it every post, comment and order production
 * received since the copy was made — and says plainly that this platform
 * holds no backup of a shared-hosting site: whatever the panel's own
 * backups are, they are not this platform's to promise.
 */
final readonly class PushWordPressToProduction
{
    public function __construct(
        private WordPressCopySupport $support,
        private CreateProvisioningJob $jobs,
    ) {}

    /**
     * @return array<string, mixed> what a push would overwrite, for the confirmation screen
     *
     * @throws WordPressRefusedException
     */
    public function impact(WordPressSite $staging, WordPressPushScope $scope): array
    {
        $production = $this->productionOf($staging);

        $warnings = [
            sprintf('%s will be overwritten with the staging copy. This cannot be undone from this platform.', $production->domain),
        ];

        if ($scope->overwritesDatabase()) {
            $warnings[] = sprintf(
                'The database is replaced: every post, comment, order and user %s received since the copy was made on %s is lost.',
                $production->domain,
                $staging->created_at->toDateString(),
            );
        }

        if ($scope !== WordPressPushScope::Database) {
            $warnings[] = 'The files are replaced: themes, plugins and uploads on the live site that are not in the copy are lost.';
        }

        $warnings[] = 'This platform holds no backup of a shared-hosting site. If you need one, take it in the panel before pushing.';

        return [
            'staging_domain' => $staging->domain,
            'production_domain' => $production->domain,
            'scope' => $scope->value,
            'copy_made_at' => $staging->created_at->toIso8601String(),
            'production_verified_at' => $production->verified_at?->toIso8601String(),
            'platform_backup' => null,
            'warnings' => $warnings,
        ];
    }

    /**
     * @throws WordPressRefusedException
     */
    public function execute(WordPressSite $staging, WordPressPushScope $scope, string $confirmation, ?string $userId): WordPressSiteOperation
    {
        $production = $this->productionOf($staging);

        if (! hash_equals($production->domain, $confirmation)) {
            throw WordPressRefusedException::becauseTheConfirmationDoesNotMatch();
        }

        $resolved = $this->support->resolve($staging);

        if ($this->support->operationInFlight($staging) || $this->support->operationInFlight($production)) {
            throw WordPressRefusedException::becauseAnOperationIsInFlight($staging->domain);
        }

        $operation = WordPressSiteOperation::query()->create([
            'customer_id' => $staging->customer_id,
            'wordpress_site_id' => $staging->getKey(),
            'target_site_id' => $production->getKey(),
            'kind' => WordPressOperationKind::PushToProduction,
            'state' => WordPressOperationState::Requested,
            'scope' => $scope,
            'impact' => $this->impact($staging, $scope),
            'requested_by_user_id' => $userId,
        ]);

        $job = $this->jobs->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::PushWordPressToProduction,
            idempotencyKey: 'push-wordpress:'.$operation->getKey(),
            provider: $resolved['node']->panel->value,
            serviceId: $staging->service_id,
            customerId: (string) $staging->customer_id,
            payload: ['operation_id' => (string) $operation->getKey()],
        ));

        $operation->forceFill(['provisioning_job_id' => $job->getKey()])->save();

        RunProvisioningJob::dispatch((string) $job->getKey());

        return $operation->refresh();
    }

    /**
     * @throws WordPressRefusedException
     */
    private function productionOf(WordPressSite $staging): WordPressSite
    {
        if (! $staging->kind->canBePushedToProduction()) {
            throw WordPressRefusedException::becauseOnlyAStagingCopyCanBePushed($staging->domain);
        }

        $production = $staging->parent()->first();

        if (! $production instanceof WordPressSite || $production->customer_id !== $staging->customer_id) {
            throw WordPressRefusedException::becauseTheProductionSiteIsGone($staging->domain);
        }

        return $production;
    }
}
