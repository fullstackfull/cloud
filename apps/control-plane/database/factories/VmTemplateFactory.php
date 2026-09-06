<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;

/**
 * @extends Factory<VmTemplate>
 */
class VmTemplateFactory extends Factory
{
    protected $model = VmTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cluster_id' => ComputeCluster::factory(),
            'slug' => 'debian-13-'.Str::lower(Str::random(6)),
            'name' => ['en' => 'Debian 13', 'ar' => 'ديبيان 13'],
            'os_family' => OsFamily::Debian,
            'os_version' => '13',
            'architecture' => CpuArchitecture::X86_64,
            'provider_reference' => 'local:import/debian-13-genericcloud-amd64.qcow2',
            'checksum' => str_repeat('a', 64),
            'checksum_algorithm' => 'sha256',
            'cloud_init' => true,
            'guest_agent' => true,
            'requires_licence' => false,
            'is_active' => true,
        ];
    }

    public function windows(): static
    {
        return $this->state(fn (): array => [
            'slug' => 'windows-2022-'.Str::lower(Str::random(6)),
            'name' => ['en' => 'Windows Server 2022'],
            'os_family' => OsFamily::Windows,
            'os_version' => '2022',
            'requires_licence' => true,
            'licence_note' => 'Billed per two cores, minimum sixteen.',
        ]);
    }

    /**
     * A catalogue entry that has not been staged on any cluster yet, and so
     * cannot actually be built.
     */
    public function unstaged(): static
    {
        return $this->state(fn (): array => [
            'cluster_id' => null,
            'provider_reference' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
