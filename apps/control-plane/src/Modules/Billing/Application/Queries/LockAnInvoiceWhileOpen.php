<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Queries;

use Illuminate\Support\Facades\DB;
use LogicException;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Throwable;

/**
 * Locks an invoice only if it is still open, and holds no lock on it if not.
 *
 * For the actions that find an open invoice by an unlocked read and must lock
 * it before its subscription (a renewal lapsing an upgrade,
 * WindUpAnEndedSubscription). Locked by its id alone, an invoice paid between
 * the read and the lock was held as a paid invoice while they went on to wait
 * for the subscription - and ApplyPlanChange, holding the subscription, waits
 * for the paid invoices a downgrade credit is drawn from: a deadlock (40P01),
 * measured across processes by the round-four verifier and pinned by
 * ARenewalAndAPlanChangeDoNotDeadlockTest.
 *
 * Putting the status in the locking statement is not enough: PostgreSQL, having
 * waited for a row, locks its newest version before it re-checks the WHERE,
 * and keeps that lock when the row no longer matches. So the lock is taken
 * inside a savepoint and the savepoint rolled back when the row is no longer
 * open, which releases the row lock (it belongs to the aborted
 * subtransaction). What is left is the lock order WhatAnInvoiceStillHolds
 * writes down: nothing holding a paid invoice's lock waits for a subscription.
 */
final class LockAnInvoiceWhileOpen
{
    /**
     * Inside the caller's transaction only: the lock it returns is held by
     * that transaction, and outside one it would be released at once.
     */
    public static function take(string $invoiceId): ?Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An invoice is locked while open inside a transaction, which holds the lock.');
        }

        DB::beginTransaction();

        try {
            /** @var Invoice|null $locked */
            $locked = Invoice::query()->lockForUpdate()->find($invoiceId);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($locked === null || $locked->status !== InvoiceStatus::Open) {
            DB::rollBack();

            return null;
        }

        DB::commit();

        return $locked;
    }
}
