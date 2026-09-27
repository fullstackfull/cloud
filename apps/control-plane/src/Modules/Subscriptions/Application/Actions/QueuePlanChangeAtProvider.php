<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Queries\HostingPackageForPlan;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\PlanResources;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Ask the engine to make the product what the customer now pays for.
 *
 * Lifted out of {@see ApplyPlanChange} so that it has two callers rather than
 * one, because a plan change now reaches the provider at two different
 * moments. A downgrade goes through immediately — the customer is receiving
 * less and owes nothing. An upgrade waits for its proration invoice to settle,
 * and is queued from {@see ResizeOnPlanChangeSettlement}
 * instead. The work itself is identical either way, so it lives in one place.
 *
 * Two shapes, because the two products are changed in different places: a
 * machine is resized at the hypervisor, and a hosting account is moved onto
 * another package at the control panel. Both are queued for the same reason —
 * each is a provider call that can fail, and a plan change whose provider half
 * is not tracked is a customer charged for something they did not get.
 *
 * A dedicated server has neither. It cannot be resized at all: a customer
 * moving between dedicated plans is moving between machines, which is a
 * different operation with a different price. So a change of shape this
 * would queue nothing for - on such a product, or on a VPS service with no
 * machine, or onto a hosting plan with no single package on sale - is
 * refused before any money moves ({@see PlanChangeDelivery}), rather than
 * paid for and delivered as nothing (F-07).
 */
final readonly class QueuePlanChangeAtProvider
{
    public function __construct(
        private CreateProvisioningJob $createJob,
        private HostingPackageForPlan $packages,
    ) {}

    public function execute(
        Subscription $subscription,
        string $planId,
        PlanResources $resources,
        string $delivers,
    ): ?ProvisioningJob {
        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();

        if ($service === null) {
            return null;
        }

        if ($service->kind === ProductKind::SharedHosting->value) {
            return $this->queuePackageChange($subscription, $service, $planId, $delivers);
        }

        if ($service->kind !== ProductKind::Vps->value) {
            return null;
        }

        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->first();

        if ($machine === null) {
            return null;
        }

        return $this->dispatch($this->createJob->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::Resize,
            idempotencyKey: $this->keyFor($subscription, $planId, $delivers),
            provider: (string) ($machine->cluster()->first()?->driver->value ?? 'unknown'),
            serviceId: (string) $service->getKey(),
            customerId: $subscription->customer_id,
            payload: [
                'virtual_machine_id' => (string) $machine->getKey(),
                'subscription_id' => (string) $subscription->getKey(),
                'plan_id' => $planId,
                // The target shape, absolute. The handler turns the disk into
                // a growth against what the machine actually has, which is the
                // only form the provider contract accepts.
                'vcpu' => $resources->vcpu,
                'memory_mib' => $resources->memoryMib,
                'disk_gib' => $resources->diskGib,
            ],
        )));
    }

    /**
     * The hosting half: the account moves onto the package the new plan is
     * sold under.
     *
     * Which package that is comes from {@see HostingPackageForPlan}, the same
     * answer checkout and the build are given. This used to take the first
     * row naming the plan, withdrawn or not, in heap order — so a paid upgrade
     * could tell the panel the package the new one had replaced (F-32).
     *
     * When the resolver refuses, nothing is queued rather than guessing.
     * Choosing "some package on the right node" would put a customer on a
     * quota nobody sold them.
     *
     * A plan the resolver refuses is refused before any money moves: the
     * quote refuses the change (PlanChangeRefusal::NotDeliverable, asked of
     * PlanChangeDelivery), the payment of an accepted change's invoice asks
     * again (F-07), and so does the settlement of that payment, just before
     * it calls this: a change refused there is returned, not queued
     * (ReturnAPlanChangeNoLongerDeliverable). This used to accept the change,
     * take the money and queue nothing. What still reaches here refused is a
     * package changed between that last question and this read; a
     * proration invoice issued before plan changes were recorded (the
     * settlement's fallback, which asks nothing); and a settlement redelivered
     * for a change already recorded delivered, after its package was
     * withdrawn - the settlement does not ask again of a delivered change, and
     * whatever its first delivery queued stands, so nothing new is owed.
     * Every refusal is logged with the resolver's reason; in the first
     * two the customer has paid, the subscription has moved and the quota has
     * not.
     */
    private function queuePackageChange(
        Subscription $subscription,
        Service $service,
        string $planId,
        string $delivers,
    ): ?ProvisioningJob {
        $account = HostingAccount::query()->where('service_id', $service->getKey())->first();

        if ($account === null) {
            return null;
        }

        $choice = $this->packages->resolve($planId);
        $package = $choice->package;

        if ($package === null) {
            Log::warning('A plan change was not applied at the panel because the platform cannot say which hosting package the plan is sold under.', [
                'subscription_id' => (string) $subscription->getKey(),
                'plan_id' => $planId,
                'reason' => $choice->reason,
            ]);

            return null;
        }

        return $this->dispatch($this->createJob->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::ChangeHostingPackage,
            idempotencyKey: $this->keyFor($subscription, $planId, $delivers),
            provider: $account->node()->first()?->panel->value ?? 'unknown',
            serviceId: (string) $service->getKey(),
            customerId: $subscription->customer_id,
            payload: [
                'hosting_account_id' => (string) $account->getKey(),
                'hosting_package_id' => (string) $package->getKey(),
                'subscription_id' => (string) $subscription->getKey(),
                'plan_id' => $planId,
            ],
        )));
    }

    /**
     * Keyed on the one plan change this delivers, never on anything a client
     * sent.
     *
     * `$delivers` names it: `change:<PlanChange id>` for a change queued when
     * it is made (ApplyPlanChange), `invoice:<proration invoice id>` for one
     * queued when its invoice settles (ResizeOnPlanChangeSettlement - one
     * invoice bills one change). A settlement redelivered by the queue finds
     * the job the first delivery made; a customer's retry of the same change
     * is refused as `same_plan` before it gets here, and a double-click waits
     * on the subscription's lock and is refused the same way.
     *
     * The key used to end in the customer's raw Idempotency-Key. The engine's
     * key is unique and CreateProvisioningJob answers a repeat with the job
     * already there, so a customer who reused a key on a later change - a
     * downgrade after a paid upgrade - was handed back the earlier, completed
     * job and nothing was queued: the machine kept the upgrade's shape and was
     * billed at the smaller plan, with the downgrade's credit posted (U-1, the
     * re-audit after round five; the same for a hosting package). And a key
     * the customer spelled `invoice:<id>` matched the settlement's scheme.
     * Scoped to the change as VpsIdempotencyKey scopes a VPS operation to its
     * machine, a key reused on another change is another job.
     */
    private function keyFor(Subscription $subscription, string $planId, string $delivers): string
    {
        if (preg_match('/\A(change|invoice):[0-9A-Za-z]{26}\z/', $delivers) !== 1) {
            throw new InvalidArgumentException(sprintf('A plan change is queued under the change or the invoice it delivers, not under "%s".', $delivers));
        }

        return sprintf(
            'plan-change:%s:%s:%s',
            $subscription->getKey(),
            $planId,
            $delivers,
        );
    }

    /**
     * Dispatched once the caller's transaction commits, and at once when
     * there is none.
     *
     * ApplyPlanChange writes the job row inside the transaction that moves the
     * plan and settles its money. A worker handed the job before that commit
     * would find no row - or, if the transaction then rolled back, would
     * resize a machine for a plan change that never happened.
     */
    private function dispatch(ProvisioningJob $job): ProvisioningJob
    {
        if ($job->wasRecentlyCreated) {
            $id = (string) $job->getKey();

            DB::afterCommit(static function () use ($id): void {
                RunProvisioningJob::dispatch($id);
            });
        }

        return $job;
    }
}
