<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Handlers;

use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressStagingProvider;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressPushRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressPushScope;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;

/**
 * Overwrite production with the staging copy, and say what happened.
 *
 * The worst case on the WordPress surface is a push that timed out:
 * production may be half-written. So the production row is marked
 * `needs_review` with the reason, the operation `indeterminate`, the
 * customer is told not to push again, and nothing here retries. A refusal
 * leaves production exactly as it was, and says so.
 */
final readonly class PushWordPressToProductionHandler implements ProvisioningHandler
{
    public function __construct(
        private HostingProviderFactory $providers,
        private SecretRedactor $redactor,
        private NotifyCustomer $notify,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::PushWordPressToProduction;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        $operation = WordPressSiteOperation::query()->find((string) ($job->payload['operation_id'] ?? ''));

        if (! $operation instanceof WordPressSiteOperation) {
            return ProvisioningResult::failed(FailureClass::Permanent, 'wordpress.unknown_operation', 'No push operation exists with the id this job names.');
        }

        if (! $operation->state->isInFlight()) {
            return ProvisioningResult::succeeded(providerReference: (string) $operation->getKey(), metadata: ['skipped' => 'the operation is already settled']);
        }

        $staging = $operation->site()->first();
        $production = $operation->target()->first();

        if (! $staging instanceof WordPressSite || ! $production instanceof WordPressSite) {
            return $this->fail($operation, null, FailureClass::Permanent, 'wordpress.push_sites_missing', 'The staging copy or the production site no longer exists.');
        }

        $account = $staging->hostingAccount()->first();
        $node = $account instanceof HostingAccount ? $account->node()->first() : null;

        if (! $account instanceof HostingAccount || ! $node instanceof HostingNode) {
            return $this->fail($operation, $production, FailureClass::Permanent, 'wordpress.account_not_ready', 'The site is not on a hosting account on a node.');
        }

        $provider = $this->providers->for($node);

        if (! $provider instanceof WordPressStagingProvider) {
            return $this->fail($operation, $production, FailureClass::Permanent, 'wordpress.panel_cannot_copy', 'This account is on a panel whose toolkit cannot push WordPress sites.');
        }

        $scope = $operation->scope ?? WordPressPushScope::Both;

        $operation->forceFill(['state' => WordPressOperationState::Running, 'started_at' => now()])->save();

        try {
            $pushed = $provider->pushWordPressToProduction($node, new WordPressPushRequest(
                username: $account->username,
                stagingDomain: $staging->domain,
                productionDomain: $production->domain,
                scope: $scope,
            ));
        } catch (HostingProviderException $e) {
            $message = $this->redactor->redactString($e->getMessage());

            if ($e->isIndeterminate()) {
                $production->forceFill([
                    'state' => WordPressSiteState::NeedsReview,
                    'failure_reason' => $message,
                    'review_reason' => 'The toolkit did not answer the push. The live site may be partly overwritten; check it before pushing again.',
                ])->save();
                $operation->forceFill(['state' => WordPressOperationState::Indeterminate, 'failure_reason' => $message])->save();
                $this->tell($operation, $production, NotificationType::WordPressPushNeedsReview, $scope);

                return ProvisioningResult::failed(FailureClass::Timeout, 'wordpress.push_indeterminate', $message, metadata: $this->redactor->redact($e->context()));
            }

            $this->tell($operation, $production, NotificationType::WordPressPushFailed, $scope);

            return $this->fail($operation, $production, FailureClass::Transient, 'wordpress.push_refused', $message, $this->redactor->redact($e->context()));
        }

        $production->forceFill([
            'wordpress_version' => $pushed->version ?? $production->wordpress_version,
            // Verified again by the next pass: what is live now is the copy,
            // and the platform has not looked at it yet.
            'verified_at' => null,
            'failure_reason' => null,
        ])->save();

        $operation->forceFill(['state' => WordPressOperationState::Succeeded, 'finished_at' => now()])->save();
        $this->tell($operation, $production, NotificationType::WordPressPushCompleted, $scope);

        return ProvisioningResult::succeeded(providerReference: $production->domain);
    }

    private function tell(WordPressSiteOperation $operation, WordPressSite $production, NotificationType $type, WordPressPushScope $scope): void
    {
        $this->notify->execute(
            customerId: $operation->customer_id,
            type: $type,
            idempotencyKey: 'wordpress-push:'.$operation->getKey().':'.$type->value,
            subject: $operation,
            data: ['domain' => $production->domain, 'scope' => $scope->value],
            link: '/wordpress',
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function fail(WordPressSiteOperation $operation, ?WordPressSite $production, FailureClass $class, string $code, string $message, array $metadata = []): ProvisioningResult
    {
        // Production is not touched on a refusal: the row keeps its state
        // and only the operation says what the toolkit said.
        $operation->forceFill(['state' => WordPressOperationState::Failed, 'failure_reason' => $message, 'finished_at' => now()])->save();

        return ProvisioningResult::failed($class, $code, $message, metadata: [...$metadata, 'production' => $production?->domain]);
    }
}
