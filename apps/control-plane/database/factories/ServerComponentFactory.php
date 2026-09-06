<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;

/**
 * @extends Factory<ServerComponent>
 */
class ServerComponentFactory extends Factory
{
    protected $model = ServerComponent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dedicated_server_id' => DedicatedServer::factory(),
            'kind' => ComponentKind::Disk,
            'model' => 'INTEL SSDSC2KB960G8',
            'serial' => 'D'.Str::upper(Str::random(9)),
            'quantity' => 1,
            'attributes' => ['capacity_bytes' => 960197124096],
            'health' => ComponentHealth::Ok,
            'health_checked_at' => now(),
        ];
    }

    public function forServer(DedicatedServer $server): static
    {
        return $this->state(fn (): array => ['dedicated_server_id' => $server->getKey()]);
    }

    /**
     * The NIC a network install is authorised against.
     *
     * `pxe_enabled` is set because a machine with several NICs has exactly one
     * that is cabled to the provisioning VLAN, and picking the wrong one is a
     * machine that silently never boots.
     */
    public function nic(string $macAddress = 'aa:bb:cc:dd:ee:ff', bool $pxeEnabled = true): static
    {
        return $this->state(fn (): array => [
            'kind' => ComponentKind::Nic,
            'model' => 'Intel X710',
            'attributes' => ['mac_address' => $macAddress, 'pxe_enabled' => $pxeEnabled, 'speed_mbps' => 10000],
        ]);
    }

    public function kind(ComponentKind $kind): static
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }

    public function health(ComponentHealth $health): static
    {
        return $this->state(fn (): array => ['health' => $health]);
    }
}
