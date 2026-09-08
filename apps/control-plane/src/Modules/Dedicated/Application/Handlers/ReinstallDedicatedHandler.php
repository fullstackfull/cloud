<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Handlers;

use Illuminate\Support\Sleep;
use Lynomia\Modules\Dedicated\Application\Actions\AuthorisePxeBoot;
use Lynomia\Modules\Dedicated\Domain\Contracts\HostReachability;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\InstallProfileNotRenderableException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\PxeAuthorisationRefusedException;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Queries\PrimaryServerAddress;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Rebuilds a customer's physical machine.
 *
 * ---------------------------------------------------------------------------
 * Why this is not the provisioning handler with a flag
 * ---------------------------------------------------------------------------
 *
 * ProvisionDedicatedHandler turns a paid order into an installed server: it
 * reserves a machine nobody owns, allocates addresses, installs, and hands the
 * result over. Every one of those steps is wrong here. The machine is already
 * this customer's, its address is already assigned to it and already on their
 * invoice, and re-running any of that would produce a second reservation, a
 * second address, or a machine handed to somebody who already has it.
 *
 * What survives a rebuild, and is asserted to:
 *
 *  - the machine itself — same serial, same chassis, same rack;
 *  - the service mapping and the customer, untouched;
 *  - the address assignment in IPAM, and with it the MAC it was committed
 *    against — the rebuilt host comes up on the same address because the
 *    install profile is rendered with it;
 *  - the hardware, obviously, and therefore the specification the customer
 *    pays for.
 *
 * What does not survive: the disks. That is what was asked for.
 *
 * ---------------------------------------------------------------------------
 * The destructive boundary
 * ---------------------------------------------------------------------------
 *
 * Arming a one-time PXE override changes nothing on disk — the machine has to
 * boot for anything to happen. The power cycle is the act that starts the
 * installer, so the operation stamps `destructive_started_at` as it issues it,
 * and everything before that point can fail safely.
 *
 * The corollary is the ugliest case in this module, and it is handled
 * explicitly: an override that was armed and then could not be consumed is a
 * machine that will erase itself at its next reboot, whenever that is and
 * whoever causes it. When the power cycle is *refused* — the controller
 * answered and said no — the authorisation is revoked before returning. When
 * the controller stops answering instead, it cannot be revoked and must not be
 * assumed either way: the operation ends `indeterminate` and a person is told
 * that a live override may be sitting on that machine.
 *
 * ---------------------------------------------------------------------------
 * Verification
 * ---------------------------------------------------------------------------
 *
 * Three checks, and each is named for exactly what it proves. The
 * authorisation reports the installer finished. The controller reports the
 * machine is powered on. The host answers on its SSH port, which means it
 * booted and brought up its network on the address the platform gave it. What
 * is deliberately NOT claimed is an SSH login: the platform holds no key to a
 * customer's machine and should not.
 *
 * A machine that installed and does not answer is not called a failure. It is
 * `needs_review` — the rebuild happened, the customer's data is gone either
 * way, and an operator has to look before anybody says "ready".
 */
final readonly class ReinstallDedicatedHandler implements ProvisioningHandler
{
    private const int POLL_INTERVAL_SECONDS = 15;

    private const int FALLBACK_INSTALL_TIMEOUT_MINUTES = 90;

    /** How long to keep knocking before calling a rebuilt machine unreachable. */
    private const int FALLBACK_REACHABILITY_ATTEMPTS = 20;

    private const int SSH_PORT = 22;

    public function __construct(
        private AuthorisePxeBoot $authorisePxe,
        private DedicatedProviderFactory $providers,
        private DedicatedServerStateMachine $states,
        private HostReachability $reachability,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::ReinstallDedicated;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        $serverId = (string) ($payload['dedicated_server_id'] ?? '');
        $server = $serverId === '' ? null : DedicatedServer::query()->find($serverId);

        if ($server === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'dedicated.unknown_server',
                'The job names a dedicated server that no longer exists.',
                metadata: ['dedicated_server_id' => $serverId],
            );
        }

        $operation = $this->operationFor($job, $server);

        if ($operation->state->isTerminal()) {
            /*
             * A second delivery of a job that already finished. Answered
             * without touching the machine: on this hardware the alternative
             * is a customer's server erased twice because a queue redelivered
             * a message.
             */
            return ProvisioningResult::succeeded(
                remoteJobId: $operation->pxe_boot_authorisation_id,
                providerReference: $server->serial,
                metadata: [
                    'reinstall_state' => $operation->state->value,
                    'replayed' => true,
                ],
            );
        }

        if ($operation->destructive_started_at !== null) {
            /*
             * A redelivery of a job whose first attempt had already armed the
             * network installer and did not live to say what happened. The
             * machine may be mid-install right now, and telling a controller
             * to power-cycle it again would interrupt an install that is
             * writing partition tables.
             *
             * So nothing is touched and the operation ends indeterminate: on
             * hardware, "I do not know what this machine is doing" is a
             * question for a person with access to the console, not for
             * another automatic attempt.
             */
            $operation->recordFailure(
                DedicatedReinstallState::Indeterminate,
                'dedicated.reinstall_interrupted',
                'A previous attempt had already started the installer on this machine and did not report an outcome.',
            );

            return ProvisioningResult::failed(
                FailureClass::Timeout,
                'dedicated.reinstall_interrupted',
                'A previous attempt had already started the installer on this machine and did not report an outcome.',
                metadata: [
                    'dedicated_reinstall_id' => (string) $operation->getKey(),
                    'dedicated_server_id' => (string) $server->getKey(),
                    'reinstall_state' => $operation->state->value,
                ],
            );
        }

        $operation->advanceTo(DedicatedReinstallState::Validating);

        $profile = $this->profileFor($payload, $server);

        if ($profile === null) {
            return $this->refuse(
                $operation,
                DedicatedReinstallState::Failed,
                FailureClass::Permanent,
                'dedicated.install_profile_unavailable',
                'No active install profile was named for this rebuild, and the machine names none.',
            );
        }

        $endpoint = $server->preferredBmcEndpoint();

        if ($endpoint === null) {
            /*
             * Not a failure of the rebuild — a fact about the machine's
             * cabling or its records. Its own state, because "we could not
             * reach the controller" and "the install went wrong" send an
             * operator to two different places.
             */
            return $this->refuse(
                $operation,
                DedicatedReinstallState::HardwareUnavailable,
                FailureClass::Permanent,
                'dedicated.bmc_not_configured',
                'The machine has no management controller recorded, so nothing can tell it to reinstall.',
            );
        }

        $operation->forceFill([
            'bmc_endpoint_id' => (string) $endpoint->getKey(),
            'bmc_protocol' => $endpoint->protocol->value,
            'os_install_profile_id' => (string) $profile->getKey(),
            'os_install_profile_slug' => $profile->slug,
            'preserved' => $this->snapshot($server),
        ])->save();

        /*
         * The machine moves to `reinstalling` before anything is armed, and
         * that is what makes the PXE authorisation legal at all: a network
         * install is refused for a machine in `active`, deliberately, because
         * PXE on a running customer server is that server erased. The typed
         * confirmation upstream is what earns this transition.
         */
        $this->transition($server, DedicatedServerStatus::Reinstalling);

        $operation->advanceTo(DedicatedReinstallState::BmcConfiguring);

        try {
            $authorisation = $this->authorisePxe->execute(
                server: $server,
                profile: $profile,
                reason: (string) ($payload['install_reason'] ?? 'Customer-requested reinstall'),
                provisioningJobId: (string) $job->getKey(),
                variables: $this->installVariables($server, $payload),
            );
        } catch (PxeAuthorisationRefusedException|InstallProfileNotRenderableException $e) {
            // The request or the profile is wrong and will be next time too.
            // Nothing has been armed and nothing has booted.
            return $this->refuseAndRelease($server, $operation, DedicatedReinstallState::Failed, FailureClass::Permanent, $e->errorCode(), $e->getMessage(), $e->context());
        } catch (BmcNotConfiguredException $e) {
            return $this->refuseAndRelease($server, $operation, DedicatedReinstallState::HardwareUnavailable, FailureClass::Permanent, $e->errorCode(), $e->getMessage(), $e->context());
        } catch (DedicatedProviderException $e) {
            if ($e->isIndeterminate()) {
                /*
                 * The controller stopped answering while the override was
                 * being written. It may have taken — and an override that took
                 * and is never consumed erases this machine at its next
                 * reboot. Nothing automatic may clear it, because clearing an
                 * override that was never set is fine while assuming one was
                 * never set is how a machine reinstalls itself in three weeks.
                 */
                return $this->uncertain($operation, $e, 'a boot override may be armed on this machine and must be cleared by hand');
            }

            return $this->refuseAndRelease($server, $operation, DedicatedReinstallState::HardwareUnavailable, FailureClass::Transient, $e->errorCode(), $e->getMessage(), $e->context());
        }

        $operation->forceFill(['pxe_boot_authorisation_id' => (string) $authorisation->getKey()])->save();

        /*
         * The line, stamped before the call rather than after it.
         *
         * Written as a column rather than by advancing the state, and the
         * distinction matters. A worker killed between the controller
         * accepting a reset and this process writing anything must leave
         * behind a record that says the disks may already be gone — so the
         * stamp is pessimistic and goes first. The *state* only moves to
         * `pxe_booting` once the machine has actually been made to boot,
         * because that is a claim about what happened rather than about what
         * was attempted.
         *
         * The stamp is retracted in exactly one place: below, when the
         * controller answers and refuses. That is the only evidence strong
         * enough to say a machine definitely did not boot.
         */
        $operation->forceFill(['destructive_started_at' => now()])->save();

        try {
            $powerOperation = $this->powerCycle($server, $endpoint);
        } catch (DedicatedProviderException $e) {
            if ($e->isIndeterminate()) {
                // The reset may have happened. The machine may be erasing
                // itself right now. Nothing may touch it.
                return $this->uncertain($operation, $e, 'the machine may be installing right now');
            }

            /*
             * The controller answered and refused, so the machine did not
             * boot — but the override is armed and will fire at its next
             * reboot, whenever that is and whoever causes it. Revoked here,
             * which is the whole reason this branch is separate from the one
             * above: an override that cannot be revoked must never be assumed
             * cleared.
             */
            $authorisation->revoke();

            $this->transition($server, DedicatedServerStatus::Active);

            return $this->refuse(
                $operation,
                DedicatedReinstallState::HardwareUnavailable,
                FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                nothingBooted: true,
            );
        } catch (BmcNotConfiguredException $e) {
            $authorisation->revoke();

            $this->transition($server, DedicatedServerStatus::Active);

            return $this->refuse(
                $operation,
                DedicatedReinstallState::HardwareUnavailable,
                FailureClass::Permanent,
                $e->errorCode(),
                $e->getMessage(),
                nothingBooted: true,
            );
        }

        $operation->forceFill(['power_operation' => $powerOperation])->save();

        // Now it is true: the machine has been told to boot, into an
        // installer.
        $operation->advanceTo(DedicatedReinstallState::PxeBooting);

        $operation->advanceTo(DedicatedReinstallState::Installing);

        try {
            $outcome = $this->awaitInstallation($authorisation, $server);
        } catch (DedicatedProviderException $e) {
            return $this->uncertain($operation, $e, 'the platform lost sight of an install that had already started');
        }

        if ($outcome !== PxeAuthorisationStatus::Completed) {
            return $this->installDidNotComplete($server, $operation, $authorisation, $outcome);
        }

        $operation->advanceTo(DedicatedReinstallState::Configuring);

        $server->forceFill(['os_install_profile_id' => $profile->getKey()])->save();

        $operation->advanceTo(DedicatedReinstallState::Verifying);

        $verified = $this->cameBack($server, $endpoint);

        if (! $verified) {
            /*
             * The installer said it finished and the machine is not answering.
             * Not called a failure: the rebuild happened, and telling a
             * customer it failed when their server may simply be slow to boot
             * is its own kind of wrong. A person looks before anybody says
             * "ready".
             */
            $this->transition($server, DedicatedServerStatus::Maintenance);

            return $this->refuse(
                $operation,
                DedicatedReinstallState::NeedsReview,
                FailureClass::Permanent,
                'dedicated.reinstall_unverified',
                'The install reported success and the machine did not answer afterwards.',
            );
        }

        $this->transition($server, DedicatedServerStatus::Active);

        $operation->advanceTo(DedicatedReinstallState::Completed);

        return ProvisioningResult::succeeded(
            remoteJobId: (string) $authorisation->getKey(),
            providerReference: $server->serial,
            metadata: [
                'dedicated_reinstall_id' => (string) $operation->getKey(),
                'dedicated_server_id' => (string) $server->getKey(),
                'os_install_profile' => $profile->slug,
                'power_operation' => $powerOperation,
            ],
        );
    }

    /**
     * Start the install by making the machine boot.
     *
     * The branch is the same one ProvisionDedicatedHandler makes, and for the
     * same reason: the test is "is it definitely OFF", not "is it definitely
     * ON". Treating not-on as off would issue a power-on to a machine that is
     * already running, which does nothing, never consumes the one-time
     * override, and leaves it armed for some later unrelated reboot to pick
     * up — reinstalling the machine then, with nobody watching.
     *
     * @throws DedicatedProviderException
     * @throws BmcNotConfiguredException
     */
    private function powerCycle(DedicatedServer $server, BmcEndpoint $endpoint): string
    {
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
     * The authorisation row is the progress record — the boot server marks it
     * booted and then completed — so this polls the row rather than the
     * machine. The controller is probed on each pass as well, and that is the
     * point of the loop rather than a bonus: an install that finished and one
     * whose machine fell off the network look identical from the row alone,
     * and only the controller can tell them apart. A controller that stops
     * answering raises an indeterminate exception, which the caller turns into
     * a state nothing automatic will act on.
     *
     * @throws DedicatedProviderException
     */
    private function awaitInstallation(PxeBootAuthorisation $authorisation, DedicatedServer $server): PxeAuthorisationStatus
    {
        $timeoutMinutes = (int) config('dedicated.pxe.install_timeout_minutes', self::FALLBACK_INSTALL_TIMEOUT_MINUTES);
        $deadline = now()->addMinutes(max(1, $timeoutMinutes));

        $endpoint = $server->preferredBmcEndpoint();

        while (true) {
            $authorisation->refresh();

            if ($authorisation->status->isFinished()) {
                return $authorisation->status;
            }

            if ($authorisation->hasExpired() || now()->greaterThanOrEqualTo($deadline)) {
                return PxeAuthorisationStatus::Expired;
            }

            if ($endpoint !== null) {
                $this->providers->for($endpoint)->powerState($endpoint);
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }
    }

    /**
     * Whether the rebuilt machine is powered on and answering.
     *
     * Both halves are needed and neither is sufficient. A controller reporting
     * "on" says the chassis has power, not that an operating system booted; a
     * port answering says something booted, and on the address the platform
     * expects, which is the fact a customer cares about.
     */
    private function cameBack(DedicatedServer $server, BmcEndpoint $endpoint): bool
    {
        try {
            if ($this->providers->for($endpoint)->powerState($endpoint) === PowerState::Off) {
                return false;
            }
        } catch (DedicatedProviderException) {
            // The controller is unreachable after a successful install. That
            // is a management-network problem rather than a rebuilt machine
            // that failed, and the port check below is the better evidence.
        }

        $address = PrimaryServerAddress::for($server)?->ipAddress?->address;

        if ($address === null) {
            // Nothing to knock on. Reported as unverified rather than assumed
            // fine: a machine with no address the platform knows is a machine
            // the customer cannot reach either.
            return false;
        }

        $attempts = max(1, (int) config('dedicated.reinstall.reachability_attempts', self::FALLBACK_REACHABILITY_ATTEMPTS));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($this->reachability->answers($address, self::SSH_PORT)) {
                return true;
            }

            if ($attempt === $attempts) {
                break;
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }

        return false;
    }

    /**
     * The install ran and did not finish.
     */
    private function installDidNotComplete(
        DedicatedServer $server,
        DedicatedReinstall $operation,
        PxeBootAuthorisation $authorisation,
        PxeAuthorisationStatus $outcome,
    ): ProvisioningResult {
        /*
         * The machine goes to maintenance either way, and specifically not
         * back to `active`: its disks have been erased and whatever is on them
         * now is not what the customer had. Saying "active" would put a broken
         * machine back on their dashboard as though nothing happened.
         */
        $this->transition($server, DedicatedServerStatus::Maintenance);

        if ($outcome === PxeAuthorisationStatus::Expired) {
            return $this->refuse(
                $operation,
                DedicatedReinstallState::ProvisioningTimeout,
                FailureClass::Timeout,
                'dedicated.reinstall_timed_out',
                'The install did not report completion before the platform stopped waiting; the machine may still be installing.',
            );
        }

        return $this->refuse(
            $operation,
            DedicatedReinstallState::NeedsReview,
            FailureClass::Permanent,
            'dedicated.reinstall_install_failed',
            sprintf('The unattended install ended as "%s".', $outcome->value),
        );
    }

    /**
     * The platform stopped being able to see what it had started.
     *
     * Always a TIMEOUT, which the engine neither retries nor compensates, and
     * always with enough recorded to ask the hardware afterwards: the
     * authorisation, the controller, the machine and the service.
     */
    private function uncertain(DedicatedReinstall $operation, DedicatedProviderException $e, string $whatIsUnknown): ProvisioningResult
    {
        $operation->recordFailure(DedicatedReinstallState::Indeterminate, $e->errorCode(), $e->getMessage());

        return ProvisioningResult::failed(
            FailureClass::Timeout,
            $e->errorCode(),
            $e->getMessage(),
            remoteJobId: $operation->pxe_boot_authorisation_id,
            metadata: [
                ...$this->redactor->redact($e->context()),
                'dedicated_reinstall_id' => (string) $operation->getKey(),
                'dedicated_server_id' => $operation->dedicated_server_id,
                'service_id' => $operation->service_id,
                'pxe_boot_authorisation_id' => $operation->pxe_boot_authorisation_id,
                'unknown' => $whatIsUnknown,
                'quarantined' => true,
            ],
        );
    }

    /**
     * End the operation.
     *
     * `$nothingBooted` retracts the destructive stamp, and it is passed in
     * exactly one situation: a controller that answered and refused to boot
     * the machine. Everything else leaves the stamp alone, including every
     * case where the platform merely did not hear back — the safe assumption
     * about a machine that may have been told to erase itself is that it was.
     */
    private function refuse(
        DedicatedReinstall $operation,
        DedicatedReinstallState $state,
        FailureClass $class,
        string $code,
        string $message,
        bool $nothingBooted = false,
    ): ProvisioningResult {
        if ($nothingBooted) {
            $operation->forceFill(['destructive_started_at' => null])->save();
        }

        $operation->recordFailure($state, $code, $message);

        return ProvisioningResult::failed($class, $code, $message, metadata: [
            'dedicated_reinstall_id' => (string) $operation->getKey(),
            'dedicated_server_id' => $operation->dedicated_server_id,
        ]);
    }

    /**
     * The same, for a machine that had already been moved to `reinstalling`.
     *
     * It goes back to `active` because nothing was armed and nothing booted —
     * the customer's server is exactly as they left it, and leaving it in a
     * rebuild state would take a working machine off their dashboard.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function refuseAndRelease(
        DedicatedServer $server,
        DedicatedReinstall $operation,
        DedicatedReinstallState $state,
        FailureClass $class,
        string $code,
        string $message,
        array $context = [],
    ): ProvisioningResult {
        $this->transition($server, DedicatedServerStatus::Active);

        $operation->recordFailure($state, $code, $message);

        return ProvisioningResult::failed($class, $code, $message, metadata: [
            ...$this->redactor->redact($context),
            'dedicated_reinstall_id' => (string) $operation->getKey(),
            'dedicated_server_id' => (string) $server->getKey(),
        ]);
    }

    /**
     * What the rebuild is required not to change.
     *
     * @return array<string, scalar|null>
     */
    private function snapshot(DedicatedServer $server): array
    {
        return [
            'serial' => $server->serial,
            'service_id' => $server->service_id,
            'customer_id' => $server->customer_id,
            'primary_address' => PrimaryServerAddress::for($server)?->ipAddress?->address,
            'hostname' => $this->hostnameFor($server),
        ];
    }

    /**
     * The values the install profile is rendered with.
     *
     * The machine's own address, restated. IPAM is not touched by a rebuild:
     * the assignment that was live before is the one the rebuilt host comes up
     * on, and rendering the profile with anything else would install a machine
     * onto an address the platform did not allocate.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    private function installVariables(DedicatedServer $server, array $payload): array
    {
        /** @var array<string, mixed> $extra */
        $extra = is_array($payload['install_variables'] ?? null) ? $payload['install_variables'] : [];

        $scalars = array_filter(
            $extra,
            static fn (mixed $value): bool => is_scalar($value) || $value === null,
        );

        $assignment = PrimaryServerAddress::for($server);
        $address = $assignment?->ipAddress;
        $subnet = $address?->subnet;

        return [
            // The caller's extras go first so the platform's own values win.
            ...$scalars,
            'hostname' => $this->hostnameFor($server),
            'ipv4_address' => $address?->address,
            'ipv4_prefix_length' => $subnet?->prefix_length,
            'ipv4_gateway' => $subnet?->gateway,
        ];
    }

    /**
     * The name the machine comes back with.
     *
     * Taken from the install that put the machine into service, so a rebuild
     * does not silently rename a server the customer has DNS, monitoring and
     * runbooks pointing at. The serial is the fallback rather than a
     * generated name: it is the one identifier that is certainly this machine
     * and certainly stable.
     */
    private function hostnameFor(DedicatedServer $server): string
    {
        /** @var PxeBootAuthorisation|null $lastInstall */
        $lastInstall = $server->pxeAuthorisations()
            ->where('status', PxeAuthorisationStatus::Completed->value)
            ->orderByDesc('completed_at')
            ->first();

        $rendered = $lastInstall === null ? [] : ($lastInstall->rendered_config ?? []);
        $variables = is_array($rendered['variables'] ?? null) ? $rendered['variables'] : [];
        $hostname = $variables['hostname'] ?? null;

        return is_string($hostname) && $hostname !== '' ? $hostname : $server->serial;
    }

    /**
     * The image to lay down: the one asked for, or the one the machine already
     * runs. A withdrawn profile names nothing installable and is refused
     * rather than substituted.
     *
     * @param  array<string, mixed>  $payload
     */
    private function profileFor(array $payload, DedicatedServer $server): ?OsInstallProfile
    {
        $requested = isset($payload['os_install_profile_id']) ? (string) $payload['os_install_profile_id'] : null;

        /** @var OsInstallProfile|null $profile */
        $profile = OsInstallProfile::query()
            ->where('is_active', true)
            ->whereKey($requested ?? $server->os_install_profile_id ?? '')
            ->first();

        return $profile;
    }

    private function operationFor(ProvisioningJob $job, DedicatedServer $server): DedicatedReinstall
    {
        $existing = DedicatedReinstall::query()
            ->where('provisioning_job_id', $job->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var DedicatedReinstall $created */
        $created = DedicatedReinstall::query()->create([
            'dedicated_server_id' => $server->getKey(),
            'service_id' => $job->service_id,
            'customer_id' => $job->customer_id,
            'provisioning_job_id' => $job->getKey(),
            'state' => DedicatedReinstallState::Queued,
            'state_changed_at' => now(),
        ]);

        return $created;
    }

    private function transition(DedicatedServer $server, DedicatedServerStatus $to): void
    {
        if ($server->status === $to) {
            return;
        }

        $this->states->assertCanTransition($server->status, $to);

        $server->forceFill(['status' => $to])->save();
    }
}
