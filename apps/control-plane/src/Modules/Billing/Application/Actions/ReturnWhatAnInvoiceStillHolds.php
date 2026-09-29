<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;

/**
 * Hands back to the wallet what an invoice still holds for the customer, and,
 * where nothing more will be delivered against it, withdraws it.
 *
 * One implementation for the actions that give an invoice's money back
 * because what it bought will not be delivered: a cancelled order's invoice
 * (CreditWhatACancelledOrderPaid), an upgrade that lapsed unpaid
 * (RenewSubscription), the open invoices of a subscription that has ended
 * (CancelSubscription, EndTheSubscriptionWithItsService), and a paid upgrade
 * that ending prevented from being delivered (ReturnAnUpgradeTheEndPrevented).
 * Each used to carry
 * its own arithmetic, and the one in the renewal read the document's own
 * `amount_paid - amount_refunded` - blind to a card refund still pending at
 * the provider, and to a wallet refund whose row had not yet been booked onto
 * the invoice - and returned the same money twice (N-1).
 *
 * The figure is {@see WhatAnInvoiceStillHolds}, read under the invoice's row
 * lock (the lock order it documents), and the credit is a top-up carrying the
 * invoice's id: which is what makes it part of that same figure afterwards,
 * so a card refund, a booked refund or a second run of this sees it and
 * returns nothing more.
 *
 * The ledger key names the invoice, what the call is for and the figure the
 * credit was computed from (captured less refunded), so a retry that the
 * lock somehow did not serialise posts once. The lock, not the key, is what
 * makes a second run credit nothing.
 */
final readonly class ReturnWhatAnInvoiceStillHolds
{
    public function __construct(
        private WalletLedger $wallet,
        private VoidInvoice $voidInvoice,
    ) {}

    /**
     * Credits what the invoice still holds to the customer's wallet, against
     * the invoice.
     *
     * @param  string  $purpose  a short slug naming why, which goes into the ledger key
     * @param  array<string, mixed>  $metadata
     * @return int the minor units the ledger posted for this call; zero when nothing is held,
     *             or when the ledger answered with an entry already posted under the key
     */
    public function toTheWallet(Invoice $invoice, string $purpose, string $description, array $metadata = []): int
    {
        return DB::transaction(function () use ($invoice, $purpose, $description, $metadata): int {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            $capturedMinor = WhatAnInvoiceStillHolds::capturedMinor($locked);
            $alreadyInTheWalletMinor = WhatAnInvoiceStillHolds::creditedToTheWalletMinor($locked);
            $refundedMinor = WhatAnInvoiceStillHolds::refundedMinor($locked);

            $heldMinor = $capturedMinor - $alreadyInTheWalletMinor - $refundedMinor;

            if ($heldMinor <= 0) {
                return 0;
            }

            /** @var Customer $customer */
            $customer = $locked->customer()->firstOrFail();

            $entry = $this->wallet->credit(
                wallet: $this->wallet->walletFor($customer, $locked->currency),
                amount: Money::ofMinor($heldMinor, $locked->currency),
                // Stored value the customer handed over that no delivery
                // claims: the same kind, and so the same place in
                // SettleInvoice's arithmetic, as an overpayment surplus.
                kind: WalletTransactionKind::Topup,
                description: $description,
                metadata: [
                    'invoice_id' => (string) $locked->getKey(),
                    'captured_minor' => $capturedMinor,
                    'already_in_wallet_minor' => $alreadyInTheWalletMinor,
                    'refunded_minor' => $refundedMinor,
                    ...$metadata,
                ],
                idempotencyKey: sprintf('invoice:%s:%s:%d', $locked->getKey(), $purpose, $capturedMinor - $refundedMinor),
                invoiceId: (string) $locked->getKey(),
            );

            /*
             * What the ledger posted for this call. A replay - an entry
             * already under the key - was posted by something else and moved
             * nothing now; answering with the computed figure reported money
             * this call never moved (round four's re-audit). The same rule
             * ApplyPlanChange's credit follows.
             */
            return $entry->wasRecentlyCreated ? $entry->amount_minor : 0;
        });
    }

    /**
     * Returns what the invoice still holds and then voids it, as one unit: an
     * invoice nothing more will be delivered against must not stay payable,
     * and it cannot be voided while it holds money (VoidInvoice).
     *
     * @param  array<string, mixed>  $metadata
     * @return int the minor units credited
     */
    public function andWithdraw(
        Invoice $invoice,
        string $purpose,
        string $description,
        string $voidReason,
        array $metadata = [],
    ): int {
        return DB::transaction(function () use ($invoice, $purpose, $description, $voidReason, $metadata): int {
            $credited = $this->toTheWallet($invoice, $purpose, $description, $metadata);

            $this->voidInvoice->execute($invoice, $voidReason);

            return $credited;
        });
    }
}
