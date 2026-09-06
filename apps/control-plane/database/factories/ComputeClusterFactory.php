<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;

/**
 * @extends Factory<ComputeCluster>
 */
class ComputeClusterFactory extends Factory
{
    protected $model = ComputeCluster::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'datacenter_id' => Datacenter::factory(),
            'slug' => 'pve-'.Str::lower(Str::random(8)),
            'name' => 'Proxmox Cluster',
            // The fake by default: a test that wants to talk to a real
            // hypervisor has to say so, which is the direction the mistake
            // should be hard in.
            'driver' => ComputeDriver::Fake,
            'credentials_reference' => null,
            'api_endpoint' => null,
            'verify_tls' => true,
            'status' => ClusterStatus::Active,
        ];
    }

    public function proxmox(string $credentialsReference = 'test-cluster', string $endpoint = 'https://pve.test:8006'): static
    {
        return $this->state(fn (): array => [
            'driver' => ComputeDriver::Proxmox,
            'credentials_reference' => $credentialsReference,
            'api_endpoint' => $endpoint,
        ]);
    }

    public function status(ClusterStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * A lab cluster with a self-signed certificate — the only case in which
     * verification is legitimately off, and only for this cluster.
     */
    public function withoutTlsVerification(): static
    {
        return $this->state(fn (): array => ['verify_tls' => false]);
    }
}
