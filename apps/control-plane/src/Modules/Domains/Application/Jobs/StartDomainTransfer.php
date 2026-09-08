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
 * Ask the losing registrar to let a name go.
 *
 * ===========================================================================
 * A TRANSFER IS NOT AN OPERATION THAT FINISHES
 * ===========================================================================
 *
 * It is a request that sits, sometimes for five days, while the losing
 * registrar waits for its own customer to approve or fail to object. That is
 * the registry's design, not a delay this platform can shorten, and the one
 * thing this job must not do is pretend otherwise.
 *
 * So a started transfer goes to `awaiting_registry` — a state that is neither
 * success nor failure, which the customer's screen renders as what it is: sent
 * and waiting on somebody else. The reconciler polls it.
 *
 * The auth code passed here is never stored. It arrives, it is sent, and it
 * goes out of scope with the job.
 */
final class StartDomainTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A transfer is money and a registry-side state change. One attempt. */
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

        if (! $domain instanceof Domain || $domain->state !== DomainState::TransferPending) {
            return;
        }

        $operation->forceFill([
            'state' => DomainOperationState::Running,
            'attempts' => $operation->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        $code = (string) $operation->authorisation_code;

        /*
         * Erased before the call, not after. A job that dies mid-transfer must
         * not leave a domain's bearer credential sitting in the database
         * waiting for a retry that the Timeout Rule forbids anyway.
         */
        $operation->forceFill(['authorisation_code' => null])->save();

        try {
            $status = $registrars->make($operation->provider)->startTransfer(
                $domain->name,
                $code,
                $domain->nameservers ?? [],
            );
        } catch (UnknownRegistrarDriverException|RegistrarNotAvailableException $e) {
            $this->fail($operation, $domain, 'domain.registrar_not_available', $redactor->redactString($e->getMessage()));

            return;
        } catch (DomainRegistrarException $e) {
            $message = $redactor->redactString($e->getMessage());

            if ($e->isIndeterminate()) {
                /*
                 * The transfer may be running at the registry. Starting a
                 * second one is refused by every registry anyway, and would
                 * charge for a second year in the registries that accept it.
                 * The reconciler asks what the state really is.
                 */
                $operation->forceFill([
                    'state' => DomainOperationState::Indeterminate,
                    'failure_code' => $e->errorCode(),
                    'failure_message' => $message,
                ])->save();

                $domain->forceFill(['reconciled_at' => null])->save();

                return;
            }

            $this->fail($operation, $domain, $e->errorCode(), $message);

            return;
        }

        /*
         * Sent, and now somebody else's decision. Not `completed`: a customer
         * told their transfer is done, who then finds the name still at their
         * old registrar five days later, has been lied to by a status field.
         */
        $operation->forceFill([
            'state' => $status->isCompleted()
                ? DomainOperationState::Completed
                : DomainOperationState::AwaitingRegistry,
            'provider_reference' => $status->providerReference,
            'failure_code' => null,
            'failure_message' => null,
            'completed_at' => $status->isCompleted() ? now() : null,
        ])->save();

        if ($status->isCompleted()) {
            $domain->forceFill([
                'state' => DomainState::Active,
                'provider_reference' => $status->providerReference,
                'expires_at' => $status->expiresAt,
                'registered_at' => $domain->registered_at ?? now(),
            ])->save();
        }
    }

    private function fail(DomainOperation $operation, Domain $domain, string $code, string $message): void
    {
        $operation->forceFill([
            'state' => DomainOperationState::Failed,
            'failure_code' => $code,
            'failure_message' => $message,
        ])->save();

        /*
         * A refused transfer leaves the name exactly where it was: at the
         * other registrar, working, owned by the same customer. The row here
         * must stop holding the name so a later attempt can be made.
         */
        $domain->forceFill([
            'state' => DomainState::Failed,
            'review_reason' => 'The transfer was refused. The name remains with its current registrar, '
                .'and the customer has paid for a transfer that did not happen.',
        ])->save();
    }
}
