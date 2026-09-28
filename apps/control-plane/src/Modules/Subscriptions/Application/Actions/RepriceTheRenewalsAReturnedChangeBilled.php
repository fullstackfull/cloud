<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Repositories\CouponTermsRepository;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;

/**
 * Reprices the renewals a returned plan change billed, when the subscription
 * goes back to the plan the change came from (ReturnAHeldPaidChange).
 *
 * A paid upgrade moves the subscription's plan and recurring amount when it
 * is settled; its resize can then stop and be held for an operator, and a
 * renewal issued meanwhile bills the new plan's price. When the operator
 * returns the change and the plan goes back, those renewals billed a period
 * the subscription is no longer on: 90.000 for a machine that ran the 9.000
 * plan all along, left open, or paid in part from the wallet with 17.000
 * still owing (N1, the verification of round ten M, 1af8ec1). The customer
 * was told the subscription "is billed at that plan's price", and it was not.
 *
 * Which renewals: every invoice of the subscription carrying a renewal line
 * (InvoiceItemKind::Plan) whose unit price is the price the change moved the
 * subscription to, issued at or after the change was made, and still open or
 * paid (renewalsToReprice()). Each is priced again at the price the change
 * came from, the way the renewal sweep prices one (RenewDueSubscriptions):
 * the same quantity, the tax rate recorded on its line, and - when its line
 * carried a discount - the subscription's coupon terms, or else the same
 * fixed amount off. Then:
 *
 *  - An open renewal is withdrawn and issued again. What it holds - a part
 *    already paid against it - goes back to the wallet, recorded against it,
 *    and it is voided (ReturnWhatAnInvoiceStillHolds::andWithdraw(), the
 *    idiom a lapsing upgrade and an ended subscription's open invoices
 *    already use); a new renewal for the same period is issued at the right
 *    price (IssueInvoice), open, for the customer to pay as any renewal. The
 *    part paid is returned to the wallet rather than carried onto the new
 *    invoice: the platform has no idiom that moves a payment from one
 *    invoice to another, and the wallet pays the new one as it paid the old.
 *  - A paid renewal keeps its document, and the difference - what it took
 *    less what it would have billed - goes back to the wallet as a credit
 *    recorded against it, so WhatAnInvoiceStillHolds counts it and a card
 *    refund of the same invoice afterwards is held to what is left.
 *
 * The caller holds the locks, in the money-path order
 * (WhatAnInvoiceStillHolds): the open renewals before the subscription
 * (LockAnInvoiceWhileOpen, as the renewal and the wind-up take them), the
 * paid ones after it with the paid proration invoice, in ascending id order;
 * the wallet is taken here, after all of them. No provider is called.
 */
final readonly class RepriceTheRenewalsAReturnedChangeBilled
{
    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
        private WalletLedger $wallet,
        private IssueInvoice $issueInvoice,
        private PricingEngine $pricing,
        private CouponTermsRepository $coupons,
    ) {}

    /**
     * The renewals billed at the price the change moved the subscription to,
     * issued at or after the change, open or paid, in ascending id order. An
     * unlocked read: the caller locks them.
     *
     * @return list<string>
     */
    public static function renewalsToReprice(Subscription $subscription, PlanChange $change, int $billedAtMinor): array
    {
        return Invoice::query()
            ->where('subscription_id', $subscription->getKey())
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::Paid->value])
            ->where('created_at', '>=', $change->changed_at)
            ->whereHas('items', static fn ($items) => $items
                ->where('kind', InvoiceItemKind::Plan->value)
                ->where('unit_amount_minor', $billedAtMinor))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Subscription  $locked  the subscription, locked by the caller and already back on the old plan
     * @param  list<Invoice>  $renewals  the renewals, each locked by the caller
     * @return array{reissued: list<string>, withdrawn: list<string>, returned_minor: int}
     */
    public function execute(Subscription $locked, PlanChange $change, array $renewals, int $billedAtMinor): array
    {
        $reissued = [];
        $withdrawn = [];
        $returned = 0;

        /** @var Customer $customer */
        $customer = Customer::query()->findOrFail($locked->customer_id);

        foreach ($renewals as $renewal) {
            /** @var InvoiceItem|null $line */
            $line = InvoiceItem::query()
                ->where('invoice_id', $renewal->getKey())
                ->where('kind', InvoiceItemKind::Plan->value)
                ->where('unit_amount_minor', $billedAtMinor)
                ->orderBy('id')
                ->first();

            if ($line === null) {
                continue;
            }

            $pricingLine = new PricingLine(
                description: $line->description,
                quantity: $line->quantity,
                unitPrice: Money::ofMinor((int) $change->from_recurring_amount_minor, $renewal->currency),
                setupFee: Money::zero($renewal->currency),
            );

            [$fixed, $percentage, $code] = $this->discountOf($locked, $line, $renewal->currency);

            $priced = $this->pricing->price(
                lines: [$pricingLine],
                taxRate: $line->taxRate(),
                fixedDiscount: $fixed,
                percentageDiscount: $percentage,
                couponCode: $code,
            );

            if ($renewal->status === InvoiceStatus::Paid) {
                $returned += $this->returnTheDifference($renewal, $change, $priced->total->minorUnits());

                continue;
            }

            $returned += $this->returnWhatItHolds->andWithdraw(
                $renewal,
                'returned-plan-change-reprice',
                sprintf('Payment for invoice %s returned: it billed a plan change that was returned, and is issued again at the price of the plan the subscription is on', $renewal->number),
                sprintf('Billed at the price of a plan change that was returned (%s); issued again at the price of the plan the subscription went back to.', (string) $change->getKey()),
                ['subscription_id' => (string) $locked->getKey(), 'plan_change_id' => (string) $change->getKey()],
            );
            $withdrawn[] = (string) $renewal->getKey();

            $reissued[] = (string) $this->issueInvoice->execute(
                customer: $customer,
                lines: InvoiceLineDraft::zip(
                    $priced,
                    [$pricingLine],
                    InvoiceItemKind::Plan,
                    $line->period_start === null ? null : CarbonImmutable::instance($line->period_start),
                    $line->period_end === null ? null : CarbonImmutable::instance($line->period_end),
                    (string) $locked->getKey(),
                ),
                subscriptionId: (string) $locked->getKey(),
            )->getKey();
        }

        return ['reissued' => $reissued, 'withdrawn' => $withdrawn, 'returned_minor' => $returned];
    }

    /**
     * What a paid renewal took beyond what it would have billed, credited to
     * the wallet against it; zero when it took no more. Keyed on the
     * renewal and the change, so a second run posts nothing.
     */
    private function returnTheDifference(Invoice $renewal, PlanChange $change, int $shouldHaveBilledMinor): int
    {
        $difference = (int) $renewal->total_minor - $shouldHaveBilledMinor;

        if ($difference <= 0) {
            return 0;
        }

        /** @var Customer $customer */
        $customer = Customer::query()->findOrFail($renewal->customer_id);

        $entry = $this->wallet->credit(
            wallet: $this->wallet->walletFor($customer, $renewal->currency),
            amount: Money::ofMinor($difference, $renewal->currency),
            // Stored value handed over that no delivery claims, as every
            // return against an invoice is (ReturnWhatAnInvoiceStillHolds).
            kind: WalletTransactionKind::Topup,
            description: sprintf('Invoice %s repriced: it billed a plan change that was returned', $renewal->number),
            metadata: [
                'invoice_id' => (string) $renewal->getKey(),
                'plan_change_id' => (string) $change->getKey(),
                'billed_minor' => (int) $renewal->total_minor,
                'repriced_minor' => $shouldHaveBilledMinor,
            ],
            idempotencyKey: sprintf('invoice:%s:returned-plan-change-reprice:%s', $renewal->getKey(), $change->getKey()),
            invoiceId: (string) $renewal->getKey(),
        );

        return $entry->wasRecentlyCreated ? $entry->amount_minor : 0;
    }

    /**
     * The discount the renewal line carried, as the sweep would apply it at
     * the old price: none when it carried none; the subscription's coupon
     * terms when it has them; else the same fixed amount off.
     *
     * @return array{?Money, ?string, ?string}
     */
    private function discountOf(Subscription $subscription, InvoiceItem $line, string $currency): array
    {
        if ($line->discount_minor <= 0) {
            return [null, null, null];
        }

        $coupon = $this->coupons->find($subscription->coupon_id);

        if ($coupon !== null) {
            return [$coupon->fixedAmountIn($currency), $coupon->percentage, $coupon->code];
        }

        return [Money::ofMinor($line->discount_minor, $currency), null, null];
    }
}
