<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Handlers;

use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressStagingProvider;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressCopyRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;

/**
 * Make the copy the customer asked for, and record what came back.
 *
 * A refusal leaves the target row `failed` and the operation `failed`:
 * nothing was written, and the customer may ask again. A timeout leaves
 * both `indeterminate`: the copy may exist under the new name, and asking
 * again would copy over it. The next verification pass settles a copy
 * that exists, as it settles any other site.
 */
final readonly class CopyWordPressSiteHandler implements ProvisioningHandler
{
    public function __construct(
        private HostingProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::CopyWordPressSite;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        $operation = WordPressSiteOperation::query()->find((string) ($job->payload['operation_id'] ?? ''));

        if (! $operation instanceof WordPressSiteOperation) {
            return ProvisioningResult::failed(FailureClass::Permanent, 'wordpress.unknown_operation', 'No copy operation exists with the id this job names.');
        }

        if (! $operation->state->isInFlight()) {
            return ProvisioningResult::succeeded(providerReference: (string) $operation->getKey(), metadata: ['skipped' => 'the operation is already settled']);
        }

        $source = $operation->site()->first();
        $target = $operation->target()->first();

        if (! $source instanceof WordPressSite || ! $target instanceof WordPressSite) {
            return $this->fail($operation, null, FailureClass::Permanent, 'wordpress.copy_sites_missing', 'The source or the target site of this copy no longer exists.');
        }

        $account = $source->hostingAccount()->first();
        $node = $account instanceof HostingAccount ? $account->node()->first() : null;

        if (! $account instanceof HostingAccount || ! $node instanceof HostingNode) {
            return $this->fail($operation, $target, FailureClass::Permanent, 'wordpress.account_not_ready', 'The site is not on a hosting account on a node.');
        }

        $provider = $this->providers->for($node);

        if (! $provider instanceof WordPressStagingProvider) {
            return $this->fail($operation, $target, FailureClass::Permanent, 'wordpress.panel_cannot_copy', 'This account is on a panel whose toolkit cannot copy WordPress sites.');
        }

        $operation->forceFill(['state' => WordPressOperationState::Running, 'started_at' => now()])->save();

        try {
            $copied = $provider->copyWordPress($node, new WordPressCopyRequest(
                username: $account->username,
                sourceDomain: $source->domain,
                targetDomain: $target->domain,
            ));
        } catch (HostingProviderException $e) {
            $message = $this->redactor->redactString($e->getMessage());

            if ($e->isIndeterminate()) {
                $target->forceFill([
                    'state' => WordPressSiteState::Indeterminate,
                    'failure_reason' => $message,
                    'review_reason' => 'The toolkit did not answer the copy. The copy may or may not exist; check the account before copying again.',
                ])->save();
                $operation->forceFill(['state' => WordPressOperationState::Indeterminate, 'failure_reason' => $message])->save();

                return ProvisioningResult::failed(FailureClass::Timeout, 'wordpress.copy_indeterminate', $message, metadata: $this->redactor->redact($e->context()));
            }

            return $this->fail($operation, $target, FailureClass::Transient, 'wordpress.copy_refused', $message, $this->redactor->redact($e->context()));
        }

        $target->forceFill([
            'installed' => true,
            'site_url' => $copied->siteUrl,
            'wordpress_version' => $copied->version,
            'state' => WordPressSiteState::AwaitingCertificate,
            'failure_reason' => null,
        ])->save();

        $operation->forceFill(['state' => WordPressOperationState::Succeeded, 'finished_at' => now()])->save();

        return ProvisioningResult::succeeded(providerReference: $target->domain);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fail(WordPressSiteOperation $operation, ?WordPressSite $target, FailureClass $class, string $code, string $message, array $metadata = []): ProvisioningResult
    {
        $target?->forceFill(['state' => WordPressSiteState::Failed, 'failure_reason' => $message])->save();
        $operation->forceFill(['state' => WordPressOperationState::Failed, 'failure_reason' => $message, 'finished_at' => now()])->save();

        return ProvisioningResult::failed($class, $code, $message, metadata: $metadata);
    }
}
