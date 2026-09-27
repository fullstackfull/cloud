<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\DTOs\PlanChangeOutcome;
use Lynomia\Modules\Subscriptions\Application\DTOs\ProrationPlan;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Application\Listeners\RestorePlanOnVoidedUpgrade;
use Lynomia\Modules\Subscriptions\Application\Queries\MoneyCollectedForThePeriod;
use Lynomia\Modules\Subscriptions\Domain\Enums\PlanChangeRefusal;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\PlanChangeRefusedException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
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
 * all. What the listener builds is what the paid invoice bought - the shape
 * recorded on this change's {@see PlanChange} row - not whatever plan the
 * subscription has moved on to by the time the money arrives.
 *
 * The plan and recurring amount do move at once, and the record keeps the
 * amount they replaced. Until the invoice is paid a renewal bills that amount
 * ({@see RenewSubscription}), and if the invoice is voided the subscription is
 * put back on the plan it came from ({@see RestorePlanOnVoidedUpgrade}), so an
 * upgrade nobody paid for is never billed or credited as though it had been.
 *
 * ---------------------------------------------------------------------------
 * No money moves that was not collected
 * ---------------------------------------------------------------------------
 *
 * Two rules, because the plan a subscription is on says what it costs and not
 * what was paid for it. A change is refused while an invoice for the
 * subscription is open ({@see PlanChangeRefusal::InvoiceOutstanding}), and a
 * downgrade credit is held under the period's ceiling
 * ({@see MoneyCollectedForThePeriod}). Without them, small -> large -> small
 * three times over, paying nothing, left 162.000 KWD of spendable credit.
 *
 * ---------------------------------------------------------------------------
 * The quote is the gate
 * ---------------------------------------------------------------------------
 *
 * The same quote the customer was shown is recomputed here, under the
 * subscription's lock, and refused if it has stopped being available. A screen
 * renders what it was given seconds ago; between then and the confirmation a
 * service can be suspended, a plan can be withdrawn or sold out, or another
 * operation can start on the machine — and the disk-shrink refusal in
 * particular must not be defeated by a client that simply posts the plan id
 * without asking. The change is executed at the instant and the unit count
 * the quote priced; the request cannot name another count.
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
        private PlanCapacity $capacity,
        private MoneyCollectedForThePeriod $collected,
    ) {}

    /**
     * @throws PlanChangeRefusedException
     */
    public function execute(
        Subscription $subscription,
        Plan $plan,
        PlanPrice $price,
        ?string $idempotencyKey = null,
        ?User $actor = null,
    ): PlanChangeOutcome {
        /*
         * One transaction for the move and its money.
         *
         * ChangeSubscriptionPlan used to commit the move on its own, and the
         * invoice or the credit was written after it. An invoice write that
         * failed left the plan moved, the recurring amount raised, no invoice
         * and no audit entry - and a retry was refused as "same plan", so the
         * proration was lost for good (re-audit, F-01). Now the move, the
         * invoice or the credit, the plan-change record and the audit entry
         * commit together or not at all, and a failed attempt can simply be
         * made again. The resize job row is written inside it too; the job is
         * only dispatched once the transaction commits.
         */
        return DB::transaction(function () use ($subscription, $plan, $price, $idempotencyKey, $actor): PlanChangeOutcome {
            /*
             * The subscription row is the mutex for everything below: a second
             * change for the same subscription waits here, and then finds the
             * plan already moved, or the invoice this one left open.
             */
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

            /*
             * The order this subscription was bought on is a pool of money
             * its sibling subscriptions draw credit from too. Locked before
             * the quote reads it, so two siblings downgrading at once cannot
             * both draw the same pool.
             */
            $this->collected->lockTheOrdersBehind($locked);

            /*
             * And the invoices a downgrade credit would be drawn from, so it
             * is sized from, and recorded against, what they still hold under
             * the lock every other return of their money takes (O-2).
             */
            $this->collected->lockTheInvoicesItDrawsOn($locked);

            $quote = $this->quotes->execute($locked, $plan, $price);

            if (! $quote->isAvailable()) {
                /*
                 * Refused with its reasons, in the platform's own vocabulary,
                 * so the portal can say which of them applies. A generic 422
                 * here would leave a customer guessing between "not sold in
                 * your currency" and "that would destroy your disk".
                 */
                throw PlanChangeRefusedException::because($quote->refusals);
            }

            /*
             * A downgrade credits the wallet, and the wallet is locked before
             * the plan (the money-path lock order, WhatAnInvoiceStillHolds).
             * Claiming the unit locks the plan moved onto; taking the wallet
             * only when the credit was posted, after it, deadlocked against a
             * renewal lapsing a sibling subscription's upgrade, which holds
             * the wallet (returning what the upgrade held) and then locks the
             * plan it restores - the same plan, for a downgrade onto it (OA-1,
             * round four's re-audit, 40P01 4/4). An upgrade, which posts
             * nothing to the wallet, takes no wallet lock.
             */
            if ($quote->credit->isGreaterThan($quote->charge)) {
                /** @var Customer $owner */
                $owner = $locked->customer()->firstOrFail();
                $this->wallet->lockWalletFor($owner, $locked->currency);
            }

            $this->claimTheUnit($locked, $plan, $quote->units);

            $fromPlanId = $locked->plan_id;
            $fromRecurring = $locked->recurring_amount_minor;

            /*
             * Priced at the instant the quote used, and at the unit count the
             * subscription holds - the count the quote priced, never one the
             * client names.
             */
            $proration = $this->changePlan->execute(
                subscription: $locked,
                newPlan: $plan,
                newPrice: $price,
                changeAt: $quote->effectiveAt,
            );

            /*
             * The money, made durable, before anything is handed over.
             *
             * An upgrade is invoiced and the machine is left alone until that
             * invoice settles; a downgrade credits the wallet and goes through
             * at once.
             */
            /*
             * The change's own id, taken before anything is written, so the
             * wallet credit can be keyed on it. Keying the ledger entry on the
             * subscription, the plan left and the second of the change let
             * two different downgrades off the same plan inside one
             * wall-clock second share a key: the ledger replayed the first
             * and posted nothing for the second, while the response, this
             * record and the period's ceiling all counted it as paid out.
             */
            $changeId = (string) Str::ulid();

            [$invoice, $walletCredit] = $this->settleTheDifference($locked, $changeId, $proration, $actor);

            (new PlanChange)->forceFill([
                'id' => $changeId,
                'subscription_id' => (string) $locked->getKey(),
                'from_plan_id' => $fromPlanId,
                'to_plan_id' => (string) $plan->getKey(),
                'currency' => $proration->currency,
                'units' => $quote->units,
                'credit_minor' => $proration->credit->minorUnits(),
                'charge_minor' => $proration->charge->minorUnits(),
                'wallet_credit_minor' => $walletCredit,
                'from_recurring_amount_minor' => $fromRecurring,
                'proration_invoice_id' => $invoice === null ? null : (string) $invoice->getKey(),
                'resources' => $quote->newResources->toArray(),
                'changed_by_user_id' => $actor === null ? null : (string) $actor->getKey(),
                'changed_at' => $proration->changeAt,
            ])->save();

            $resizeJob = $quote->changesInfrastructure && ! $proration->net()->isPositive()
                ? $this->queueAtProvider->execute($locked, $quote->planId, $quote->newResources, $idempotencyKey)
                : null;

            $this->audit->execute(
                action: AuditAction::PlanChanged,
                subject: $locked,
                customerId: $locked->customer_id,
                context: [
                    'from_plan_id' => $fromPlanId,
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
        });
    }

    /**
     * Take a unit of the plan being moved onto, under the rules a checkout
     * obeys (F-06), or refuse.
     *
     * The quote already read the counts, as a courtesy. This is the claim: the
     * plan row is locked through PlanCapacity::lock(), the lock every checkout
     * takes, and the counts are read again under it. The unit this
     * subscription holds on the plan it is leaving is given back by the same
     * write that moves it, because capacity counts a unit against the plan
     * its subscription is on.
     *
     * @throws PlanChangeRefusedException
     */
    private function claimTheUnit(Subscription $subscription, Plan $plan, int $units): void
    {
        $this->capacity->lock([(string) $plan->getKey()]);

        /** @var Customer $customer */
        $customer = $subscription->customer()->firstOrFail();

        /** @var Plan $fresh */
        $fresh = Plan::query()->findOrFail($plan->getKey());

        $refused = match ($this->capacity->shortfall($fresh, $units, $customer)) {
            PlanCapacity::OUT_OF_STOCK => PlanChangeRefusal::OutOfStock,
            PlanCapacity::PER_CUSTOMER_LIMIT => PlanChangeRefusal::PerCustomerLimit,
            default => null,
        };

        if ($refused !== null) {
            throw PlanChangeRefusedException::because([$refused]);
        }
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
    /**
     * @return array{0: ?Invoice, 1: int} the invoice an upgrade left, and the
     *                                    wallet credit a downgrade posted, in minor units
     */
    private function settleTheDifference(
        Subscription $subscription,
        string $changeId,
        ProrationPlan $proration,
        ?User $actor,
    ): array {
        $net = $proration->net();

        if ($net->isZero()) {
            return [null, 0];
        }

        /** @var Customer $customer */
        $customer = $subscription->customer()->firstOrFail();

        if ($net->isNegative()) {
            return [null, $this->creditTheCustomer($customer, $subscription, $changeId, $proration, $actor)];
        }

        return [$this->invoiceTheDifference($customer, $subscription, $proration), 0];
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
     * The amount is the proration's net, whose credit half ChangeSubscriptionPlan
     * has already held under the period's ceiling: never more than the period
     * collected, less what earlier changes returned. The amount posted is
     * returned so the change's record can carry it, which is what the next
     * change's ceiling subtracts.
     *
     * Keyed on the id of this change's record, which is unique per change, so
     * two different changes can never share a key. A change that fails rolls
     * back whole, ledger entry included, and whatever retries it is a new
     * change with a new id. A retry of a change that succeeded - even under
     * the same Idempotency-Key - finds the subscription already on the plan
     * and is refused (409, `same_plan`); nothing replays the first response. The
     * earlier key (subscription, plan left, second of the change) collided for
     * two downgrades off the same plan within one second, and the second
     * credit was silently replayed as the first.
     *
     * Recorded against the invoices whose money it is
     * (MoneyCollectedForThePeriod::drawnFrom()): one entry per invoice drawn
     * on, each carrying that invoice's id, which is what WhatAnInvoiceStillHolds
     * reads - so a card refund of the same invoice afterwards is held to what
     * is left (O-2). It used to be one entry against no invoice, and a card
     * refund of the whole period after a downgrade returned the credit twice.
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
        string $changeId,
        ProrationPlan $proration,
        ?User $actor,
    ): int {
        $amount = $proration->net()->absolute();
        $wallet = $this->wallet->walletFor($customer, $proration->currency);

        $posted = 0;
        $part = 0;

        /*
         * One entry per invoice the credit draws on, carrying its id, so the
         * money is recorded against the invoice it came from and every later
         * return of that invoice's money sees it (O-2). The first entry keeps
         * the change's own key; any further one is numbered after it.
         */
        foreach ($this->collected->drawnFrom($subscription, $amount->minorUnits()) as $invoiceId => $minor) {
            $entry = $this->wallet->credit(
                wallet: $wallet,
                amount: Money::ofMinor($minor, $proration->currency),
                kind: WalletTransactionKind::Adjustment,
                description: 'Unused time after moving plan',
                actor: $actor,
                metadata: [
                    'subscription_id' => (string) $subscription->getKey(),
                    'plan_change_id' => $changeId,
                    'credit_minor' => $proration->credit->minorUnits(),
                    'charge_minor' => $proration->charge->minorUnits(),
                ],
                idempotencyKey: 'plan-change-credit:'.$changeId.($part === 0 ? '' : ':'.$part),
                invoiceId: $invoiceId === '' ? null : (string) $invoiceId,
            );

            $part++;

            /*
             * What the ledger actually posted for this change. A replayed
             * entry was posted by something else and is not this change's
             * money; it is recorded as nothing rather than counted twice.
             */
            $posted += $entry->wasRecentlyCreated ? $entry->amount_minor : 0;
        }

        return $posted;
    }
}
