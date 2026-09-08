<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\Subscriptions\Application\DTOs\PlanChangeOutcome;
use Lynomia\Modules\Subscriptions\Application\DTOs\PlanChangeQuote;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\PlanChangeRefusedException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Vps\Application\Handlers\ResizeVpsHandler;

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
 * So this action does the money, then queues the resize, and says which of
 * those happened. What it deliberately does not do is write the new shape onto
 * the service as though the machine already had it: the customer's dashboard
 * would show four vCPU on a machine running two, and the platform's own
 * capacity accounting would believe a node had handed out memory it has not.
 * {@see ResizeVpsHandler} writes the
 * shape, after the provider confirms it.
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
        private CreateProvisioningJob $createJob,
        private RecordAuditEntry $audit,
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

        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();

        $resizeJob = $quote->changesInfrastructure
            ? $this->queueTheChangeAtTheProvider($subscription, $service, $quote, $idempotencyKey)
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
            ],
        );

        return new PlanChangeOutcome(
            proration: $proration,
            quote: $quote,
            resizeJob: $resizeJob,
        );
    }

    /**
     * Ask the engine to make the product what the customer now pays for.
     *
     * Two shapes, because the two products are changed in different places: a
     * machine is resized at the hypervisor, and a hosting account is moved
     * onto another package at the control panel. Both are queued for the same
     * reason — each is a provider call that can fail, and a plan change whose
     * provider half is not tracked is a customer charged for something they
     * did not get.
     *
     * A dedicated server has neither. It cannot be resized at all: a customer
     * moving between dedicated plans is moving between machines, which is a
     * different operation with a different price.
     */
    private function queueTheChangeAtTheProvider(
        Subscription $subscription,
        ?Service $service,
        PlanChangeQuote $quote,
        ?string $idempotencyKey,
    ): ?ProvisioningJob {
        if ($service === null) {
            return null;
        }

        if ($service->kind === ProductKind::SharedHosting->value) {
            return $this->queuePackageChange($subscription, $service, $quote, $idempotencyKey);
        }

        if ($service->kind !== ProductKind::Vps->value) {
            return null;
        }

        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->first();

        if ($machine === null) {
            return null;
        }

        $job = $this->createJob->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::Resize,
            /*
             * Keyed on the subscription and the target plan rather than on a
             * client-supplied value alone. A customer double-clicking confirm
             * must not resize their machine twice — the second press finds the
             * job the first one made.
             */
            idempotencyKey: sprintf(
                'plan-change:%s:%s:%s',
                $subscription->getKey(),
                $quote->planId,
                $idempotencyKey ?? 'default',
            ),
            provider: (string) ($machine->cluster()->first()?->driver->value ?? 'unknown'),
            serviceId: (string) $service->getKey(),
            customerId: $subscription->customer_id,
            payload: [
                'virtual_machine_id' => (string) $machine->getKey(),
                'subscription_id' => (string) $subscription->getKey(),
                'plan_id' => $quote->planId,
                // The target shape, absolute. The handler turns the disk into
                // a growth against what the machine actually has, which is the
                // only form the provider contract accepts.
                'vcpu' => $quote->newResources->vcpu,
                'memory_mib' => $quote->newResources->memoryMib,
                'disk_gib' => $quote->newResources->diskGib,
            ],
        ));

        if ($job->wasRecentlyCreated) {
            RunProvisioningJob::dispatch((string) $job->getKey());
        }

        return $job;
    }

    /**
     * The hosting half: the account moves onto the package the new plan names.
     *
     * A plan with no package behind it queues nothing rather than guessing.
     * Choosing "some package on the right node" would put a customer on a
     * quota nobody sold them, and the alternative — a plan change that says so
     * — is a support ticket rather than a silent wrong answer.
     */
    private function queuePackageChange(
        Subscription $subscription,
        Service $service,
        PlanChangeQuote $quote,
        ?string $idempotencyKey,
    ): ?ProvisioningJob {
        $account = HostingAccount::query()->where('service_id', $service->getKey())->first();

        if ($account === null) {
            return null;
        }

        $package = HostingPackage::query()->where('plan_id', $quote->planId)->first();

        if ($package === null) {
            return null;
        }

        $job = $this->createJob->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::ChangeHostingPackage,
            idempotencyKey: sprintf(
                'plan-change:%s:%s:%s',
                $subscription->getKey(),
                $quote->planId,
                $idempotencyKey ?? 'default',
            ),
            provider: $account->node()->first()?->panel->value ?? 'unknown',
            serviceId: (string) $service->getKey(),
            customerId: $subscription->customer_id,
            payload: [
                'hosting_account_id' => (string) $account->getKey(),
                'hosting_package_id' => (string) $package->getKey(),
                'subscription_id' => (string) $subscription->getKey(),
                'plan_id' => $quote->planId,
            ],
        ));

        if ($job->wasRecentlyCreated) {
            RunProvisioningJob::dispatch((string) $job->getKey());
        }

        return $job;
    }
}
