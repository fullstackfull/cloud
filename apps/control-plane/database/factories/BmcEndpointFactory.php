<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;

/**
 * @extends Factory<BmcEndpoint>
 */
class BmcEndpointFactory extends Factory
{
    protected $model = BmcEndpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dedicated_server_id' => DedicatedServer::factory(),
            'protocol' => BmcProtocol::Redfish,
            // RFC 5737 documentation space, and a management range at that: a
            // factory must never produce an address that could resolve to
            // somebody's real controller.
            'address' => '192.0.2.'.fake()->numberBetween(2, 254),
            'port' => null,
            'username' => 'lynomia-svc',
            'credentials_reference' => 'test-bmc',
            // On by default, matching the only acceptable production setting.
            'verify_tls' => true,
        ];
    }

    public function forServer(DedicatedServer $server): static
    {
        return $this->state(fn (): array => ['dedicated_server_id' => $server->getKey()]);
    }

    public function protocol(BmcProtocol $protocol): static
    {
        return $this->state(fn (): array => ['protocol' => $protocol]);
    }

    public function at(string $address): static
    {
        return $this->state(fn (): array => ['address' => $address]);
    }
}
