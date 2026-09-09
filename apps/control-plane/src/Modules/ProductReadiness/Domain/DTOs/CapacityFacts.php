<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\DTOs;

use Lynomia\Modules\ProductReadiness\Domain\Enums\HardwareRequirement;

/**
 * What the physical machines can offer, counted, for the products that need
 * more than a provider's word.
 *
 * A projection like {@see ProviderFacts}: the engine stays a pure function
 * and the counting happens once, in the action that loads it.
 */
final readonly class CapacityFacts
{
    /**
     * @param  int  $gpuDevicesAvailable  GPU devices registered on a managed machine, in the available state.
     */
    public function __construct(
        public int $gpuDevicesAvailable = 0,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function satisfies(HardwareRequirement $requirement): bool
    {
        return match ($requirement) {
            HardwareRequirement::GpuDevice => $this->gpuDevicesAvailable > 0,
        };
    }

    public function describeMissing(HardwareRequirement $requirement): string
    {
        return match ($requirement) {
            HardwareRequirement::GpuDevice => 'No GPU device is registered as available on any managed machine.',
        };
    }
}
