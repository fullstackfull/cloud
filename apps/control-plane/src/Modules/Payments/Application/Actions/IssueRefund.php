<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Domain\Exceptions\InvalidRefundAmountException;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundExceedsCaptureException;
use Lynomia\Modules\Payments\Domain\Exceptions\TransactionNotRefundableException;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Throwable;

/**
 * Returns money to a customer against one captured transaction.
 *
 * The refundable balance is recomputed under a lock on the transaction row,
 * never trusted from the caller. Without that, two operators refunding the
 * same payment at the same moment each read "10.000 KWD captured, nothing
 * refunded" and each issue 10.000, and the platform has paid out twice what it
 * took. The lock is taken on the transaction rather than on the refund rows
 * because an aggregate cannot be locked: serialising on the parent is what
 * makes the sum that follows it authoritative.
 *
 * The provider call happens *after* the lock is released, with the refund row
 * already written as pending. That ordering is the reason the balance holds: a
 * pending refund counts against the capture from the instant it is committed,
 * so a second refund arriving mid-flight sees the reservation, while holding a
 * row lock across a network call would block every other refund for the
 * duration of Stripe's latency.
 */
final readonly class IssueRefund
{
    public function __construct(
        private PaymentProviderRegistry $registry,
    ) {}

    /**
     * @throws TransactionNotRefundableException
     * @throws RefundExceedsCaptureException
     */
    public function execute(
        Transaction $transaction,
        Money $amount,
        string $reason,
        ?User $issuedBy = null,
        ?string $invoiceId = null,
    ): Refund {
        $this->assertRefundable($transaction, $amount);

        $refund = DB::transaction(fn (): Refund => $this->reserve(
            $transaction,
            $amount,
            $reason,
            $issuedBy,
            $invoiceId ?? $transaction->invoice_id,
        ));

        $provider = $this->registry->get($transaction->provider);

        try {
            $result = $provider->refund((string) $transaction->provider_reference, $amount, $reason);
        } catch (Throwable $e) {
            /*
             * Release the reservation. Leaving it pending would permanently
             * withhold that part of the capture from any future refund, for a
             * payout that never happened.
             */
            $refund->fill([
                'status' => RefundStatus::Failed,
                'provider_metadata' => ['error' => $e->getMessage()],
            ])->save();

            throw $e;
        }

        $refund->fill([
            'status' => $result->status,
            'provider_reference' => $result->reference,
            'provider_metadata' => $result->metadata,
            'processed_at' => $result->status === RefundStatus::Succeeded ? now() : null,
        ])->save();

        if ($result->status === RefundStatus::Succeeded) {
            event(new RefundIssued(
                refundId: $refund->id,
                transactionId: $transaction->id,
                customerId: $transaction->customer_id,
                invoiceId: $refund->invoice_id,
                provider: $transaction->provider,
                amount: $amount,
                remainingRefundable: $transaction->fresh()?->refundableAmount() ?? Money::zero($amount->currency()),
                reason: $reason,
                issuedByUserId: $issuedBy?->id,
                issuedAt: now()->toImmutable(),
            ));
        }

        return $refund;
    }

    /**
     * Writes the pending refund under a lock on the transaction, after
     * re-reading the balance from the locked row.
     */
    private function reserve(
        Transaction $transaction,
        Money $amount,
        string $reason,
        ?User $issuedBy,
        ?string $invoiceId,
    ): Refund {
        /** @var Transaction $locked */
        $locked = Transaction::query()->lockForUpdate()->findOrFail($transaction->getKey());

        $captured = $locked->amount();
        // Safe to aggregate without locking the refund rows: every writer of
        // those rows holds the transaction lock we are holding now.
        $refunded = $locked->refundedAmount();
        $refundable = $captured->minus($refunded);

        if ($amount->isGreaterThan($refundable)) {
            throw RefundExceedsCaptureException::forTransaction(
                (string) $locked->id,
                $amount,
                $refundable,
                $captured,
            );
        }

        return Refund::create([
            'transaction_id' => $locked->id,
            'invoice_id' => $invoiceId,
            'issued_by_user_id' => $issuedBy?->id,
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'status' => RefundStatus::Pending,
            'reason' => mb_substr($reason, 0, 255),
        ]);
    }

    private function assertRefundable(Transaction $transaction, Money $amount): void
    {
        if ($transaction->status !== TransactionStatus::Succeeded || $transaction->kind !== TransactionKind::Charge) {
            throw TransactionNotRefundableException::inStatus((string) $transaction->id, $transaction->status);
        }

        if ($transaction->provider_reference === null || $transaction->provider_reference === '') {
            throw TransactionNotRefundableException::withoutProviderReference((string) $transaction->id);
        }

        if (! $amount->isPositive()) {
            throw InvalidRefundAmountException::notPositive($amount);
        }

        // Throws CurrencyMismatchException rather than converting: a refund is
        // always in the currency the money was taken in.
        $transaction->amount()->minus($amount);
    }
}
