<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Gives a customer their machine back, and proves it before saying so.
 *
 * ---------------------------------------------------------------------------
 * The order matters
 * ---------------------------------------------------------------------------
 *
 * The lock is lifted first, then the machine is started. The other order
 * cannot work — a locked machine refuses a start, which is the entire point of
 * the lock — and getting it wrong would produce a reactivation that appears to
 * run and leaves the customer's server off.
 *
 * ---------------------------------------------------------------------------
 * Started, but only if it should be
 * ---------------------------------------------------------------------------
 *
 * A machine that was deliberately powered off by its owner before the
 * suspension must not come back running. The platform cannot distinguish those
 * two cases from the provider alone, so it records at suspension time whether
 * the machine was running, and honours that here. Absent that record — an old
 * suspension, a machine adopted mid-flight — it starts the machine, because a
 * customer who has just paid and finds their server off will open a ticket,
 * and the reverse costs them nothing but a stop.
 */
final readonly class LiftComputeSuspension
{
    public function __construct(
        private ComputeProviderFactory $providers,
    ) {}

    /**
     * @return bool whether the provider now reports the machine as usable again
     *
     * @throws ComputeProviderException
     */
    public function execute(Service $service, ?SuspensionPolicy $policy = null): bool
    {
        $policy ??= SuspensionPolicy::configured();

        $machine = VirtualMachine::query()
            ->where('service_id', $service->getKey())
            ->whereNotNull('provider_id')
            ->first();

        if ($machine === null) {
            return true;
        }

        $cluster = $machine->cluster()->first();
        $node = $machine->node()->first();

        if ($cluster === null || $node === null) {
            return true;
        }

        $provider = $this->providers->for($cluster);
        $nodeName = $node->provider_name;
        $providerId = (string) $machine->provider_id;

        $provider->liftSuspension($nodeName, $providerId);

        if (! $policy->requiresProviderVerification()) {
            // record_only never touched the provider, so there is nothing to
            // confirm and nothing standing between the customer and their
            // machine.
            return true;
        }

        $state = $provider->getVm($nodeName, $providerId);

        if ($state === null) {
            return false;
        }

        /*
         * The lock being gone is the property that matters: it is what stops
         * the customer using their machine. Power is deliberately not part of
         * the verdict — a start is a task that takes seconds to complete, and
         * refusing to call the service active until the guest has booted would
         * leave a paid-up customer suspended because their kernel is slow.
         *
         * It is checked *before* the start rather than after, because a lock
         * that is still there is a lock somebody else put on: a backup, a
         * migration, an operator mid-repair. Starting into it would fail
         * anyway, and the failure would surface as a provider exception on a
         * reactivation that has in fact only found a busy machine.
         */
        if ($state->isLockedAtProvider()) {
            return false;
        }

        if ($this->shouldStart($service) && $policy->powersOff()) {
            $provider->startVm($nodeName, $providerId);
        }

        return true;
    }

    /**
     * Whether this machine was running when it was suspended.
     */
    private function shouldStart(Service $service): bool
    {
        $resources = $service->resources;

        $wasRunning = $resources['suspension']['was_running'] ?? null;

        // Default true. A customer who has just paid and finds their server
        // off opens a ticket; the reverse costs a stop.
        return ! is_bool($wasRunning) || $wasRunning;
    }
}
