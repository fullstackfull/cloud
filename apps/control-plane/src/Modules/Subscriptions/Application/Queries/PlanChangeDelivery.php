<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Queries\HostingPackageForPlan;
use Lynomia\Modules\Subscriptions\Application\Actions\QueuePlanChangeAtProvider;
use Lynomia\Modules\Subscriptions\Application\Actions\QuotePlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAnUpgradeTheEndPrevented;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Application\Listeners\ReturnAPaidChangeWhoseDeliveryStopped;
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
 * of a subscription or of its service reads
 * ({@see ReturnAnUpgradeTheEndPrevented}) and what the settlement reads
 * ({@see ResizeOnPlanChangeSettlement}), one answer for both. Settled
 * ({@see wasSettled()}) is not delivered: a settlement queues a resize or a
 * package change, which can still stop in review or fail, and a change whose
 * job stopped on a service that has ended was not delivered
 * ({@see deliveryEndedWithItsService()}) - its payment goes back.
 */
final readonly class PlanChangeDelivery
{
    /** The least lookBackFrom() reads behind the current period, in days. */
    public const int LOOK_BACK_DAYS = 7;

    /**
     * Where a job that delivers a paid change stops without delivering it
     * (deliveryEndedWithItsService()).
     *
     * @var list<ProvisioningJobStatus>
     */
    public const array STOPPED_UNDELIVERED = [
        ProvisioningJobStatus::NeedsReview,
        ProvisioningJobStatus::Failed,
        ProvisioningJobStatus::Cancelled,
    ];

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
        $machine = $this->machineBehind($subscription);

        return $machine === null ? null : $this->commitment->whatItRuns($machine);
    }

    /**
     * The VPS machine behind this subscription as its row (virtual_machines)
     * records it - the shape the hypervisor last confirmed, written by every
     * resize that completes; null when there is no such machine. A database
     * read, no provider call.
     *
     * ApplyPlanChange takes it before its reading (whatTheMachineRuns()) and
     * again under its locks, after the quote's ServiceBusy question: a row
     * that moved in between is a resize that completed after the reading was
     * taken, so the reading is stale and is taken again outside the locks.
     */
    public function whatTheRowSays(?Subscription $subscription): ?PlanResources
    {
        $machine = $this->machineBehind($subscription);

        return $machine === null
            ? null
            : new PlanResources(vcpu: $machine->vcpu, memoryMib: $machine->memory_mib, diskGib: $machine->disk_gib);
    }

    private function machineBehind(?Subscription $subscription): ?VirtualMachine
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

        return $machine;
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
     * refusals again. Of what writes a job's status under src/Modules, four
     * move one out of review, and three of them can reach a resize - the
     * fourth, OperationsController's settling of a reinstall, reaches only a
     * reinstall's job (a VmReinstall's or a DedicatedReinstall's). The three
     * that can are its retry (RetryProvisioningJob), which runs
     * it - a resize looks at the machine and writes the row - an
     * adoption (AdoptOrphanResource), which would settle it without running
     * it and is refused for a job that builds no resource, a resize among
     * them, and a close (CloseAJobWhoseServiceEnded), which cancels it
     * without running it and only once its service has ended - a service no
     * change of plan is quoted for (ServiceNotActive). `vps.resize_unverified` used to fail the job, which holds
     * nothing, and an adoption could settle it too; either way a downgrade
     * was then quoted from the row as no change of shape - credited, with no
     * resize queued, and the machine left large (A8-1, the re-audit after
     * round seven; B-1, its verification). A quote with a reading measures
     * from the reading, so a row left behind the machine is not a credit for
     * a change the machine did not make; one without a reading measures from
     * the row. The reading can be the one left behind instead: it is taken
     * before the locks, and a resize that completes between it and them
     * leaves no job busy. A downgrade quoted from it read as no change of
     * shape, was credited and queued no resize (A9-1, the re-audit after
     * round eight). ApplyPlanChange therefore takes the row
     * (whatTheRowSays()) before its reading and again under its locks,
     * after the quote asked ServiceBusy; a row that moved in between means
     * a resize completed after the reading, and the change writes nothing
     * and reads the machine again outside the locks - three times, then
     * refuses as ServiceBusy. The payment and the settlement, which ask with
     * a reading of their own, are not held to this: they do not measure a
     * credit from it.
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
     *
     * Settled is not delivered: what the settlement queued can still stop in
     * review or fail. aPaidChangeAwaitsDelivery() reads this - a change
     * settled is not one whose settlement is still awaited - and
     * wasDelivered() reads it with deliveryEndedWithItsService().
     */
    public function wasSettled(string $subscriptionId, string $invoiceId): bool
    {
        $settled = PlanChange::query()
            ->where('proration_invoice_id', $invoiceId)
            ->whereNotNull('delivered_at')
            ->exists();

        return $settled || $this->deliveringJobs($subscriptionId, $invoiceId)->exists();
    }

    /**
     * Whether this paid proration invoice's change was delivered, as the end
     * of a subscription or of its service reads it
     * ({@see ReturnAnUpgradeTheEndPrevented}): its settlement was heard
     * (wasSettled()), and what that settlement queued has not ended
     * undelivered with its service (deliveryEndedWithItsService()).
     *
     * A settlement that queued nothing - nothing to resize, or a later change
     * already decided the machine - delivered the change all the same. It
     * used to be read off `delivered_at` alone, so a resize that stopped in
     * review or failed, on a service that then ended, read as delivered, and
     * the payment was kept for a change never made (R10-M, the final audit).
     */
    public function wasDelivered(string $subscriptionId, string $invoiceId): bool
    {
        return $this->wasSettled($subscriptionId, $invoiceId)
            && ! $this->deliveryEndedWithItsService($subscriptionId, $invoiceId);
    }

    /**
     * Whether the resize or package change queued for this paid proration
     * invoice (under its key, as wasSettled() reads it) can no longer deliver
     * it: there is such a job, and every one of them stopped without
     * succeeding - in review, failed, or closed (cancelled) - on a service
     * that has ended (`terminated`).
     *
     * Both halves, because a stopped job on a live service is not the end of
     * it: an operator's retry can still run it and deliver
     * (RetryProvisioningJob), and the payment is held for that
     * (docs/billing.md). A retry is refused once the service has ended, and a
     * close does not run the job (CloseAJobWhoseServiceEnded), so a job
     * stopped on an ended service never delivers. A job still queued or
     * running is not stopped: it is asked again when it stops
     * ({@see ReturnAPaidChangeWhoseDeliveryStopped}).
     */
    public function deliveryEndedWithItsService(string $subscriptionId, string $invoiceId): bool
    {
        return $this->endedWithItsService($this->deliveringJobs($subscriptionId, $invoiceId));
    }

    /**
     * Whether a plan change recorded after this one was delivered, and so
     * decided the machine's shape in its place: what the end of a
     * subscription or a service asks before it keeps an earlier paid change
     * undelivered ({@see ReturnAnUpgradeTheEndPrevented}).
     *
     * A later change counts only when it was delivered itself. It is not
     * returned (`returned_at`), and either:
     *  - it owed nothing (no proration invoice), and what it queued under its
     *    own key (`...:change:<id>`) did not stop undelivered on a service
     *    that has ended - or it queued nothing; or
     *  - its invoice was paid and it was delivered (wasDelivered()).
     *
     * aLaterChangeWasSettled() - the settlement's question, "has a later
     * change already decided what to build" - counted any later change that
     * owed nothing or was paid. Read at the end, that kept an earlier paid
     * upgrade because a later one was paid, although the later one failed
     * too: 27.000 kept for nothing, and the answer turned on which of the two
     * was asked first, since a later change already returned did not count
     * (B2, the verification of round ten M: two paid upgrades that both
     * failed; a paid upgrade followed by a downgrade whose shrink failed). A
     * later change that was itself not delivered decided nothing, and the
     * answer no longer depends on whether it has been returned yet.
     */
    public function aLaterChangeWasDelivered(PlanChange $change): bool
    {
        /** @var list<PlanChange> $later */
        $later = PlanChange::query()
            ->where('subscription_id', $change->subscription_id)
            ->whereNull('returned_at')
            ->where(static fn ($query) => $query
                ->where('changed_at', '>', $change->changed_at)
                ->orWhere(static fn ($same) => $same
                    ->where('changed_at', $change->changed_at)
                    ->where('id', '>', $change->id)))
            ->get()
            ->all();

        $subscriptionId = (string) $change->subscription_id;

        foreach ($later as $candidate) {
            if ($candidate->proration_invoice_id === null) {
                $queued = ProvisioningJob::query()->where('idempotency_key', 'like', sprintf('plan-change:%s:%%:change:%s', $subscriptionId, $candidate->getKey()));

                if (! $this->endedWithItsService($queued)) {
                    return true;
                }

                continue;
            }

            $paid = Invoice::query()
                ->whereKey($candidate->proration_invoice_id)
                ->where('status', InvoiceStatus::Paid->value)
                ->exists();

            if ($paid && $this->wasDelivered($subscriptionId, (string) $candidate->proration_invoice_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the paid plan change this proration invoice bills is still in
     * play and not delivered: recorded, paid, not returned, and either its
     * settlement has not been heard (nothing queued, `delivered_at` empty) or
     * what it queued has not succeeded - queued, running, in review, failed.
     *
     * Such a change can still be delivered - the settlement queues it, a
     * retry runs it - and refunding its invoice by hand would leave it in
     * play: the subscription on the new plan and billed at its price, a job
     * in review holding every plan change (`service_busy`), a later downgrade
     * crediting the refunded money a second time, and a retry that could
     * deliver a change already paid back (B1, the verification of round ten
     * M). The raw refund route is refused for it; an operator returns it with
     * ReturnAHeldPaidChange, which takes it out of play.
     */
    public function aPaidChangeIsInPlay(Invoice $invoice): bool
    {
        if ($invoice->status !== InvoiceStatus::Paid || $invoice->subscription_id === null) {
            return false;
        }

        /** @var PlanChange|null $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->first();

        if ($change === null || $change->returned_at !== null) {
            return false;
        }

        $jobs = $this->deliveringJobs((string) $invoice->subscription_id, (string) $invoice->getKey())->get(['status'])->all();

        if ($jobs === []) {
            return $change->delivered_at === null;
        }

        foreach ($jobs as $job) {
            if ($job->status === ProvisioningJobStatus::Succeeded || $job->status === ProvisioningJobStatus::Cancelled) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether there is such a job and every one stopped without succeeding
     * (STOPPED_UNDELIVERED) on a service that has ended.
     *
     * @param  Builder<ProvisioningJob>  $jobs
     */
    private function endedWithItsService(Builder $jobs): bool
    {
        /** @var list<ProvisioningJob> $found */
        $found = $jobs->get(['id', 'status', 'service_id'])->all();

        if ($found === []) {
            return false;
        }

        foreach ($found as $job) {
            if (! in_array($job->status, self::STOPPED_UNDELIVERED, true) || $job->service_id === null) {
                return false;
            }

            if (Service::query()->find($job->service_id)?->status !== ServiceStatus::Terminated) {
                return false;
            }
        }

        return true;
    }

    /**
     * The jobs queued under this invoice's key (wasSettled()).
     *
     * @return Builder<ProvisioningJob>
     */
    private function deliveringJobs(string $subscriptionId, string $invoiceId): Builder
    {
        return ProvisioningJob::query()
            ->where('idempotency_key', 'like', sprintf('plan-change:%s:%%:invoice:%s', $subscriptionId, $invoiceId));
    }

    /**
     * Whether a change of this subscription has been paid for and not yet
     * delivered: its proration invoice is paid and its settlement has not
     * been heard (wasSettled() is false), and no later change was settled
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
            if (! $this->wasSettled((string) $subscription->getKey(), (string) $change->proration_invoice_id)
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
