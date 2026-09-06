<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * What a machine is doing right now, as last observed.
 *
 * Unknown is a real state, not a null stand-in: it means the platform has not
 * heard from the hypervisor about this machine, which is different from
 * knowing it is stopped. Billing and reconciliation treat the two differently.
 */
enum PowerState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Paused = 'paused';
    case Suspended = 'suspended';
    case Unknown = 'unknown';

    /**
     * Map a Proxmox qemu status string onto the platform's vocabulary.
     *
     * Anything unrecognised becomes Unknown rather than a guess: reconciliation
     * reports drift from this value, and inventing "stopped" for a status we do
     * not understand would raise a false alarm on every machine.
     */
    public static function fromProxmox(?string $status): self
    {
        return match ($status) {
            'running' => self::Running,
            'stopped' => self::Stopped,
            'paused' => self::Paused,
            'suspended' => self::Suspended,
            default => self::Unknown,
        };
    }

    public function isOn(): bool
    {
        return $this === self::Running;
    }
}
