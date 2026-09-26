<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\Log;
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
 * different operation with a different price.
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
        ?string $idempotencyKey = null,
    ): ?ProvisioningJob {
        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();

        if ($service === null) {
            return null;
        }

        if ($service->kind === ProductKind::SharedHosting->value) {
            return $this->queuePackageChange($subscription, $service, $planId, $idempotencyKey);
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
            idempotencyKey: $this->keyFor($subscription, $planId, $idempotencyKey),
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
     * Only one of its three refusals is logged, and the line is drawn on
     * purpose. Two packages on sale is a silence the resolver introduced:
     * before it, an ambiguous plan got the heap's pick, and now it gets
     * nothing — the customer has paid, the subscription has moved and the
     * quota has not — so it is said, with the resolver's reason, the way
     * checkout says it when it refuses the same plan.
     *
     * The other two refusals both mean nothing is on sale for the plan. With
     * no package at all, this queued nothing and said nothing before the
     * resolver too. With every package withdrawn, it used to queue a change
     * onto a withdrawn package — the defect — and now queues nothing. Both
     * stay silent here: whether a plan change onto a plan with nothing on sale
     * should be refused before the money moves, or reported after, is a
     * decision about the plan-change path that F-32 did not take, and the
     * tests pin both silences so that the warning is not widened by accident.
     */
    private function queuePackageChange(
        Subscription $subscription,
        Service $service,
        string $planId,
        ?string $idempotencyKey,
    ): ?ProvisioningJob {
        $account = HostingAccount::query()->where('service_id', $service->getKey())->first();

        if ($account === null) {
            return null;
        }

        $choice = $this->packages->resolve($planId);
        $package = $choice->package;

        if ($package === null) {
            if ($choice->refusal === HostingPackageForPlan::NAMES_SEVERAL) {
                Log::warning('A plan change was not applied at the panel because the platform cannot say which hosting package the plan is sold under.', [
                    'subscription_id' => (string) $subscription->getKey(),
                    'plan_id' => $planId,
                    'reason' => $choice->reason,
                ]);
            }

            return null;
        }

        return $this->dispatch($this->createJob->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::ChangeHostingPackage,
            idempotencyKey: $this->keyFor($subscription, $planId, $idempotencyKey),
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
     * Keyed on the subscription and the target plan rather than on a
     * client-supplied value alone. A customer double-clicking confirm must not
     * resize their machine twice — the second press finds the job the first
     * one made — and a settlement redelivered by the queue must not either.
     */
    private function keyFor(Subscription $subscription, string $planId, ?string $idempotencyKey): string
    {
        return sprintf(
            'plan-change:%s:%s:%s',
            $subscription->getKey(),
            $planId,
            $idempotencyKey ?? 'default',
        );
    }

    private function dispatch(ProvisioningJob $job): ProvisioningJob
    {
        if ($job->wasRecentlyCreated) {
            RunProvisioningJob::dispatch((string) $job->getKey());
        }

        return $job;
    }
}
