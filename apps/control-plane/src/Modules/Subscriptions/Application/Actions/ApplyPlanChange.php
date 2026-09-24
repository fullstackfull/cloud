<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Subscriptions\Application\DTOs\PlanChangeOutcome;
use Lynomia\Modules\Subscriptions\Application\DTOs\ProrationPlan;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\PlanChangeRefusedException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Vps\Application\Handlers\ResizeVpsHandler;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;

/**
 * Move a subscription onto another plan, and make the machine match.
 *
 * ---------------------------------------------------------------------------
 * Billing success is not plan-change completion
 * ---------------------------------------------------------------------------
 *
 * The money half of a plan change is immediate and reversible-by-arithmetic:
 * credit the unused remainder, charge the same remainder at the new price. The
 * infrastructure half is neither. A machine grows when a hypervisor says it
 * has, minutes later, and it can fail.
 *
 * So this action does the money first and says what it did. What it
 * deliberately does not do is write the new shape onto the service as though
 * the machine already had it: the customer's dashboard would show four vCPU on
 * a machine running two, and the platform's own capacity accounting would
 * believe a node had handed out memory it has not.
 * {@see ResizeVpsHandler} writes the
 * shape, after the provider confirms it.
 *
 * ---------------------------------------------------------------------------
 * Nothing is handed over against an open invoice
 * ---------------------------------------------------------------------------
 *
 * An upgrade is a purchase, and the platform's rule everywhere else is that a
 * purchase is delivered on settlement. This action therefore queues the resize
 * only when the change owes nothing — a downgrade, or a move between equally
 * priced plans. An upgrade leaves an invoice behind and the resize is queued
 * by {@see ResizeOnPlanChangeSettlement}
 * when that invoice is paid. Before this, the bigger machine was handed over
 * at the moment confirm was pressed and the difference was never collected at
 * all.
 *
 * ---------------------------------------------------------------------------
 * The quote is the gate
 * ---------------------------------------------------------------------------
 *
 * The same quote the customer was shown is recomputed here and refused if it
 * has stopped being available. A screen renders what it was given seconds ago;
 * between then and the confirmation a service can be suspended, a plan can be
 * withdrawn, or another operation can start on the machine — and the
 * disk-shrink refusal in particular must not be defeated by a client that
 * simply posts the plan id without asking.
 */
final readonly class ApplyPlanChange
{
    public function __construct(
        private QuotePlanChange $quotes,
        private ChangeSubscriptionPlan $changePlan,
        private QueuePlanChangeAtProvider $queueAtProvider,
        private RecordAuditEntry $audit,
        private IssueInvoice $issueInvoice,
        private PricingEngine $pricing,
        private TaxResolver $taxResolver,
        private WalletLedger $wallet,
    ) {}

    /**
     * @throws PlanChangeRefusedException
     */
    public function execute(
        Subscription $subscription,
        Plan $plan,
        PlanPrice $price,
        ?int $units = null,
        ?string $idempotencyKey = null,
        ?User $actor = null,
    ): PlanChangeOutcome {
        $quote = $this->quotes->execute($subscription, $plan, $price);

        if (! $quote->isAvailable()) {
            /*
             * Refused with its reasons, in the platform's own vocabulary, so
             * the portal can say which of them applies. A generic 422 here
             * would leave a customer guessing between "not sold in your
             * currency" and "that would destroy your disk".
             */
            throw PlanChangeRefusedException::because($quote->refusals);
        }

        $proration = $this->changePlan->execute(
            subscription: $subscription,
            newPlan: $plan,
            newPrice: $price,
            units: $units,
        );

        /*
         * The money, made durable, before anything is handed over.
         *
         * An upgrade is invoiced and the machine is left alone until that
         * invoice settles; a downgrade credits the wallet and goes through at
         * once. Both halves used to be computed and dropped on the floor.
         */
        $invoice = $this->settleTheDifference($subscription, $proration, $actor);

        $resizeJob = $quote->changesInfrastructure && ! $proration->net()->isPositive()
            ? $this->queueAtProvider->execute($subscription, $quote->planId, $quote->newResources, $idempotencyKey)
            : null;

        $this->audit->execute(
            action: AuditAction::PlanChanged,
            subject: $subscription,
            customerId: $subscription->customer_id,
            context: [
                'from_plan_id' => $quote->planId === (string) $subscription->plan_id ? null : (string) $subscription->plan_id,
                'to_plan_id' => (string) $plan->getKey(),
                'amount_due_now_minor' => $quote->amountDueNow->minorUnits(),
                'currency' => $quote->currentRecurring->currency(),
                'resize_job_id' => $resizeJob === null ? null : (string) $resizeJob->getKey(),
                'proration_invoice_id' => $invoice === null ? null : (string) $invoice->getKey(),
            ],
        );

        return new PlanChangeOutcome(
            proration: $proration,
            quote: $quote,
            resizeJob: $resizeJob,
            invoice: $invoice,
        );
    }

    /**
     * Turn the proration into something that survives the request.
     *
     * Everything below reuses primitives that already existed: the same
     * IssueInvoice a renewal uses, the same PricingEngine, the same
     * TaxResolver, and the wallet ledger's own idempotent credit. Nothing new
     * was modelled, because nothing new was missing — only the wiring.
     *
     * Which of the two happens is decided by the sign of the net, and the two
     * are deliberately not symmetrical:
     *
     *  - **Owed to us** becomes an invoice, carrying both halves as separate
     *    lines so the document says what was taken off and what was charged.
     *    The customer is not given the larger machine until it settles; that
     *    is the same rule an order follows, where nothing is provisioned
     *    against an open invoice.
     *  - **Owed to them** becomes wallet credit, not a refund to the card.
     *    This domain already separates the two — WalletLedger for balance a
     *    later invoice can consume, IssueRefund for money returned through the
     *    payment provider — and a downgrade is not a request for money back.
     *    Choosing the refund here would be inventing a policy nobody set.
     *  - **Exactly nothing** writes neither. A change between two equally
     *    priced plans is a real change, and billing it would be an invention.
     */
    private function settleTheDifference(
        Subscription $subscription,
        ProrationPlan $proration,
        ?User $actor,
    ): ?Invoice {
        $net = $proration->net();

        if ($net->isZero()) {
            return null;
        }

        /** @var Customer $customer */
        $customer = $subscription->customer()->firstOrFail();

        if ($net->isNegative()) {
            $this->creditTheCustomer($customer, $subscription, $proration, $actor);

            return null;
        }

        return $this->invoiceTheDifference($customer, $subscription, $proration);
    }

    private function invoiceTheDifference(
        Customer $customer,
        Subscription $subscription,
        ProrationPlan $proration,
    ): Invoice {
        // The rate that applies at the moment of the change, as a renewal does
        // it: a VAT change takes effect on the documents issued after it.
        $taxRate = $this->taxResolver->forCustomer($customer, $proration->changeAt);

        $lines = $proration->pricingLines();
        $priced = $this->pricing->price(lines: $lines, taxRate: $taxRate);

        /*
         * Zipped by hand rather than through InvoiceLineDraft::zip(), which
         * fixes one kind for every line. A proration carries two different
         * kinds — what came off the old plan and what went on the new one —
         * and collapsing them would make the invoice unreadable.
         */
        $drafts = [];
        foreach ($proration->lines as $index => $line) {
            $drafts[] = InvoiceLineDraft::fromPricing(
                $lines[$index],
                $priced->lines[$index],
                $line->kind,
                $proration->changeAt,
                $proration->periodEnd,
                $proration->subscriptionId,
            );
        }

        return $this->issueInvoice->execute(
            customer: $customer,
            lines: $drafts,
            subscriptionId: (string) $subscription->getKey(),
        );
    }

    /**
     * The unused remainder of the plan they left, returned as balance.
     *
     * Keyed on the subscription, the plan and the instant of the change, so a
     * retried request credits once. The ledger refuses a second entry under a
     * key it has already posted.
     *
     * Posted as an Adjustment, which the ledger will not accept without a
     * named user behind it. That rule is right and is honoured rather than
     * worked around: the entry is the consequence of a person choosing a
     * smaller plan, and that person is who it is attributed to. The two
     * neighbouring kinds were both rejected. Refund means money returned
     * through the channel it arrived by, against a transaction that was
     * actually reversed — there is none here, and claiming one would overstate
     * what the platform has paid out. Topup means value the customer handed
     * over, which they did not.
     */
    private function creditTheCustomer(
        Customer $customer,
        Subscription $subscription,
        ProrationPlan $proration,
        ?User $actor,
    ): void {
        $amount = $proration->net()->absolute();
        $wallet = $this->wallet->walletFor($customer, $proration->currency);

        $this->wallet->credit(
            wallet: $wallet,
            amount: $amount,
            kind: WalletTransactionKind::Adjustment,
            description: 'Unused time after moving plan',
            actor: $actor,
            metadata: [
                'subscription_id' => (string) $subscription->getKey(),
                'credit_minor' => $proration->credit->minorUnits(),
                'charge_minor' => $proration->charge->minorUnits(),
            ],
            idempotencyKey: sprintf(
                'plan-change-credit:%s:%s:%s',
                $subscription->getKey(),
                $subscription->plan_id,
                $proration->changeAt->getTimestamp(),
            ),
        );
    }
}
