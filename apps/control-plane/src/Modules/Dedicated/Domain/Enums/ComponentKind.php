<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

/**
 * The parts of a machine the platform tracks individually.
 *
 * Tracked separately rather than as a text blob because a component is the
 * unit a failure happens to: a customer's incident says "disk 3 in bay 5 of
 * serial X", and a spare is ordered against a model and a serial that have to
 * have been recorded before the part died.
 */
enum ComponentKind: string
{
    case Cpu = 'cpu';
    case Memory = 'memory';
    case Disk = 'disk';
    case RaidController = 'raid_controller';
    case Nic = 'nic';
    case PowerSupply = 'power_supply';
    case Fan = 'fan';
    case Gpu = 'gpu';

    /**
     * Whether this kind carries a MAC address worth recording.
     *
     * The provisioning VLAN's DHCP server is told which MAC may boot, so a
     * NIC's address is not decoration: it is the identity a PXE authorisation
     * is granted to.
     */
    public function carriesMacAddress(): bool
    {
        return $this === self::Nic;
    }

    /**
     * Whether losing one of these takes the machine, or the customer's data,
     * with it.
     *
     * A failed fan or a failed power supply in a redundant pair is a work
     * order; a failed disk or DIMM is an incident on a customer's service.
     */
    public function isSinglePointOfFailure(): bool
    {
        return match ($this) {
            self::Cpu, self::Memory, self::Disk, self::RaidController => true,
            default => false,
        };
    }
}
