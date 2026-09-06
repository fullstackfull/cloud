<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Handlers;

use Illuminate\Support\Sleep;
use Lynomia\Modules\Dedicated\Application\Actions\AuthorisePxeBoot;
use Lynomia\Modules\Dedicated\Application\Actions\ReserveDedicatedServer;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\InstallProfileNotRenderableException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\NoMatchingHardwareException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\PxeAuthorisationRefusedException;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Turns a paid order into an installed physical server.
 *
 * The order of the steps is the design, and it is the same principle as the
 * VPS handler with one difference that changes everything: nothing here is
 * created, so the irreversible step is not "a machine now exists" but "a
 * machine's disks have been erased".
 *
 *  1. **Reserve a machine** — a row lock. Cheap, and undoable.
 *  2. **Reserve addresses** — rows. Released or quarantined by compensation.
 *  3. **Authorise one-time PXE** — a recorded decision, then a boot override
 *     armed for exactly one boot.
 *  4. **Power cycle** — the first step whose effect is physical.
 *  5. **Wait for the install** — tens of minutes, over which the machine
 *     erases and rebuilds itself.
 *  6. **Mark active and commit the addresses** — bookkeeping that follows a
 *     machine that already exists.
 *
 * Doing step 4 before steps 1 to 3 would reinstall a machine that nobody had
 * reserved, that had no address to come up on and no record of why.
 *
 * Failures are classified exactly as CreateVpsHandler classifies them, because
 * the engine's retry and compensation behaviour depends entirely on the class
 * and getting it wrong here is a customer's server reinstalled twice:
 *
 *  - **no free machine → Capacity.** Nothing was built and nothing was
 *    touched; hardware is racked, freed and reassigned, so the condition
 *    genuinely resolves with time. Permanent would fail the order and refund a
 *    customer who was content to wait; the metadata carries the manual-review
 *    disposition so the order goes to an operator rather than round a retry
 *    loop for ever.
 *  - **exhausted address pool → Capacity**, for the same reason.
 *  - **the server is not in a state to be installed → Permanent.** The request
 *    is wrong and will be just as wrong next time.
 *  - **a controller that refused out loud → Transient.** It answered; nothing
 *    happened.
 *  - **a controller that stopped answering → TIMEOUT.** This is the one that
 *    matters. A BMC that goes quiet mid-install has not told us the install
 *    stopped; the machine is very likely still erasing and rebuilding itself.
 *    The engine neither retries nor releases resources for a timeout, and this
 *    handler additionally leaves the server in `provisioning` — returning it to
 *    stock would hand a half-installed machine, carrying whatever the installer
 *    has written so far, to the next customer.
 */
final readonly class ProvisionDedicatedHandler implements ProvisioningHandler
{
    /** How long to leave between polls of the install's progress. */
    private const int POLL_INTERVAL_SECONDS = 15;

    private const int FALLBACK_INSTALL_TIMEOUT_MINUTES = 90;

    public function __construct(
        private ReserveDedicatedServer $reserveServer,
        private AuthorisePxeBoot $authorisePxe,
        private IpAllocator $ipAllocator,
        private DedicatedProviderFactory $providers,
        private DedicatedServerStateMachine $states,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::ProvisionDedicated;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        try {
            $server = $this->reserveServer->execute(
                hardwareProfile: (string) ($payload['hardware_profile'] ?? ''),
                datacenterId: (string) ($payload['datacenter_id'] ?? ''),
                orderId: $job->order_id,
                customerId: $job->customer_id,
                serviceId: $job->service_id,
            );
        } catch (NoMatchingHardwareException $e) {
            /*
             * Capacity, not permanent, and the disposition travels with it.
             *
             * A dedicated server already exists or it does not, and no amount
             * of retrying conjures another one — but an operator racking a
             * machine, or a termination freeing one, resolves this within a
             * day. Refunding instead is the outcome the platform explicitly
             * rejects: a customer content to wait is worth more than a refund.
             */
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: [...$this->redactor->redact($e->context()), 'requires_manual_review' => true],
            );
        }

        // The machine is ours; from here it is being built rather than held.
        $this->transition($server, DedicatedServerStatus::Provisioning);

        try {
            $reservations = $this->ipAllocator->reserve(
                scope: IpPool::query()->findOrFail((string) $payload['ip_pool_id']),
                provisioningJobId: (string) $job->getKey(),
                customer: $job->customer_id !== null ? Customer::query()->find($job->customer_id) : null,
                count: (int) ($payload['ipv4_count'] ?? 1),
            );
        } catch (IpPoolExhaustedException $e) {
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $primary = $reservations[0];
        $address = $primary->ipAddress()->firstOrFail();

        /** @var OsInstallProfile $profile */
        $profile = OsInstallProfile::query()->findOrFail((string) $payload['os_install_profile_id']);

        try {
            $authorisation = $this->authorisePxe->execute(
                server: $server,
                profile: $profile,
                reason: (string) ($payload['install_reason'] ?? 'Initial provisioning for order '.($job->order_id ?? $job->getKey())),
                authorisedByUserId: isset($payload['authorised_by_user_id']) ? (string) $payload['authorised_by_user_id'] : null,
                provisioningJobId: (string) $job->getKey(),
                variables: [
                    'hostname' => (string) ($payload['hostname'] ?? 'srv-'.strtolower((string) $job->getKey())),
                    'ipv4_address' => $address->address,
                    'ipv4_prefix_length' => $address->subnet->prefix_length,
                    'ipv4_gateway' => $address->subnet->gateway,
                    ...$this->profileVariables($payload),
                ],
            );
        } catch (PxeAuthorisationRefusedException|InstallProfileNotRenderableException $e) {
            // The request itself is wrong — a machine in the wrong state, a
            // profile with a hole in it — and will be just as wrong next time.
            return $this->failWithoutReleasing($server, FailureClass::Permanent, $e->errorCode(), $e->getMessage(), $e->context());
        } catch (BmcNotConfiguredException $e) {
            // A machine with no reachable controller cannot be installed by any
            // amount of retrying; an operator has to configure or cable it.
            return $this->failWithoutReleasing($server, FailureClass::Permanent, $e->errorCode(), $e->getMessage(), $e->context());
        } catch (DedicatedProviderException $e) {
            return $this->classifyProviderFailure($server, $e, remoteJobId: null);
        }

        try {
            $powerOperation = $this->powerCycle($server);
        } catch (DedicatedProviderException $e) {
            /*
             * A power cycle that timed out is the sharpest case in the module.
             * The controller may have reset the machine, which means the
             * install it was armed for is running right now. Retrying would
             * reset a machine mid-install; releasing the server would offer a
             * half-erased machine to the next order.
             */
            return $this->classifyProviderFailure($server, $e, remoteJobId: (string) $authorisation->getKey());
        } catch (BmcNotConfiguredException $e) {
            return $this->failWithoutReleasing($server, FailureClass::Permanent, $e->errorCode(), $e->getMessage(), $e->context());
        }

        try {
            $outcome = $this->awaitInstallation($authorisation);
        } catch (DedicatedProviderException $e) {
            return $this->classifyProviderFailure($server, $e, remoteJobId: (string) $authorisation->getKey());
        }

        if ($outcome !== PxeAuthorisationStatus::Completed) {
            return $this->installDidNotComplete($server, $authorisation, $outcome);
        }

        $this->transition($server, DedicatedServerStatus::Active);

        $server->forceFill([
            'service_id' => $job->service_id,
            'customer_id' => $job->customer_id,
            'reserved_until' => null,
        ])->save();

        // Addresses are committed only now, against a machine that is actually
        // installed. Committing earlier would leave an assignment pointing at a
        // machine that never came up.
        foreach ($reservations as $reservation) {
            $this->ipAllocator->commit(
                reservation: $reservation,
                serviceId: $job->service_id,
                macAddress: $authorisation->mac_address,
                assignable: $server,
            );
        }

        return ProvisioningResult::succeeded(
            remoteJobId: (string) $authorisation->getKey(),
            providerReference: $server->serial,
            metadata: [
                'dedicated_server_id' => (string) $server->getKey(),
                'serial' => $server->serial,
                'primary_ipv4' => $address->address,
                'os_install_profile' => $profile->slug,
                'power_operation' => $powerOperation,
            ],
        );
    }

    /**
     * Start the install by making the machine boot.
     *
     * The branch matters. A machine that is off is powered on, which consumes
     * the one-time override on the way up. A machine that is on is reset,
     * because a running host has no reason to reboot itself and a graceful
     * shutdown would ask an operating system that may not be there. A machine
     * whose power state is anything else — UNKNOWN, or a transitional
     * PoweringOn/PoweringOff — is reset, deliberately the same as "on". The
     * test is therefore "is it definitely OFF", not "is it definitely ON":
     * the alternative reading, treating not-on as off and issuing power-on,
     * does nothing at all to a machine that is already running, so the
     * one-time override is never consumed, the install silently never starts,
     * and the override stays armed until some later unrelated reboot picks it
     * up and reinstalls the machine then.
     */
    private function powerCycle(DedicatedServer $server): string
    {
        $endpoint = $server->preferredBmcEndpoint();

        if ($endpoint === null) {
            throw BmcNotConfiguredException::noEndpoint((string) $server->getKey());
        }

        $provider = $this->providers->for($endpoint);

        $operation = $provider->powerState($endpoint) === PowerState::Off
            ? $provider->powerOn($endpoint)
            : $provider->reset($endpoint);

        $server->forceFill([
            'power_state' => $operation->resultingPowerState ?? $server->power_state,
        ])->save();

        return $operation->operation;
    }

    /**
     * Wait for the unattended install to finish.
     *
     * The authorisation row is the progress record: the boot server marks it
     * booted when the machine asks to install and completed when the installer
     * reports success, so this polls the row rather than the machine.
     *
     * The controller is probed on each pass anyway, and that is the point of
     * the loop rather than a bonus. An install that has genuinely finished and
     * an install whose machine has fallen off the network look identical from
     * the row alone — both are "not completed yet" — and only the BMC can tell
     * them apart. A controller that stops answering raises an indeterminate
     * provider exception, which the caller classifies as a TIMEOUT: the
     * platform stopped being able to see the install, not the install stopped.
     */
    private function awaitInstallation(PxeBootAuthorisation $authorisation): PxeAuthorisationStatus
    {
        $timeoutMinutes = (int) config('dedicated.pxe.install_timeout_minutes', self::FALLBACK_INSTALL_TIMEOUT_MINUTES);
        $deadline = now()->addMinutes(max(1, $timeoutMinutes));

        $server = $authorisation->server()->firstOrFail();
        $endpoint = $server->preferredBmcEndpoint();

        while (true) {
            $authorisation->refresh();

            if ($authorisation->status->isFinished()) {
                return $authorisation->status;
            }

            if ($authorisation->hasExpired() || now()->greaterThanOrEqualTo($deadline)) {
                /*
                 * Out of time with the machine still installing. Reported as
                 * Expired rather than Failed, because the two demand opposite
                 * handling: a failed install is a fact about the hardware, an
                 * expired window is a fact about our patience — and the machine
                 * may still be mid-install, which is why the caller treats this
                 * as a timeout and quarantines rather than releases.
                 */
                return PxeAuthorisationStatus::Expired;
            }

            if ($endpoint !== null) {
                // Throws, indeterminate, if the controller has gone quiet.
                // Deliberately not caught here: only the caller knows how to
                // classify it, and swallowing it would turn a machine we have
                // lost sight of into one we report as merely slow.
                $this->providers->for($endpoint)->powerState($endpoint);
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }
    }

    /**
     * The install ran and did not finish.
     *
     * Classified Permanent and the machine marked failed, which is the
     * uncomfortable but correct call. On physical hardware an install that
     * fails after the machine booted is usually a fact about the machine — a
     * dead disk, a failing DIMM, a NIC that dropped off the provisioning VLAN
     * — and a retry against the same box repeats it, having erased the disks a
     * second time. An operator decides between repair and replacement, and a
     * replacement is a different machine with its own provisioning cycle.
     *
     * The exception is running out of time, which says nothing about the
     * hardware: that is a timeout, and the machine is quarantined rather than
     * failed.
     *
     * @param  PxeAuthorisationStatus  $outcome  How the authorisation ended.
     */
    private function installDidNotComplete(
        DedicatedServer $server,
        PxeBootAuthorisation $authorisation,
        PxeAuthorisationStatus $outcome,
    ): ProvisioningResult {
        if ($outcome === PxeAuthorisationStatus::Expired) {
            /*
             * Left in `provisioning`, not returned to stock and not marked
             * failed. The installer may still be running: the platform stopped
             * waiting, the machine did not stop erasing itself. Handing this
             * box to the next order is how a customer receives a machine
             * carrying another customer's half-written filesystem.
             */
            return ProvisioningResult::failed(
                FailureClass::Timeout,
                'dedicated.install_timed_out',
                'The unattended install did not report completion before the platform stopped waiting; '
                .'the machine may still be installing and must be checked by hand.',
                remoteJobId: (string) $authorisation->getKey(),
                metadata: [
                    'dedicated_server_id' => (string) $server->getKey(),
                    'pxe_boot_authorisation_id' => (string) $authorisation->getKey(),
                    'quarantined' => true,
                ],
            );
        }

        $this->transition($server, DedicatedServerStatus::Failed);

        return ProvisioningResult::failed(
            FailureClass::Permanent,
            'dedicated.install_failed',
            sprintf('The unattended install ended as "%s".', $outcome->value),
            remoteJobId: (string) $authorisation->getKey(),
            metadata: [
                'dedicated_server_id' => (string) $server->getKey(),
                'pxe_boot_authorisation_id' => (string) $authorisation->getKey(),
                'authorisation_status' => $outcome->value,
            ],
        );
    }

    /**
     * Translate a controller failure into the engine's vocabulary.
     *
     * The adapter's own verdict on whether the request may still be in flight
     * is what decides the class, and it is the only thing that can know: it saw
     * the transport. A refusal spoken out loud is transient — nothing happened,
     * try again. A request that stopped being waited for is a TIMEOUT, which
     * the engine neither retries nor releases resources for.
     */
    private function classifyProviderFailure(
        DedicatedServer $server,
        DedicatedProviderException $e,
        ?string $remoteJobId,
    ): ProvisioningResult {
        if (! $e->isIndeterminate()) {
            /*
             * The controller answered and declined, so nothing physical
             * happened. Transient: the engine retries, and the retry finds this
             * same machine because the hold is keyed to the order — a second
             * attempt therefore continues with the box it started on rather
             * than reserving another one.
             *
             * The machine is deliberately NOT put back into stock here, and
             * that is the point of the whole transition table: once a machine
             * has entered `provisioning` the platform has begun a process whose
             * physical effect it cannot always observe, so it never returns one
             * to the shelf on its own. An operator clears it through `failed`,
             * which is the step that makes somebody look at the disks.
             */
            return ProvisioningResult::failed(
                FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                remoteJobId: $remoteJobId,
                metadata: [...$this->redactor->redact($e->context()), 'held_for_retry' => true],
            );
        }

        /*
         * Indeterminate: the machine stays exactly where it is.
         *
         * Not released, not marked failed, not retried. The controller may have
         * accepted a reset, and the machine may be erasing itself right now.
         * Every "helpful" alternative is worse: releasing it sells a machine
         * that is mid-install, marking it failed sends an engineer to a machine
         * that is fine, and retrying resets it a second time.
         */
        return ProvisioningResult::failed(
            FailureClass::Timeout,
            $e->errorCode(),
            $e->getMessage(),
            remoteJobId: $remoteJobId,
            metadata: [...$this->redactor->redact($e->context()), 'quarantined' => true],
        );
    }

    /**
     * Fail without changing the machine's state.
     *
     * The hardware is not at fault — the request or the configuration is — so
     * it is NOT marked failed: flagging a healthy machine takes a sellable box
     * out of inventory and sends an engineer to a rack for nothing.
     *
     * Neither is it returned to stock. A machine that has entered
     * `provisioning` stays there until a person decides what happened to it,
     * because the platform cannot always tell from outside whether an installer
     * has begun writing to the disks, and the one irreversible mistake
     * available here is handing a half-erased machine to the next customer. The
     * hold stays attached to the order, so the row says exactly which order
     * left it in this state.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function failWithoutReleasing(
        DedicatedServer $server,
        FailureClass $class,
        string $errorCode,
        string $message,
        array $context,
    ): ProvisioningResult {
        return ProvisioningResult::failed(
            $class,
            $errorCode,
            $message,
            metadata: [
                ...$this->redactor->redact($context),
                'dedicated_server_id' => (string) $server->getKey(),
                'held_for_review' => true,
            ],
        );
    }

    private function transition(DedicatedServer $server, DedicatedServerStatus $to): void
    {
        // Nothing in this module writes the status column without asking first;
        // the state machine is the single statement of what may follow what.
        $this->states->assertCanTransition($server->status, $to);

        $server->forceFill(['status' => $to])->save();
    }

    /**
     * Values the install profile may need, taken from the job payload.
     *
     * Filtered to scalars: a nested structure written into an answer file
     * renders as the word "Array", which an installer reads as a hostname.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    private function profileVariables(array $payload): array
    {
        /** @var array<string, mixed> $variables */
        $variables = is_array($payload['install_variables'] ?? null) ? $payload['install_variables'] : [];

        return array_filter(
            $variables,
            static fn (mixed $value): bool => is_scalar($value) || $value === null,
        );
    }
}
