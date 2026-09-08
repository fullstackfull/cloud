<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Domains\Application\Jobs\RegisterDomainAtRegistrar;
use Lynomia\Modules\Domains\Application\Jobs\RenewDomainAtRegistrar;
use Lynomia\Modules\Domains\Application\Jobs\StartDomainTransfer;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;

/**
 * The moment a paid invoice becomes a registration.
 *
 * ---------------------------------------------------------------------------
 * Why the money comes first
 * ---------------------------------------------------------------------------
 *
 * A domain cannot be repossessed. Unlike a machine, which can be suspended and
 * reclaimed if an invoice goes unpaid, a registration is a fee spent at a
 * registry the moment it succeeds — so this platform registers nothing until
 * the customer's money has arrived.
 *
 * ---------------------------------------------------------------------------
 * Why the queued state is written here and not in the job
 * ---------------------------------------------------------------------------
 *
 * The row moves from `requested` to `queued` under a lock, and the job is
 * dispatched only if this listener is the one that moved it. A settlement
 * webhook that arrives twice — which they do — would otherwise dispatch two
 * registrations for one name, and the second would either buy a second term or
 * collide at the registrar. The lock makes the duplicate a no-op.
 */
final class RegisterDomainOnPayment implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    public function handle(InvoicePaid $event): void
    {
        /** @var list<array{id: string, kind: DomainOperationKind}> $toDispatch */
        $toDispatch = DB::transaction(function () use ($event): array {
            $operations = DomainOperation::query()
                ->where('invoice_id', $event->invoiceId)
                ->where('state', DomainOperationState::Requested->value)
                ->lockForUpdate()
                ->get();

            $queued = [];

            foreach ($operations as $operation) {
                $operation->forceFill(['state' => DomainOperationState::Queued])->save();
                $queued[] = ['id' => (string) $operation->getKey(), 'kind' => $operation->kind];
            }

            return $queued;
        });

        /*
         * Dispatched after the transaction commits, not inside it. A worker is
         * quite capable of picking the job up before the commit lands, and
         * then it reads a row that still says `requested` and does nothing —
         * a registration silently dropped for a customer who has paid.
         */
        foreach ($toDispatch as $operation) {
            match ($operation['kind']) {
                DomainOperationKind::Register => RegisterDomainAtRegistrar::dispatch($operation['id']),
                DomainOperationKind::Renew => RenewDomainAtRegistrar::dispatch($operation['id']),
                DomainOperationKind::Transfer => StartDomainTransfer::dispatch($operation['id']),

                /*
                 * A redemption is not dispatched from here. Recovering a name
                 * from redemption is a registry request with a penalty fee and
                 * a manual step at most registrars, and this platform does not
                 * yet offer it — the catalogue refuses to quote one, so no
                 * invoice for one can exist to reach this line.
                 */
                DomainOperationKind::Redeem => null,
            };
        }
    }
}
