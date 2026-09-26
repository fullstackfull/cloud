<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\DTOs\PlanChangeQuote;
use Lynomia\Modules\Subscriptions\Application\Queries\MoneyCollectedForThePeriod;
use Lynomia\Modules\Subscriptions\Application\Queries\UnpaidUpgrade;
use Lynomia\Modules\Subscriptions\Domain\Enums\PlanChangeRefusal;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\PlanResources;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * What a plan change would cost and change, before anybody commits to it.
 *
 * ---------------------------------------------------------------------------
 * Why the quote is computed here and not in the portal
 * ---------------------------------------------------------------------------
 *
 * Every figure a customer sees on the change-plan screen comes from this
 * action, through the same {@see PricingEngine::prorate()} call that
 * {@see ChangeSubscriptionPlan} makes when the change is applied, against the
 * same period boundaries and the same instant. The quote and the invoice are
 * therefore not two calculations that ought to agree — they are one
 * calculation performed twice. The credit on both sides is also held under
 * the same ceiling, {@see MoneyCollectedForThePeriod::ceilCredit()}: a
 * downgrade gives back no more than the period collected.
 *
 * A portal that computed proration itself would be a second implementation of
 * the platform's money rules, in a language with one numeric type, running on
 * a device whose clock the customer sets. It would agree with the backend
 * until a leap second, a rounding rule, or a currency with three decimal
 * places — and the first customer to notice would be one who was charged
 * something other than what the screen said.
 *
 * ---------------------------------------------------------------------------
 * Refusals travel with the quote
 * ---------------------------------------------------------------------------
 *
 * A plan the platform would not move to comes back with its reasons and
 * without its numbers. Showing a price for something that will be refused is
 * how a customer decides to buy and then meets an error; showing the reason is
 * how they decide to do something else.
 */
final readonly class QuotePlanChange
{
    public function __construct(
        private PricingEngine $pricing,
        private PlanCapacity $capacity,
        private MoneyCollectedForThePeriod $collected,
        private UnpaidUpgrade $unpaid,
    ) {}

    /**
     * Quote one target plan.
     */
    public function execute(
        Subscription $subscription,
        Plan $plan,
        PlanPrice $price,
        ?DateTimeImmutable $changeAt = null,
    ): PlanChangeQuote {
        $now = $changeAt !== null ? CarbonImmutable::instance($changeAt) : CarbonImmutable::now();

        $service = $this->serviceFor($subscription);

        $current = $this->currentResources($subscription, $service);
        $target = PlanResources::fromArray($plan->resources);

        $knownUnits = $this->unitsOn($subscription);
        // Priced at one unit only for display; a count that cannot be derived
        // is refused below, so nothing is ever executed at this figure.
        $units = $knownUnits ?? 1;

        $refusals = $this->refusals($subscription, $plan, $price, $current, $target, $service, $units);

        if ($knownUnits === null) {
            /*
             * The subscription bills a figure that is not a whole multiple of
             * its plan's price (a grandfathered price), so how many units it
             * holds cannot be derived. ChangeSubscriptionPlan refuses to guess,
             * and the request cannot name a count; the options screen used to
             * offer the change anyway and the confirmation then failed with an
             * unrelated error. Refused here, so both say the same thing.
             */
            $refusals[] = PlanChangeRefusal::UnitCountUnknown;
        }

        $newRecurring = $price->recurring()->multipliedBy($units);

        /*
         * Computed even for a refused plan, and then not published. The
         * arithmetic is cheap and doing it unconditionally keeps one code
         * path; what stops a refused price reaching the customer is the
         * resource, not a branch here that could be got wrong later.
         */
        $credit = $refusals === []
            ? $this->pricing->prorate(
                // What was paid for; see ChangeSubscriptionPlan.
                $this->unpaid->recurringPaidFor($subscription),
                $subscription->current_period_start,
                $subscription->current_period_end,
                $now,
            )
            : Money::zero($subscription->currency);

        $charge = $refusals === []
            ? $this->pricing->prorate(
                $newRecurring,
                $subscription->current_period_start,
                $subscription->current_period_end,
                $now,
            )
            : Money::zero($subscription->currency);

        /*
         * The same ceiling ChangeSubscriptionPlan applies, so the credit the
         * customer is shown is the credit they will get: never more than the
         * period collected, less what earlier changes already gave back.
         */
        $credit = $this->collected->ceilCredit(
            $this->collected->pricedAsThePeriodWas($credit, $subscription),
            $charge,
            $subscription,
        );

        return new PlanChangeQuote(
            planId: (string) $plan->getKey(),
            priceId: (string) $price->getKey(),
            planName: $plan->nameFor(app()->getLocale()),
            currentRecurring: $subscription->recurringAmount(),
            newRecurring: $newRecurring,
            credit: $credit,
            charge: $charge,
            /*
             * What the customer actually owes today: the remainder at the new
             * plan, less the remainder they already paid for at the old one.
             * Negative on a downgrade, which the resource publishes as such
             * rather than hiding — a credit is money the customer is owed and
             * they should see it.
             */
            amountDueNow: $charge->minus($credit),
            effectiveAt: $now,
            periodEnd: CarbonImmutable::instance($subscription->current_period_end),
            currentResources: $current,
            newResources: $target,
            changesInfrastructure: $current->differsFrom($target),
            refusals: $refusals,
            warnings: $this->warnings($current, $target),
            units: $units,
        );
    }

    /**
     * Every plan this subscription could be quoted for, refused ones included.
     *
     * The refused ones are returned deliberately: a screen that silently
     * omitted the plan a customer is looking for tells them nothing, and the
     * commonest reason to look is precisely the one the platform refuses —
     * a smaller disk.
     *
     * @return list<PlanChangeQuote>
     */
    public function options(Subscription $subscription, ?DateTimeImmutable $changeAt = null): array
    {
        $currentPlan = $subscription->plan;

        if ($currentPlan === null) {
            return [];
        }

        /** @var list<Plan> $plans */
        $plans = Plan::query()
            // Same product only. A VPS plan and a hosting plan are not
            // alternatives to one another, and offering the swap would be
            // offering to replace one service with a different one.
            ->where('product_id', $currentPlan->product_id)
            ->where('is_active', true)
            ->where('is_public', true)
            ->with('prices')
            ->orderBy('sort_order')
            ->get()
            ->all();

        $quotes = [];

        foreach ($plans as $plan) {
            $price = $plan->prices
                ->firstWhere(fn (PlanPrice $candidate): bool => $candidate->currency === $subscription->currency
                    && $candidate->billing_period === $subscription->billing_period);

            if ($price === null) {
                // Not sold in this customer's currency on this cycle. Left out
                // rather than refused: there is nothing to show a price for.
                continue;
            }

            $quotes[] = $this->execute($subscription, $plan, $price, $changeAt);
        }

        return $quotes;
    }

    /**
     * @return list<PlanChangeRefusal>
     */
    private function refusals(
        Subscription $subscription,
        Plan $plan,
        PlanPrice $price,
        PlanResources $current,
        PlanResources $target,
        ?Service $service,
        int $units,
    ): array {
        $refusals = [];

        if (! $subscription->status->serviceShouldRun()) {
            $refusals[] = PlanChangeRefusal::SubscriptionNotRunning;
        }

        if ($plan->getKey() === $subscription->plan_id) {
            $refusals[] = PlanChangeRefusal::SamePlan;
        }

        if (! $plan->is_active || ! $plan->is_public) {
            $refusals[] = PlanChangeRefusal::NotAvailable;
        }

        if ($subscription->plan !== null && $plan->product_id !== $subscription->plan->product_id) {
            $refusals[] = PlanChangeRefusal::DifferentProduct;
        }

        if ((string) $price->plan_id !== (string) $plan->getKey()) {
            /*
             * The plan and the price both come from the client, and until this
             * check existed they were validated only for existence. A request
             * naming the largest plan and the smallest plan's price passed
             * every other rule — same product, same currency, same period —
             * and moved the subscription onto the large plan at the small
             * price. The catalogue is the authority for what a plan costs; a
             * price belonging to another plan is not a discount, it is a
             * mismatch.
             */
            $refusals[] = PlanChangeRefusal::PriceNotForPlan;
        }

        if ($service !== null && $service->status !== ServiceStatus::Active) {
            /*
             * A suspended customer can still be sold an upgrade otherwise: the
             * money moves on confirmation and the resize is queued against a
             * machine the platform has deliberately locked at the hypervisor,
             * which then refuses it. The customer is charged for a plan they
             * cannot be given.
             */
            $refusals[] = PlanChangeRefusal::ServiceNotActive;
        }

        if ($price->currency !== $subscription->currency) {
            $refusals[] = PlanChangeRefusal::DifferentCurrency;
        }

        if ($price->billing_period !== $subscription->billing_period) {
            $refusals[] = PlanChangeRefusal::DifferentBillingPeriod;
        }

        if ($current->wouldShrinkDiskOf($target)) {
            /*
             * The refusal this whole class exists to make early. Shrinking a
             * disk truncates a filesystem: the bytes past the new end are
             * gone, and there is no provider workflow here that makes that
             * safe. A customer who wants a smaller plan can build a smaller
             * server and move to it, which is slower and does not destroy
             * anything.
             */
            $refusals[] = PlanChangeRefusal::WouldShrinkDisk;
        }

        if ($service !== null && $this->serviceIsBusy($service)) {
            $refusals[] = PlanChangeRefusal::ServiceBusy;
        }

        if ($this->hasAnOpenInvoice($subscription)) {
            /*
             * The plan this subscription is on is not paid for yet - most
             * often because the last upgrade's invoice is open. A move priced
             * from it would price money that has not arrived: the re-audit
             * flapped small -> large -> small, paid nothing and was credited
             * 162.000 KWD. Pay, or have it voided, and the change is available.
             */
            $refusals[] = PlanChangeRefusal::InvoiceOutstanding;
        }

        if ($plan->getKey() !== $subscription->plan_id) {
            /** @var Customer|null $customer */
            $customer = $subscription->customer()->first();

            /*
             * The courtesy read. ApplyPlanChange asks the same question again
             * under the plan row's lock, inside the transaction that moves the
             * subscription, which is the answer that actually holds.
             */
            $shortfall = $customer === null ? null : $this->capacity->shortfall($plan, $units, $customer);

            if ($shortfall === PlanCapacity::OUT_OF_STOCK) {
                $refusals[] = PlanChangeRefusal::OutOfStock;
            } elseif ($shortfall === PlanCapacity::PER_CUSTOMER_LIMIT) {
                $refusals[] = PlanChangeRefusal::PerCustomerLimit;
            }
        }

        return array_values(array_unique($refusals, SORT_REGULAR));
    }

    /**
     * @return list<string>
     */
    private function warnings(PlanResources $current, PlanResources $target): array
    {
        $warnings = [];

        if ($current->differsFrom($target)) {
            // Said plainly because the customer's server will restart or
            // pause: a resize is not a billing change that happens quietly.
            $warnings[] = 'resize_required';
        }

        if ($current->vcpu !== null && $target->vcpu !== null && $target->vcpu < $current->vcpu) {
            $warnings[] = 'fewer_vcpu';
        }

        if ($current->memoryMib !== null && $target->memoryMib !== null && $target->memoryMib < $current->memoryMib) {
            // Memory that shrinks under a running workload is an out-of-memory
            // kill, not a smaller invoice.
            $warnings[] = 'less_memory';
        }

        return $warnings;
    }

    /**
     * What the customer is actually running, which is not always what their
     * plan says.
     *
     * The service's own recorded allocation wins where it has one: a machine
     * that was resized by an operator, or built before the plan was edited, is
     * the thing a disk-shrink check has to compare against. Falling back to
     * the plan would let a downgrade past that truncates a disk the plan does
     * not know about.
     */
    private function currentResources(Subscription $subscription, ?Service $service): PlanResources
    {
        $fromService = $service === null ? new PlanResources : PlanResources::fromArray($service->resources);

        if ($fromService->vcpu !== null || $fromService->memoryMib !== null || $fromService->diskGib !== null) {
            return $fromService;
        }

        return $subscription->plan === null
            ? new PlanResources
            : PlanResources::fromArray($subscription->plan->resources);
    }

    private function serviceFor(Subscription $subscription): ?Service
    {
        return Service::query()->where('subscription_id', $subscription->getKey())->first();
    }

    /**
     * Whether the platform is already doing something to this service.
     *
     * A plan change queues a resize, and a resize landing on a machine that is
     * mid-rebuild is two destructive operations racing on one disk.
     */
    private function serviceIsBusy(Service $service): bool
    {
        return ProvisioningJob::query()
            ->where('service_id', $service->getKey())
            ->whereIn('status', [
                ProvisioningJobStatus::Queued->value,
                ProvisioningJobStatus::Running->value,
                ProvisioningJobStatus::NeedsReview->value,
            ])
            ->exists();
    }

    /**
     * Whether an invoice issued against this subscription is still waiting
     * to be paid.
     */
    private function hasAnOpenInvoice(Subscription $subscription): bool
    {
        $collectible = array_values(array_map(
            static fn (InvoiceStatus $status): string => $status->value,
            array_filter(InvoiceStatus::cases(), static fn (InvoiceStatus $status): bool => $status->isCollectible()),
        ));

        return Invoice::query()
            ->where('subscription_id', $subscription->getKey())
            ->whereIn('status', $collectible)
            ->exists();
    }

    /**
     * How many units of its plan a subscription pays for.
     *
     * The same division ChangeSubscriptionPlan makes, and for the same reason:
     * a three-server subscription quoted at one unit of the new plan would
     * show the customer a third of what they are about to be charged.
     */
    private function unitsOn(Subscription $subscription): ?int
    {
        if ($subscription->recurring_amount_minor === 0) {
            return 1;
        }

        $unit = PlanPrice::query()
            ->where('plan_id', $subscription->plan_id)
            ->where('currency', $subscription->currency)
            ->where('billing_period', $subscription->billing_period->value)
            ->value('recurring_amount_minor');

        $unit = is_numeric($unit) ? (int) $unit : 0;

        return $unit > 0 && $subscription->recurring_amount_minor % $unit === 0
            ? intdiv($subscription->recurring_amount_minor, $unit)
            : null;
    }
}
