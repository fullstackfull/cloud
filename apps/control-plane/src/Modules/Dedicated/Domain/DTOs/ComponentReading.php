<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\DTOs;

use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;

/**
 * One part of a machine as the controller currently describes it.
 *
 * The identity fields are what make a reading useful a year later: a spare is
 * ordered against a model and a serial, and a warranty claim is made against a
 * serial that has to have been recorded before the part died. `name` is the
 * controller's own label for the slot ("Bay 3", "DIMM A1") and is what an
 * engineer standing in front of the rack matches against.
 *
 * @immutable
 */
final readonly class ComponentReading
{
    /**
     * @param  string  $name  The controller's label for this part. Also the identity a sync
     *                        matches on when the part has no serial, so it must be stable
     *                        across polls.
     * @param  array<string, mixed>  $attributes  Kind-specific detail — capacity, speed, MAC address —
     *                                            already redacted by the adapter.
     */
    public function __construct(
        public ComponentKind $kind,
        public string $name,
        public ComponentHealth $health = ComponentHealth::Unknown,
        public ?string $model = null,
        public ?string $serial = null,
        public int $quantity = 1,
        public array $attributes = [],
    ) {}

    /**
     * The MAC address this reading carries, normalised, or null.
     *
     * Lower-cased and colon-separated because vendors disagree: Redfish emits
     * "AA:BB:CC:DD:EE:FF", ipmitool prints "aa bb cc dd ee ff", and a PXE
     * authorisation compared against the wrong spelling of the right address
     * is a machine that silently will not boot.
     */
    public function macAddress(): ?string
    {
        $raw = $this->attributes['mac_address'] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $raw));

        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }
}
