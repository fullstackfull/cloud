<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;
use Lynomia\Modules\Domains\Domain\Exceptions\UnknownRegistrarDriverException;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Ask the registry to restore a name the customer has paid the penalty for.
 *
 * ===========================================================================
 * ONE ATTEMPT, AND THE DOMAIN ITSELF GOES INDETERMINATE
 * ===========================================================================
 *
 * A redemption is a purchase with a penalty on it. A timeout may mean the
 * name was restored and the fee charged; retrying pays the penalty twice for
 * a name already back. So `$tries = 1`, and an unanswered redemption becomes
 * `indeterminate` on the operation AND on the domain — unlike a renewal,
 * where the customer still holds a working name and only the term is in
 * doubt. Here the customer holds nothing usable either way, and the honest
 * state of the row is "nobody knows", which is the state the reconciler
 * selects and settles against the registry's own answer.
 *
 * A refusal leaves the name in redemption, where the registry's clock keeps
 * running. The customer is told, plainly, that the platform could not
 * recover it and that a refund is owed.
 */
final class RedeemDomainAtRegistrar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A redemption is money. It is never retried automatically. */
    public int $tries = 1;

    public function __construct(
        private readonly string $operationId,
    ) {}

    public function handle(DomainRegistrarFactory $registrars, SecretRedactor $redactor, NotifyCustomer $notify): void
    {
        $operation = DomainOperation::query()->find($this->operationId);

        if ($operation === null || ! $operation->state->mayBeStarted()) {
            return;
        }

        $domain = $operation->domain;

        if (! $domain instanceof Domain || ! $domain->state->isRedeemable()) {
            return;
        }

        $operation->forceFill([
            'state' => DomainOperationState::Running,
            'attempts' => $operation->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $restored = $registrars->make($operation->provider)->redeem($domain->name);
        } catch (UnknownRegistrarDriverException|RegistrarNotAvailableException $e) {
            $this->fail($notify, $operation, $domain, 'domain.registrar_not_available', $redactor->redactString($e->getMessage()));

            return;
        } catch (DomainRegistrarException $e) {
            $message = $redactor->redactString($e->getMessage());

            if ($e->isIndeterminate()) {
                $operation->forceFill([
                    'state' => DomainOperationState::Indeterminate,
                    'failure_code' => $e->errorCode(),
                    'failure_message' => $message,
                ])->save();

                $domain->forceFill([
                    'state' => DomainState::Indeterminate,
                    'review_reason' => 'The registrar did not answer the redemption. '
                        .'The name may or may not have been restored; the registry is checked before anything else is done.',
                    'reconciled_at' => null,
                ])->save();

                $notify->execute(
                    customerId: (string) $operation->customer_id,
                    type: NotificationType::DomainNeedsReview,
                    idempotencyKey: 'domain-needs-review:'.$operation->getKey(),
                    subject: $domain,
                    data: ['domain' => $domain->name],
                    link: '/domains',
                );

                return;
            }

            $this->fail($notify, $operation, $domain, $e->errorCode(), $message);

            return;
        }

        $operation->forceFill([
            'state' => DomainOperationState::Completed,
            'provider_reference' => $restored->providerReference ?? $operation->provider_reference,
            'failure_code' => null,
            'failure_message' => null,
            'completed_at' => now(),
        ])->save();

        $domain->forceFill([
            'state' => DomainState::Active,
            'expires_at' => $restored->expiresAt,
            'nameservers' => $restored->nameservers === [] ? $domain->nameservers : $restored->nameservers,
            'review_reason' => null,
            'reconciled_at' => now(),
        ])->save();

        $notify->execute(
            customerId: (string) $operation->customer_id,
            type: NotificationType::DomainRedeemed,
            idempotencyKey: 'domain-redeemed:'.$operation->getKey(),
            subject: $domain,
            data: ['domain' => $domain->name, 'date' => $restored->expiresAt->toDateString()],
            link: '/domains',
        );
    }

    private function fail(NotifyCustomer $notify, DomainOperation $operation, Domain $domain, string $code, string $message): void
    {
        $operation->forceFill([
            'state' => DomainOperationState::Failed,
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();

        /*
         * The name stays in redemption and the registry's clock keeps
         * running. The row says so, and names what is owed.
         */
        $domain->forceFill([
            'review_reason' => 'The registrar refused to recover this name. '
                .'The customer has paid the penalty and is owed a refund; the name will be released by the registry when its redemption window closes.',
        ])->save();

        $notify->execute(
            customerId: (string) $operation->customer_id,
            type: NotificationType::DomainRedemptionFailed,
            idempotencyKey: 'domain-redemption-failed:'.$operation->getKey(),
            subject: $domain,
            data: ['domain' => $domain->name],
            link: '/domains',
        );
    }
}
