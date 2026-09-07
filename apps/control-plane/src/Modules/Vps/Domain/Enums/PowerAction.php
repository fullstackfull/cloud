<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Enums;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;

/**
 * The four things a customer may ask a running machine to do.
 *
 * `stop` and `shutdown` are separate cases and stay separate all the way down
 * to the hypervisor call. They are not two names for one operation:
 *
 *  - `shutdown` asks the guest, through ACPI or the guest agent, to shut
 *    itself down. The filesystem is flushed, the database closes its files,
 *    and the machine comes back next boot with nothing to replay.
 *  - `stop` cuts the power. It is what you use when the guest is wedged and
 *    cannot be asked, and it can lose every write that had not reached the
 *    disk.
 *
 * Collapsing them — mapping "stop" onto shutdown because it is safer, or
 * shutdown onto stop because it always works — silently gives a customer the
 * operation they did not ask for, and one of those two directions destroys
 * data.
 *
 * `reboot` is likewise the graceful restart (the guest is asked), not the hard
 * reset the provider also offers. No route publishes reset: a customer who
 * needs one wants `stop` followed by `start`, and saying so is two deliberate
 * requests rather than one ambiguous word.
 */
enum PowerAction: string
{
    case Start = 'start';
    case Stop = 'stop';
    case Reboot = 'reboot';
    case Shutdown = 'shutdown';

    /**
     * The provisioning kind this action is dispatched as.
     *
     * Stop and shutdown share a kind because the engine's vocabulary has one,
     * and it is not this module's enum to widen. The distinction is therefore
     * carried in the job payload and read back by the handler — never inferred
     * from the kind, which cannot tell them apart.
     */
    public function jobKind(): ProvisioningJobKind
    {
        return match ($this) {
            self::Start => ProvisioningJobKind::Start,
            self::Stop, self::Shutdown => ProvisioningJobKind::Stop,
            self::Reboot => ProvisioningJobKind::Restart,
        };
    }

    /**
     * Whether the guest is asked rather than told.
     */
    public function isGraceful(): bool
    {
        return match ($this) {
            self::Shutdown, self::Reboot => true,
            self::Start, self::Stop => false,
        };
    }

    /**
     * Whether performing this action risks losing writes the guest had not
     * yet flushed. Surfaced so a client can warn before it asks.
     */
    public function mayLoseData(): bool
    {
        return $this === self::Stop;
    }
}
