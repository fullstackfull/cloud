<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Queries;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Queries\HostingPackageForPlan;
use Lynomia\Modules\Subscriptions\Application\Actions\QueuePlanChangeAtProvider;
use Lynomia\Modules\Subscriptions\Application\Actions\QuotePlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAnUpgradeTheEndPrevented;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\PlanResources;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Vps\Application\Services\MachineCommitment;

/**
 * Whether a plan change can be delivered, and whether one has been.
 *
 * ---------------------------------------------------------------------------
 * Can it be delivered (F-07, on the plan-change path)
 * ---------------------------------------------------------------------------
 *
 * Checkout refuses a plan it cannot deliver before any money moves
 * ({@see LocalPlacementFeasibility}). A plan change did not ask: an operator
 * who put a hosting plan and its price on sale before mapping its package (the
 * documented build order), or who withdrew the package behind a plan still on
 * sale, left a plan the options screen offered with no refusal. The upgrade
 * was accepted, its invoice paid, the subscription moved and billed at the new
 * price for ever, and nothing was queued at the panel — money for nothing
 * (the re-audit after round five).
 *
 * {@see refusal()} is asked by {@see QuotePlanChange} (so the options screen
 * and the change refuse the same plan) and, for a change already accepted, by
 * the payment of its proration invoice ({@see refusalForTheInvoice()}), as an
 * order is asked again when it is paid, and by the settlement of that
 * payment ({@see refusalForTheChange()}), which returns a change it refuses.
 * What it asks, per product, is the following - part of what
 * {@see QueuePlanChangeAtProvider} needs to queue the change, and for a VPS
 * the capacity question the resize itself asks after it is queued. A hosting
 * service with no account yet is asked all the same, although nothing is
 * queued on it: the plan it moves onto is the plan its account will be built
 * on. (This used to say such a service was not asked; it always was - the
 * re-audit after round six.) A change of shape is measured from what the
 * service runs now (whatTheServiceRuns()), the shape the resize reads:
 *
 *  - Shared Hosting: the target plan resolves to exactly one package on sale
 *    ({@see HostingPackageForPlan}) - the answer checkout's placement is given
 *    for the same plan, and the package the change is queued onto. The
 *    fleet's room, which checkout also asks, is not: a plan change moves the
 *    existing account onto another package on its own node and places
 *    nothing new.
 *  - VPS, when the shape changes: the service has a machine to resize, and
 *    its node and pool can hold its growth - the refusal the resize itself
 *    makes before it grows the machine, asked as a dry run
 *    (growthTheNodeCannotHold()). Growth is measured as the resize measures
 *    it, from the machine as the hypervisor reports it (a target no larger
 *    than that in any dimension is not asked, and only what the target adds
 *    above what the machine runs is), and, when the hypervisor cannot be
 *    read, asked as if the machine ran nothing beyond what its commitment
 *    holds - the stricter answer, so a quote that passes is a change the
 *    resize does not refuse for capacity (MachineCommitment::
 *    whyTheGrowthWouldNotFit()). The hypervisor is read before any lock
 *    (whatTheMachineRuns()), never under one. The target plan's
 *    placement (cluster, address pool, image, a node in
 *    service), which checkout asks before building a new machine, is not
 *    asked: a resize is applied to the machine where it already runs and
 *    reads none of it, and refusing on it would refuse a change that can be
 *    delivered.
 *  - Any other product, when the shape changes: refused. Nothing queues a
 *    change of shape for it (a dedicated server is not resized), so a paid
 *    change of shape would deliver nothing.
 *
 * A subscription with no service has nothing at a provider to change, and is
 * not refused here.
 *
 * ---------------------------------------------------------------------------
 * Has it been delivered
 * ---------------------------------------------------------------------------
 *
 * {@see wasDelivered()} and {@see aLaterChangeWasSettled()} are what the end
 * of a subscription reads ({@see ReturnAnUpgradeTheEndPrevented}) and what
 * the settlement reads ({@see ResizeOnPlanChangeSettlement}), one answer for
 * both.
 */
final readonly class PlanChangeDelivery
{
    /** The least lookBackFrom() reads behind the current period, in days. */
    public const int LOOK_BACK_DAYS = 7;

    public function __construct(
        private HostingPackageForPlan $packages,
        private MachineCommitment $commitment,
    ) {}

    /**
     * Why the change of this subscription onto this plan cannot be delivered,
     * in an operator's words; null when it can, or when there is nothing to
     * deliver.
     *
     * @param  PlanResources  $current  what the service runs now, as QuotePlanChange reads it
     * @param  VmResources|null  $runs  the VPS machine as the hypervisor reported it, read before any lock
     *                                  (whatTheMachineRuns()); null asks the stricter question
     */
    public function refusal(Subscription $subscription, Plan $plan, PlanResources $current, ?VmResources $runs = null): ?string
    {
        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();

        if ($service === null) {
            return null;
        }

        if ($service->kind === ProductKind::SharedHosting->value) {
            $choice = $this->packages->resolve((string) $plan->getKey());

            return $choice->package === null ? $choice->reason : null;
        }

        if (! $current->differsFrom(PlanResources::fromArray($plan->resources ?? []))) {
            return null;
        }

        if ($service->kind === ProductKind::Vps->value) {
            /** @var VirtualMachine|null $machine */
            $machine = VirtualMachine::query()->where('service_id', $service->getKey())->first();

            return $machine === null
                ? 'the service has no virtual machine to resize'
                : $this->growthTheNodeCannotHold($machine, PlanResources::fromArray($plan->resources ?? []), $runs);
        }

        return 'nothing can change the shape of this product in place';
    }

    /**
     * Why the machine's node or pool cannot hold its growth to the target
     * shape; null when they can, or when the machine does not grow.
     *
     * The resize's own refusal, asked as a dry run
     * (MachineCommitment::whyTheGrowthWouldNotFit(), over
     * RestateNodeCommitment::whyItWouldNotFit()): the growth is measured from
     * the machine's live capacity commitment as ResizeVpsHandler finds it -
     * to the larger, per dimension, of what is held and the target - less
     * what the machine is read at the hypervisor to run already, and held to
     * the node's ceilings on what grows (NodeCapacityPolicy::assessGrowth())
     * and to the pool's uncommitted space. So the quote and the resize ask one
     * question; when the hypervisor cannot be read the quote asks the
     * stricter one (the class docblock). This used to measure from the machine's recorded shape
     * against the node alone: a machine whose commitment is held raised was
     * refused a change the resize would take without asking for anything,
     * and a growth its pool could not hold was sold and then refused at the
     * resize, with the money moved.
     *
     * A courtesy read, as a quote is: the answer that holds is the resize's,
     * under the locks, and a growth that fits here and not there is refused
     * there as capacity (ResizeVpsHandler's class docblock).
     */
    private function growthTheNodeCannotHold(VirtualMachine $machine, PlanResources $target, ?VmResources $runs): ?string
    {
        $reason = $this->commitment->whyTheGrowthWouldNotFit($machine, $target->vcpu, $target->memoryMib, $target->diskGib, $runs);

        return $reason === null ? null : 'the node or pool the machine runs on cannot hold its growth: '.$reason;
    }

    /**
     * The same question for the plan change a proration invoice bills, asked
     * when it is paid. Null for an invoice that bills no recorded plan change.
     */
    public function refusalForTheInvoice(Invoice $invoice, ?VmResources $runs = null): ?string
    {
        if ($invoice->order_id !== null || $invoice->subscription_id === null) {
            return null;
        }

        /** @var PlanChange|null $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->first();

        return $change === null ? null : $this->refusalForTheChange($change, $runs);
    }

    /**
     * The same question for a recorded plan change: asked when its invoice is
     * paid (refusalForTheInvoice()), and again when that payment's settlement
     * is heard ({@see ResizeOnPlanChangeSettlement}), because a card payment
     * is captured after it was opened and the answer can change in between.
     */
    public function refusalForTheChange(PlanChange $change, ?VmResources $runs = null): ?string
    {
        if ($change->to_plan_id === null) {
            return null;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->find($change->subscription_id);

        if ($subscription === null) {
            return null;
        }

        /** @var Plan|null $plan */
        $plan = Plan::query()->find($change->to_plan_id);

        if ($plan === null) {
            return 'the plan this change moves onto no longer exists';
        }

        return $this->refusal($subscription, $plan, $this->runningBefore($subscription, $change, $runs), $runs);
    }

    /**
     * The VPS machine behind this subscription as the hypervisor reports it
     * now (MachineCommitment::whatItRuns()), for the capacity question;
     * null when there is none, it is not a VPS, or it cannot be read.
     *
     * A provider call, so taken before any transaction or lock and passed to
     * the refusal asked under them: ApplyPlanChange before the subscription's
     * lock, PayInvoiceFromWallet (or the controller around it, before the
     * audit's transaction) before the invoice's, ResizeOnPlanChangeSettlement
     * before the subscription's, and once per request where a request asks
     * about several plans (QuotePlanChange::options()). Asked under those
     * locks it held them for as long as the hypervisor took (B2, the
     * verification of round seven D). What a reading older than the lock can
     * cost is stated at MachineCommitment::whyTheGrowthWouldNotFit().
     */
    public function whatTheMachineRuns(?Subscription $subscription): ?VmResources
    {
        if ($subscription === null) {
            return null;
        }

        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();

        if ($service === null || $service->kind !== ProductKind::Vps->value) {
            return null;
        }

        /** @var VirtualMachine|null $machine */
        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->first();

        return $machine === null ? null : $this->commitment->whatItRuns($machine);
    }

    /**
     * whatTheMachineRuns() for the subscription a plan change's invoice
     * bills; null for an invoice that bills no recorded plan change, which
     * asks nothing of it.
     */
    public function whatTheMachineRunsForTheInvoice(Invoice $invoice): ?VmResources
    {
        if ($invoice->order_id !== null || $invoice->subscription_id === null) {
            return null;
        }

        if (! PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->exists()) {
            return null;
        }

        return $this->whatTheMachineRuns(Subscription::query()->find($invoice->subscription_id));
    }

    /**
     * What the service ran before this change - it is not delivered yet, so
     * what it runs now (whatTheServiceRuns()) - falling back to the plan the
     * change left, not the subscription's plan, which is already the target.
     */
    private function runningBefore(Subscription $subscription, PlanChange $change, ?VmResources $runs): PlanResources
    {
        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();

        /** @var Plan|null $from */
        $from = $change->from_plan_id === null ? null : Plan::query()->find($change->from_plan_id);

        return $this->whatTheServiceRuns($service, $from, $runs);
    }

    /**
     * What the service runs now: the one answer the quote
     * ({@see QuotePlanChange}) and the delivery (refusalForTheChange())
     * measure a change of shape from.
     *
     *  - A VPS with a machine, when the caller holds a reading of it
     *    ($runs, MachineCommitment::whatItRuns(), taken before any lock): the
     *    machine as the hypervisor reported it - its vCPU and memory, each
     *    figure it did not report taken from the row - and the larger of the
     *    disk reported and the row's, because the resize refuses a disk below
     *    the row (a target no smaller than this passes both). What grows, and
     *    whether the node can hold it, is measured from the same reading
     *    (growthTheNodeCannotHold()), as the resize measures it.
     *  - A VPS with a machine and no reading (a caller that passed none, or a
     *    hypervisor that could not be read): the machine's row
     *    (virtual_machines) - the shape the hypervisor last confirmed,
     *    written by every resize that completes (ResizeVpsHandler).
     *
     * The row is behind the machine while a resize the hypervisor accepted is
     * unconfirmed: its task still running (`vps.resize_in_progress`, the job
     * queued for its next attempt) or its read-back failed or fell short
     * (`vps.resize_unverified`, the job in review). While the job is queued,
     * running or in review, QuotePlanChange refuses every change of plan
     * (ServiceBusy), and so does the change itself, which asks the quote's
     * refusals again. Of what writes a job's status under src/Modules, two
     * move one out of review: its retry (RetryProvisioningJob), which runs
     * it - a resize looks at the machine and writes the row - and an
     * adoption (AdoptOrphanResource), which settles it without running it
     * and is refused for a job that builds no resource, a resize among them. `vps.resize_unverified` used to fail the job, which holds
     * nothing, and an adoption could settle it too; either way a downgrade
     * was then quoted from the row as no change of shape - credited, with no
     * resize queued, and the machine left large (A8-1, the re-audit after
     * round seven; B-1, its verification). A quote with a reading measures
     * from the machine in any case, so even a row left behind is not a credit
     * for a change the machine did not make; one without a reading measures
     * from the row.
     *  - Otherwise the service's own recorded allocation where it has one,
     *    and else the plan given (the subscription's, or the one the change
     *    left).
     *
     * `services.resources` is what was bought, snapshotted when the service
     * was: no resize writes it. Read as what the machine is now it went wrong
     * after the first resize (F-07, the re-audit after round six): an upgrade
     * back to the bought shape read as no change of shape and was sold on a
     * node that could not hold it; a downgrade after a delivered upgrade read
     * as no change, was credited and queued no resize, and the machine stayed
     * large at the small plan's price; a downgrade onto a disk smaller than
     * the machine had grown to got past the disk-shrink refusal and was
     * credited before the resize refused it.
     */
    public function whatTheServiceRuns(?Service $service, ?Plan $otherwise, ?VmResources $runs = null): PlanResources
    {
        if ($service !== null && $service->kind === ProductKind::Vps->value) {
            /** @var VirtualMachine|null $machine */
            $machine = VirtualMachine::query()->where('service_id', $service->getKey())->first();

            if ($machine !== null) {
                return $runs === null
                    ? new PlanResources(vcpu: $machine->vcpu, memoryMib: $machine->memory_mib, diskGib: $machine->disk_gib)
                    : new PlanResources(
                        vcpu: $runs->vcpu,
                        memoryMib: $runs->memoryMib,
                        // The larger: the resize refuses a disk below the row.
                        diskGib: max($runs->diskGib, $machine->disk_gib),
                    );
            }
        }

        $fromService = $service === null ? new PlanResources : PlanResources::fromArray($service->resources ?? []);

        if ($fromService->vcpu !== null || $fromService->memoryMib !== null || $fromService->diskGib !== null) {
            return $fromService;
        }

        return $otherwise === null ? new PlanResources : PlanResources::fromArray($otherwise->resources ?? []);
    }

    /**
     * Whether the settlement of this paid proration invoice was heard while
     * the subscription was live (PlanChange::$delivered_at), or - for a change
     * settled before that was recorded, or an invoice with no recorded change
     * - queued the resize (or the package change) it paid for, under the key
     * the settlement gives it (`plan-change:<subscription>:<plan>:invoice:<id>`,
     * {@see QueuePlanChangeAtProvider}). Since U-1 no key a customer chooses
     * reaches a provisioning job. A job written before that fix ended in the
     * customer's raw Idempotency-Key, and one whose key the customer spelled
     * `invoice:<id>` for this invoice would still answer this; nothing
     * rewrites those rows.
     */
    public function wasDelivered(string $subscriptionId, string $invoiceId): bool
    {
        $delivered = PlanChange::query()
            ->where('proration_invoice_id', $invoiceId)
            ->whereNotNull('delivered_at')
            ->exists();

        return $delivered || ProvisioningJob::query()
            ->where('idempotency_key', 'like', sprintf('plan-change:%s:%%:invoice:%s', $subscriptionId, $invoiceId))
            ->exists();
    }

    /**
     * Whether a change of this subscription has been paid for and not yet
     * delivered: its proration invoice is paid and its settlement has not
     * been heard (wasDelivered() is false), and no later change was settled
     * after it.
     *
     * The window the queue's lag leaves between a capture and its settlement.
     * A second change made in it was accepted, and when the subscription then
     * ended the paid upgrade was taken as superseded by the unpaid one and
     * kept (X1). Refused while it lasts (PlanChangeRefusal::PreviousChangePending).
     *
     * Only changes made since lookBackFrom() are read: the start of the
     * period before the current one, or seven days before the current
     * period began, whichever is earlier. `delivered_at` was added without a
     * back-fill, and a change settled before it existed that queued nothing
     * (nothing to resize) looks undelivered for ever; bounded, such a row
     * cannot hold a subscription's plan changes past its second renewal, or
     * past seven days after the current period began on a period shorter
     * than a week. The period before is read
     * because a renewal can come between a capture and its settlement: a
     * change paid in the last minutes of a period and settled after the
     * renewal was not read at all, the next change was accepted, and when it
     * was a downgrade (settled at once) the paid upgrade's settlement found
     * itself superseded and built nothing - paid for and never delivered
     * (the re-audit after round six could not establish it; this round did,
     * AChangePaidBeforeTheRenewalAndSettledAfterItIsStillAwaitedTest).
     */
    public function aPaidChangeAwaitsDelivery(Subscription $subscription): bool
    {
        /** @var list<PlanChange> $paid */
        $paid = PlanChange::query()
            ->where('subscription_id', $subscription->getKey())
            ->whereNull('delivered_at')
            ->whereNull('returned_at')
            ->whereNotNull('proration_invoice_id')
            ->where('changed_at', '>=', $this->lookBackFrom($subscription))
            ->whereExists(static fn ($invoice) => $invoice
                ->selectRaw('1')
                ->from('invoices')
                ->whereColumn('invoices.id', 'subscription_plan_changes.proration_invoice_id')
                ->where('invoices.status', InvoiceStatus::Paid->value))
            ->get()
            ->all();

        foreach ($paid as $change) {
            if (! $this->wasDelivered((string) $subscription->getKey(), (string) $change->proration_invoice_id)
                && ! $this->aLaterChangeWasSettled($change)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How far back aPaidChangeAwaitsDelivery() reads: the start of the period
     * before the current one (BillingPeriod::retreat()), or LOOK_BACK_DAYS
     * before the current period began, whichever is earlier.
     *
     * The period before, because a renewal can come between a capture and
     * its settlement. At least a week, because on an hourly (or daily)
     * period "the period before" is an hour (or a day) and the settlement
     * can be later than that: its listener retries for about six minutes
     * and twenty seconds (ResizeOnPlanChangeSettlement::backoff(), five
     * tries), and a payments queue held up behind a worker that is down waits
     * as long as the outage. A week covers an outage of up to a week; a
     * settlement heard later than that is not read, the next change is
     * accepted, and if that change settles first the late one builds nothing:
     * a paid proration of at most one hour's or one day's price goes
     * undelivered. What it
     * costs: a legacy row with no delivered_at holds a short-period
     * subscription's plan changes for that week.
     */
    private function lookBackFrom(Subscription $subscription): CarbonImmutable
    {
        $start = CarbonImmutable::instance($subscription->current_period_start);
        $periodBefore = $subscription->billing_period->retreat($start);
        $atLeast = $start->subDays(self::LOOK_BACK_DAYS);

        return $periodBefore->lessThan($atLeast) ? $periodBefore : $atLeast;
    }

    /**
     * Whether a plan change recorded after this one has been settled - it
     * owed nothing, or its invoice was paid - and so decided the machine's
     * shape itself. A later change still waiting on its invoice (or whose
     * invoice was voided) has not, and nor has one paid and then returned at
     * its settlement (`returned_at`): it delivered nothing and its money went
     * back, so an earlier paid change it would have superseded is still
     * undelivered - built by its settlement, or returned when the
     * subscription ends (ReturnAnUpgradeTheEndPrevented).
     */
    public function aLaterChangeWasSettled(PlanChange $change): bool
    {
        return PlanChange::query()
            ->where('subscription_id', $change->subscription_id)
            ->whereNull('returned_at')
            ->where(static fn ($later) => $later
                ->where('changed_at', '>', $change->changed_at)
                ->orWhere(static fn ($same) => $same
                    ->where('changed_at', $change->changed_at)
                    ->where('id', '>', $change->id)))
            ->where(static fn ($settled) => $settled
                ->whereNull('proration_invoice_id')
                ->orWhereExists(static fn ($paid) => $paid
                    ->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('invoices.id', 'subscription_plan_changes.proration_invoice_id')
                    ->where('invoices.status', InvoiceStatus::Paid->value)))
            ->exists();
    }
}
