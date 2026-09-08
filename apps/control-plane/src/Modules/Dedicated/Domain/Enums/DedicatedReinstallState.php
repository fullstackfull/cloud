<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;

/**
 * Where a physical machine's rebuild has got to.
 *
 * ---------------------------------------------------------------------------
 * Why this is not the VPS reinstall's state list
 * ---------------------------------------------------------------------------
 *
 * A virtual reinstall is a config edit and a disk import against an API that
 * answers in milliseconds. A physical one is a boot override written into a
 * management controller, a power cycle, a machine that leaves the network for
 * twenty minutes while an installer runs, and a host that has to be found
 * again afterwards. The failure modes are not the same failure modes, and
 * collapsing them into one vocabulary would lose the two distinctions an
 * operator needs most:
 *
 *  - `hardware_unavailable` — the controller could not be reached at all. The
 *    machine was never told to do anything, so its disks are intact. This is a
 *    cable, a firmware hang, or a BMC on a management network that is having a
 *    bad day, and it is not the same thing as a failed install.
 *  - `provisioning_timeout` — the installer was started and did not report
 *    back before the platform stopped waiting. The disks are almost certainly
 *    already overwritten. Nothing may restart it automatically.
 *
 * ---------------------------------------------------------------------------
 * The destructive boundary
 * ---------------------------------------------------------------------------
 *
 * Arming a one-time PXE override changes nothing on disk: the machine has to
 * boot for anything to happen. The power cycle is the act that starts the
 * installer, so `pxe_booting` is the first state on the far side of the line —
 * and the operation stamps `destructive_started_at` when it enters. Before
 * that point a failure is safely retryable and the customer still has their
 * server. After it, no automatic step may touch the machine again.
 */
enum DedicatedReinstallState: string
{
    /** Accepted, confirmed by the customer, and recorded. Nothing asked of anyone. */
    case Requested = 'requested';

    /** Handed to the queue. Still nothing at the controller. */
    case Queued = 'queued';

    /** Checking the machine, its service, its controller and its install profile. */
    case Validating = 'validating';

    /** Writing the one-time boot override into the management controller. */
    case BmcConfiguring = 'bmc_configuring';

    /** The power cycle has been issued. Past this line the disks may be gone. */
    case PxeBooting = 'pxe_booting';

    /** The installer is running and reporting progress. */
    case Installing = 'installing';

    /** Installed; identity, network and keys being written back. */
    case Configuring = 'configuring';

    /** Asking whether the machine came back and answers. */
    case Verifying = 'verifying';

    case Completed = 'completed';

    /** The platform knows what happened and knows it is over. */
    case Failed = 'failed';

    /** The controller could not be reached. Nothing was told to happen. */
    case HardwareUnavailable = 'hardware_unavailable';

    /** The installer was started and never reported back in time. */
    case ProvisioningTimeout = 'provisioning_timeout';

    /** The platform stopped waiting for a controller mid-operation. */
    case Indeterminate = 'indeterminate';

    /** A person has to decide before anything else touches this machine. */
    case NeedsReview = 'needs_review';

    /**
     * Whether being in this state, by itself, means the installer has started.
     *
     * `failed` answers false and it is the interesting case: a reinstall can
     * fail while validating — no profile, no controller, a suspended service —
     * and it can fail after the machine has been power-cycled into an
     * installer. The state alone cannot tell those apart, so it does not
     * pretend to; the operation's `destructive_started_at` stamp can, and
     * {@see DedicatedReinstall::destroyedData()}
     * is what callers ask.
     */
    public function impliesDestroyedData(): bool
    {
        return match ($this) {
            self::Requested, self::Queued, self::Validating,
            self::BmcConfiguring, self::Failed, self::HardwareUnavailable => false,
            // Everything from the power cycle onwards. `provisioning_timeout`
            // and `indeterminate` count as destroyed because the safe
            // assumption about a machine that may be mid-install is that it is.
            self::PxeBooting, self::Installing, self::Configuring, self::Verifying,
            self::Completed, self::ProvisioningTimeout, self::Indeterminate, self::NeedsReview => true,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::HardwareUnavailable,
            self::ProvisioningTimeout, self::Indeterminate, self::NeedsReview => true,
            default => false,
        };
    }

    /**
     * Whether a person has to look before anything else touches this machine.
     *
     * Both of the uncertain endings and the explicit review state. A plain
     * failure and an unreachable controller do not qualify: the first is over
     * and the second never started, so the customer can simply ask again.
     */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::NeedsReview, self::Indeterminate, self::ProvisioningTimeout => true,
            default => false,
        };
    }

    public function isInFlight(): bool
    {
        return ! $this->isTerminal();
    }
}
