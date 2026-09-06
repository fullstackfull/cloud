<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\DTOs;

use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;

/**
 * Everything one read of a controller says about the machine behind it.
 *
 * Health and power are returned together because they are read together and
 * are only meaningful together: "critical" on a machine that is powered off is
 * a stale reading from before it was shut down, while "critical" on a running
 * machine is an incident. Splitting them across two calls would let a caller
 * pair one with the other's stale answer.
 *
 * @immutable
 */
final readonly class HardwareHealth
{
    /**
     * @param  ComponentHealth  $overall  The controller's own verdict where it gives one, rather than a
     *                                    figure derived from the readings. A chassis knows about faults
     *                                    it does not expose as components.
     * @param  list<ComponentReading>  $components
     * @param  array<string, mixed>  $raw  The controller's payload, already redacted.
     */
    public function __construct(
        public ComponentHealth $overall,
        public PowerState $powerState,
        public ?string $manufacturer = null,
        public ?string $model = null,
        public ?string $serialNumber = null,
        public ?string $biosVersion = null,
        public array $components = [],
        public array $raw = [],
    ) {}

    /**
     * The overall verdict, degraded to the worst component if any component is
     * worse than the chassis admits.
     *
     * Taken from both because either alone lies in a direction that matters: a
     * chassis reporting OK while a drive reports Critical is a failing disk
     * nobody is told about, and a chassis reporting Critical with no component
     * to point at is still a machine an engineer has to look at.
     */
    public function effectiveHealth(): ComponentHealth
    {
        $health = $this->overall;

        foreach ($this->components as $component) {
            $health = $health->worseOf($component->health);
        }

        return $health;
    }

    /**
     * @return list<ComponentReading>
     */
    public function componentsOfKind(ComponentKind $kind): array
    {
        return array_values(array_filter(
            $this->components,
            static fn (ComponentReading $component): bool => $component->kind === $kind,
        ));
    }

    /**
     * The MAC address a network install should be authorised for.
     *
     * The NIC flagged for provisioning wins; failing that, the first NIC that
     * reports an address at all. Returning the first-listed NIC unconditionally
     * would be a coin toss on machines whose onboard ports are enumerated in
     * firmware order rather than in the order they are cabled.
     */
    public function provisioningMacAddress(): ?string
    {
        $fallback = null;

        foreach ($this->componentsOfKind(ComponentKind::Nic) as $nic) {
            $mac = $nic->macAddress();

            if ($mac === null) {
                continue;
            }

            if (($nic->attributes['pxe_enabled'] ?? false) === true) {
                return $mac;
            }

            $fallback ??= $mac;
        }

        return $fallback;
    }
}
