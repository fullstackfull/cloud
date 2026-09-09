<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Enums;

/**
 * How a GPU can be handed to a guest.
 *
 * These are not equivalent and the platform does not pretend they are. A
 * whole card passed through (VFIO) gives the guest the device and nothing
 * else on the host sees it. A vGPU or a MIG slice shares the silicon, with
 * the isolation the vendor's driver provides and no more. A plan that
 * promises a dedicated GPU is satisfied by the first only.
 */
enum GpuPassthroughMode: string
{
    /** The whole device, VFIO. Dedicated to one guest. */
    case PciPassthrough = 'pci_passthrough';

    /** A vendor-managed virtual GPU. Shared silicon. */
    case Vgpu = 'vgpu';

    /** A MIG partition. Shared silicon, hardware-partitioned. */
    case Mig = 'mig';

    /** The host cannot hand it to a guest at all. Counted, not capacity. */
    case None = 'none';

    /**
     * Whether a guest holding a device in this mode holds it alone.
     */
    public function isDedicated(): bool
    {
        return $this === self::PciPassthrough;
    }

    public function canBeAllocated(): bool
    {
        return $this !== self::None;
    }
}
