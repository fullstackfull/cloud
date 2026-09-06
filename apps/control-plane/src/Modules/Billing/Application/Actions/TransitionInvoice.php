<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\StateMachines\InvoiceStateMachine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * The only place an invoice's status changes.
 *
 * The invoice actions all call it rather than assigning the column, so that
 * "an invoice never goes back to open once it is paid" is enforced in one
 * table instead of being re-argued at every call site. It is safe to call from
 * inside another transaction: the row lock it takes is already held by the
 * caller in that case, and the re-read then sees the caller's own writes.
 *
 * There is no invoice_transitions table, so unlike an order an invoice keeps
 * no per-change audit trail; what happened to it is reconstructed from its
 * transactions, refunds and timestamps.
 */
final readonly class TransitionInvoice
{
    public function __construct(
        private InvoiceStateMachine $stateMachine,
    ) {}

    /**
     * @throws IllegalStateTransitionException
     */
    public function execute(Invoice $invoice, InvoiceStatus $to): Invoice
    {
        // Re-entering the same state is a no-op rather than an error: retried
        // jobs and redelivered webhooks converge here.
        if ($invoice->status === $to) {
            return $invoice;
        }

        $this->stateMachine->assertCanTransition($invoice->status, $to);

        return DB::transaction(function () use ($invoice, $to): Invoice {
            /*
             * The caller's copy can be stale — a webhook marking an invoice
             * paid while a dunning job marks the same one uncollectible — so
             * the authoritative "from" is the locked row's status, not the one
             * that was checked above.
             */
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($locked->status === $to) {
                return $locked;
            }

            $this->stateMachine->assertCanTransition($locked->status, $to);

            $locked->status = $to;
            $this->stampTimestamps($locked, $to);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Keeps the document's dates in step with its status.
     *
     * Every stamp is written only if absent: these are the dates printed on a
     * document that has already been sent, so a second transition into the same
     * state must never move them.
     */
    private function stampTimestamps(Invoice $invoice, InvoiceStatus $to): void
    {
        match ($to) {
            InvoiceStatus::Open => $invoice->issued_at ??= now(),
            InvoiceStatus::Paid => $invoice->paid_at ??= now(),
            InvoiceStatus::Void => $invoice->voided_at ??= now(),
            default => null,
        };
    }
}
