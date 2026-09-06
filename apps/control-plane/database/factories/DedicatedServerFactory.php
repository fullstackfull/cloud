<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;

/**
 * @extends Factory<DedicatedServer>
 */
class DedicatedServerFactory extends Factory
{
    protected $model = DedicatedServer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'datacenter_id' => Datacenter::factory(),
            'rack_id' => null,
            'manufacturer' => 'HPE',
            'model' => 'ProLiant DL360 Gen10',
            // Serial is unique in the schema, as it is on real hardware.
            'serial' => 'SN'.Str::upper(Str::random(10)),
            'asset_tag' => 'AT'.Str::upper(Str::random(6)),
            'rack_unit' => 10,
            'height_units' => 1,
            'hardware_profile' => 'ded-standard-1',
            'status' => DedicatedServerStatus::Available,
            // Freshly racked machines are off, which is the state that makes
            // the provisioning handler's power-on branch the default one.
            'power_state' => PowerState::Off,
        ];
    }

    public function inDatacenter(Datacenter $datacenter): static
    {
        return $this->state(fn (): array => ['datacenter_id' => $datacenter->getKey()]);
    }

    public function inRack(Rack $rack, ?int $unit = null): static
    {
        return $this->state(fn (): array => [
            'rack_id' => $rack->getKey(),
            'datacenter_id' => $rack->datacenter_id,
            'rack_unit' => $unit ?? 10,
        ]);
    }

    public function profile(string $hardwareProfile): static
    {
        return $this->state(fn (): array => ['hardware_profile' => $hardwareProfile]);
    }

    public function status(DedicatedServerStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * A decommissioned machine, with the timestamp that says when.
     *
     * Both columns are set together because a retired row with no retired_at
     * is a machine whose decommissioning date has been lost, which is half of
     * what the row survives to answer.
     */
    public function retired(): static
    {
        return $this->state(fn (): array => [
            'status' => DedicatedServerStatus::Retired,
            'retired_at' => now()->subMonths(6),
        ]);
    }

    public function poweredOn(): static
    {
        return $this->state(fn (): array => ['power_state' => PowerState::On]);
    }
}
