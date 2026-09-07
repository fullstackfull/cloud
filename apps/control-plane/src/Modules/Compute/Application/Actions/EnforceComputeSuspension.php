<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Makes a suspension true at the hypervisor, and says whether it is.
 *
 * ---------------------------------------------------------------------------
 * Why this returns a verdict rather than void
 * ---------------------------------------------------------------------------
 *
 * The platform must never mark a service suspended on the strength of having
 * asked. A provider that accepted the request and did nothing leaves a
 * customer's unpaid machine running while every screen says otherwise, which
 * is the exact failure this phase exists to close — so the caller gets back
 * what the provider actually reports, and decides.
 *
 * Verification re-reads the machine rather than trusting the call: Proxmox
 * answers a config PUT before the change has necessarily taken, and "I sent
 * it" is not "it is so".
 */
final readonly class EnforceComputeSuspension
{
    /**
     * Distinguishes "the provider says there is no such machine" from "the
     * provider could not be asked", which have opposite answers.
     */
    private const string PROVIDER_HAS_NO_SUCH_MACHINE = 'absent';

    public function __construct(
        private ComputeProviderFactory $providers,
    ) {}

    /**
     * @return bool whether the provider now reports the machine as suspended
     *
     * @throws ComputeProviderException
     */
    public function execute(Service $service, ?SuspensionPolicy $policy = null): bool
    {
        $policy ??= SuspensionPolicy::configured();

        $machine = $this->machineFor($service);

        if ($machine === null) {
            /*
             * No machine to suspend — a service still being built, or one that
             * is not compute at all. True rather than false: there is nothing
             * running that the customer could use, which is what the caller is
             * actually asking about.
             */
            return true;
        }

        $cluster = $machine->cluster()->first();
        $node = $machine->node()->first();

        if ($cluster === null || $node === null) {
            // Unplaced. Same reasoning as above.
            return true;
        }

        $provider = $this->providers->for($cluster);

        $nodeName = $node->provider_name;
        $providerId = (string) $machine->provider_id;

        /*
         * Read before the machine is touched, for two reasons.
         *
         * A customer who had deliberately powered their server down before
         * falling behind on payment must not find it running when they pay
         * again — and once the suspension has stopped it, the provider can no
         * longer tell the platform which of the two it was.
         *
         * And a machine the platform believes in but the provider has never
         * heard of is drift, not a suspension: suspending it would throw, and
         * the operator would get a provider error where what they need is the
         * missing machine on the drift report.
         */
        $known = $this->currentState($provider, $nodeName, $providerId);

        if ($known === self::PROVIDER_HAS_NO_SUCH_MACHINE) {
            return false;
        }

        if ($known !== null) {
            $this->rememberPowerState($service, $known);
        }

        $provider->suspendVm($nodeName, $providerId, $policy);

        if (! $policy->requiresProviderVerification()) {
            // record_only asks nothing of the provider, so there is nothing to
            // confirm; requiring confirmation would leave every suspension
            // waiting for a fact that does not exist.
            return true;
        }

        return $this->providerAgrees($provider, $nodeName, $providerId, $policy);
    }

    /**
     * Whether the provider's own view matches what the policy asked for.
     */
    private function providerAgrees(
        ComputeProvider $provider,
        string $nodeName,
        string $providerId,
        SuspensionPolicy $policy,
    ): bool {
        $state = $provider->getVm($nodeName, $providerId);

        if ($state === null) {
            // The machine is gone from the provider. That is drift, not a
            // successful suspension, and saying otherwise would close the case
            // on a customer's missing server.
            return false;
        }

        if ($policy->powersOff() && $state->powerState->isOn()) {
            return false;
        }

        return ! $policy->locksAtProvider() || $state->isLockedAtProvider();
    }

    /**
     * The provider's view, distinguishing "gone" from "could not ask".
     *
     * A read that throws is swallowed and the suspension goes ahead: not
     * knowing the previous power state makes for a worse reactivation, not a
     * failed suspension, and a provider that is hard to read is exactly the
     * situation where suspending matters most. A read that succeeds and finds
     * nothing is the other thing entirely, and gets its own answer.
     *
     * @return RemoteVmState|self::PROVIDER_HAS_NO_SUCH_MACHINE|null
     */
    private function currentState(
        ComputeProvider $provider,
        string $nodeName,
        string $providerId,
    ): RemoteVmState|string|null {
        try {
            $state = $provider->getVm($nodeName, $providerId);
        } catch (ComputeProviderException) {
            return null;
        }

        return $state ?? self::PROVIDER_HAS_NO_SUCH_MACHINE;
    }

    /**
     * Notes whether the machine was running, for the reactivation to honour.
     */
    private function rememberPowerState(Service $service, RemoteVmState $state): void
    {
        $resources = $service->resources;
        $resources['suspension'] = ['was_running' => $state->powerState->isOn()];

        $service->forceFill(['resources' => $resources])->save();
    }

    private function machineFor(Service $service): ?VirtualMachine
    {
        $machine = VirtualMachine::query()
            ->where('service_id', $service->getKey())
            ->whereNotNull('provider_id')
            ->first();

        return $machine;
    }
}
