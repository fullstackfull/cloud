<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\DTOs;

/**
 * One piece of firmware the machine is running.
 *
 * Kept as an inventory rather than a single "firmware version" string because
 * a server runs a dozen independent images — BIOS, BMC, RAID controller, NIC,
 * each drive — and the ones that matter for a security advisory are rarely the
 * one an operator would have thought to record.
 *
 * @immutable
 */
final readonly class FirmwareComponent
{
    /**
     * @param  string  $id  The controller's own identifier for the image, stable across
     *                      polls, so that a version change is detectable.
     * @param  bool  $updateable  Whether the controller will accept a new image for this
     *                            component at all. A vulnerable component that cannot be
     *                            updated in place is a hardware replacement, not a patch.
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $version = null,
        public bool $updateable = false,
        public ?string $manufacturer = null,
    ) {}
}
