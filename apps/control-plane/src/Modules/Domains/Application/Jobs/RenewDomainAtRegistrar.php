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
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Extend a name the customer has already paid to keep.
 *
 * ===========================================================================
 * ONE ATTEMPT, FOR THE SAME REASON AS A REGISTRATION
 * ===========================================================================
 *
 * A renewal is a purchase. A timeout may mean the term was extended and the
 * platform charged; retrying it adds a year nobody asked for, and registries
 * do not give those back. So `$tries = 1`, and an unanswered renewal becomes
 * `indeterminate` for the reconciler to settle against the registry's own
 * expiry date — which is exactly the fact that decides it.
 *
 * The one difference from a registration: a renewal that fails leaves a name
 * the customer still holds, right up until it expires. That is why the failure
 * is loud on the row rather than merely recorded — the deadline is real and it
 * is coming.
 */
final class RenewDomainAtRegistrar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A renewal is money. It is never retried automatically. */
    public int $tries = 1;

    public function __construct(
        private readonly string $operationId,
    ) {}

    public function handle(DomainRegistrarFactory $registrars, SecretRedactor $redactor): void
    {
        $operation = DomainOperation::query()->find($this->operationId);

        if ($operation === null || ! $operation->state->mayBeStarted()) {
            return;
        }

        $domain = $operation->domain;

        if (! $domain instanceof Domain || ! $domain->state->isRenewable()) {
            return;
        }

        $operation->forceFill([
            'state' => DomainOperationState::Running,
            'attempts' => $operation->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $renewed = $registrars->make($operation->provider)->renew($domain->name, $operation->term_years);
        } catch (UnknownRegistrarDriverException|RegistrarNotAvailableException $e) {
            $this->fail($operation, $domain, 'domain.registrar_not_available', $redactor->redactString($e->getMessage()));

            return;
        } catch (DomainRegistrarException $e) {
            $message = $redactor->redactString($e->getMessage());

            if ($e->isIndeterminate()) {
                $operation->forceFill([
                    'state' => DomainOperationState::Indeterminate,
                    'failure_code' => $e->errorCode(),
                    'failure_message' => $message,
                ])->save();

                /*
                 * The domain's own state is left alone. Unlike a registration,
                 * the customer still holds this name — the question is only
                 * whether the term moved, and the reconciler answers it by
                 * reading the registry's expiry date. Marking the domain
                 * indeterminate here would take a working name off the
                 * customer's screen for a bookkeeping doubt.
                 */
                $domain->forceFill(['reconciled_at' => null])->save();

                return;
            }

            $this->fail($operation, $domain, $e->errorCode(), $message);

            return;
        }

        $operation->forceFill([
            'state' => DomainOperationState::Completed,
            'failure_code' => null,
            'failure_message' => null,
            'completed_at' => now(),
        ])->save();

        $domain->forceFill([
            'state' => DomainState::Active,
            'expires_at' => $renewed->expiresAt,
            'review_reason' => null,
        ])->save();
    }

    private function fail(DomainOperation $operation, Domain $domain, string $code, string $message): void
    {
        $operation->forceFill([
            'state' => DomainOperationState::Failed,
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();

        /*
         * The name is still held and its expiry has not moved. What has
         * changed is that somebody has to act before it does — so this is
         * flagged rather than logged, and it names the deadline.
         */
        $domain->forceFill([
            'review_reason' => 'The renewal was refused by the registrar. '
                .'This name will expire on its current date unless somebody acts.',
        ])->save();
    }
}
