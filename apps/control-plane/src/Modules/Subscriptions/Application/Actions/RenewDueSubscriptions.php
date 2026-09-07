<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Application\DTOs\RenewalPlan;
use Lynomia\Modules\Subscriptions\Application\DTOs\RenewalSweep;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Throwable;

/**
 * The renewal coordinator: the thing that was missing.
 *
 * RenewSubscription advances one subscription's period and describes what to
 * bill for it; PricingEngine prices lines; IssueInvoice issues documents. All
 * three were written, documented and covered — and nothing called them. A
 * subscription therefore never renewed, never produced a second invoice, and
 * the platform's recurring revenue existed only in the tests, one of which
 * performed this handoff itself and read as proof that the platform did.
 *
 * ---------------------------------------------------------------------------
 * One subscription, one transaction
 * ---------------------------------------------------------------------------
 *
 * The period advance and the invoice for that period commit together. Splitting
 * them would mean a process killed between the two leaves a subscription whose
 * clock has moved and whose new month was never billed — and no later run can
 * detect it, because the period has already advanced and the sweep will find
 * nothing due. The customer would get that month free, quietly, and the
 * platform would never know.
 *
 * RenewSubscription takes the row lock; issuing inside it means the lock is held
 * for the duration of the invoice write, which is a few milliseconds and the
 * price of the guarantee.
 *
 * ---------------------------------------------------------------------------
 * One failure is not the run's failure
 * ---------------------------------------------------------------------------
 *
 * A subscription whose customer has been deleted, whose plan has been pulled,
 * or whose currency the tax resolver cannot answer for must not stop the
 * thousand behind it from renewing. Each is caught, counted and logged with its
 * id, and the sweep reports how many failed so an operator has a number to
 * chase rather than a silent shortfall.
 */
final readonly class RenewDueSubscriptions
{
    public function __construct(
        private RenewSubscription $renew,
        private IssueInvoice $issueInvoice,
        private PricingEngine $pricing,
        private TaxResolver $taxResolver,
    ) {}

    /**
     * @param  int  $limit  the most subscriptions to attempt in one run; the
     *                      sweep is scheduled often enough that a backlog is
     *                      worked through over several runs rather than in one
     *                      transaction-heavy hour.
     */
    public function execute(?DateTimeImmutable $at = null, int $limit = 500): RenewalSweep
    {
        $renewed = 0;
        $skipped = 0;
        $failed = 0;

        /** @var list<string> $invoices */
        $invoices = [];

        $due = Subscription::query()
            ->dueForRenewal($at)
            ->with('customer')
            ->orderBy('next_invoice_at')
            ->limit($limit)
            ->get();

        foreach ($due as $subscription) {
            try {
                $invoiceId = DB::transaction(
                    fn (): ?string => $this->renewOne($subscription, $at),
                );

                if ($invoiceId === null) {
                    // Another worker got there first, or the row stopped being
                    // due between the query and the lock. Converging silently
                    // is the correct outcome; it is counted so a run that
                    // renews nothing is distinguishable from one that found
                    // nothing.
                    $skipped++;

                    continue;
                }

                $renewed++;
                $invoices[] = $invoiceId;
            } catch (Throwable $e) {
                $failed++;

                Log::error('A subscription could not be renewed.', [
                    'subscription_id' => $subscription->getKey(),
                    'customer_id' => $subscription->customer_id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return new RenewalSweep(
            considered: $due->count(),
            renewed: $renewed,
            skipped: $skipped,
            failed: $failed,
            invoiceIds: $invoices,
        );
    }

    /**
     * @return string|null the id of the invoice issued, or null when there was
     *                     nothing to renew
     */
    private function renewOne(Subscription $subscription, ?DateTimeImmutable $at): ?string
    {
        $plan = $this->renew->execute($subscription, $at);

        if ($plan === null) {
            return null;
        }

        /** @var Customer $customer */
        $customer = $subscription->customer()->firstOrFail();

        return (string) $this->issue($plan, $customer)->getKey();
    }

    private function issue(RenewalPlan $plan, Customer $customer): Invoice
    {
        // The rate that applies now, not the one that applied when the
        // subscription started: a VAT change takes effect on the invoices
        // issued after it, which is what a tax authority expects to see.
        $taxRate = $this->taxResolver->forCustomer($customer, $plan->periodStart);

        $priced = $this->pricing->price(
            lines: $plan->pricingLines(),
            taxRate: $taxRate,
            fixedDiscount: $plan->fixedDiscount,
            percentageDiscount: $plan->percentageDiscount,
            couponCode: $plan->couponCode,
        );

        return $this->issueInvoice->execute(
            customer: $customer,
            lines: InvoiceLineDraft::zip(
                $priced,
                $plan->pricingLines(),
                InvoiceItemKind::Plan,
                $plan->periodStart,
                $plan->periodEnd,
                $plan->subscriptionId,
            ),
            subscriptionId: $plan->subscriptionId,
        );
    }
}
