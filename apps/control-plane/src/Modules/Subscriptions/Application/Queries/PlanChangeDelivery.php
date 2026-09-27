<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Queries;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
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
 *    (growthTheNodeCannotHold()); a target no larger than the machine in any
 *    dimension is not asked, as the resize does not ask it. The target plan's
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
     */
    public function refusal(Subscription $subscription, Plan $plan, PlanResources $current): ?string
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
                : $this->growthTheNodeCannotHold($machine, PlanResources::fromArray($plan->resources ?? []));
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
     * to the larger, per dimension, of what is held and the target - and
     * held to the node's ceilings on what grows (NodeCapacityPolicy::assessGrowth())
     * and to the pool's uncommitted space. So the quote and the resize ask one
     * question. This used to measure from the machine's recorded shape
     * against the node alone: a machine whose commitment is held raised was
     * refused a change the resize would take without asking for anything,
     * and a growth its pool could not hold was sold and then refused at the
     * resize, with the money moved.
     *
     * A courtesy read, as a quote is: the answer that holds is the resize's,
     * under the locks, and a growth that fits here and not there is refused
     * there as capacity (ResizeVpsHandler's class docblock).
     */
    private function growthTheNodeCannotHold(VirtualMachine $machine, PlanResources $target): ?string
    {
        $reason = $this->commitment->whyTheGrowthWouldNotFit($machine, $target->vcpu, $target->memoryMib, $target->diskGib);

        return $reason === null ? null : 'the node or pool the machine runs on cannot hold its growth: '.$reason;
    }

    /**
     * The same question for the plan change a proration invoice bills, asked
     * when it is paid. Null for an invoice that bills no recorded plan change.
     */
    public function refusalForTheInvoice(Invoice $invoice): ?string
    {
        if ($invoice->order_id !== null || $invoice->subscription_id === null) {
            return null;
        }

        /** @var PlanChange|null $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->first();

        return $change === null ? null : $this->refusalForTheChange($change);
    }

    /**
     * The same question for a recorded plan change: asked when its invoice is
     * paid (refusalForTheInvoice()), and again when that payment's settlement
     * is heard ({@see ResizeOnPlanChangeSettlement}), because a card payment
     * is captured after it was opened and the answer can change in between.
     */
    public function refusalForTheChange(PlanChange $change): ?string
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

        return $this->refusal($subscription, $plan, $this->runningBefore($subscription, $change));
    }

    /**
     * What the service ran before this change - it is not delivered yet, so
     * what it runs now (whatTheServiceRuns()) - falling back to the plan the
     * change left, not the subscription's plan, which is already the target.
     */
    private function runningBefore(Subscription $subscription, PlanChange $change): PlanResources
    {
        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();

        /** @var Plan|null $from */
        $from = $change->from_plan_id === null ? null : Plan::query()->find($change->from_plan_id);

        return $this->whatTheServiceRuns($service, $from);
    }

    /**
     * What the service runs now: the one answer the quote
     * ({@see QuotePlanChange}) and the delivery (refusalForTheChange())
     * measure a change of shape from.
     *
     *  - A VPS with a machine: the machine's row (virtual_machines) - the
     *    shape the hypervisor confirmed, written by every resize that
     *    completes (ResizeVpsHandler), and the shape the resize itself reads
     *    to decide what grows and whether the disk would shrink.
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
    public function whatTheServiceRuns(?Service $service, ?Plan $otherwise): PlanResources
    {
        if ($service !== null && $service->kind === ProductKind::Vps->value) {
            /** @var VirtualMachine|null $machine */
            $machine = VirtualMachine::query()->where('service_id', $service->getKey())->first();

            if ($machine !== null) {
                return new PlanResources(vcpu: $machine->vcpu, memoryMib: $machine->memory_mib, diskGib: $machine->disk_gib);
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
     * Only changes made in the current period and the one before it are
     * read. `delivered_at` was added without a back-fill, and a change
     * settled before it existed that queued nothing (nothing to resize) looks
     * undelivered for ever; bounded, such a row cannot hold a subscription's
     * plan changes past its second renewal. The period before is read
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
            ->where('changed_at', '>=', $subscription->billing_period->retreat($subscription->current_period_start))
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
