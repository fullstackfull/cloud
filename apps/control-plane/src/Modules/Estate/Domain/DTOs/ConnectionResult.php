<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\DTOs;

use Lynomia\Modules\Estate\Domain\Enums\CapabilityState;
use Lynomia\Modules\Estate\Domain\Enums\ConnectionState;

/**
 * What a connection test learned.
 *
 * Carries three things, and the third is the reason this is a DTO rather than
 * just an enum: the capabilities the target admitted to. A test that
 * authenticates against Proxmox and reads the node list has learned that
 * inspection works and has learned nothing about whether this token may create
 * a VM — so capabilities come back as a map, with everything unasked left
 * Unknown rather than assumed.
 */
final readonly class ConnectionResult
{
    /**
     * @param  list<ConnectionStep>  $steps
     * @param  array<string, CapabilityState>  $capabilities
     */
    private function __construct(
        public ConnectionState $state,
        public array $steps,
        public array $capabilities = [],
        public ?string $detail = null,
    ) {}

    /**
     * @param  list<ConnectionStep>  $steps
     * @param  array<string, CapabilityState>  $capabilities
     */
    public static function of(
        ConnectionState $state,
        array $steps,
        array $capabilities = [],
        ?string $detail = null,
    ): self {
        return new self($state, $steps, $capabilities, $detail);
    }

    /**
     * @return list<array{name: string, outcome: string, detail?: string}>
     */
    public function stepsAsArray(): array
    {
        return array_map(static fn (ConnectionStep $step): array => $step->toArray(), $this->steps);
    }
}
