<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * @extends Factory<HostingPackage>
 */
class HostingPackageFactory extends Factory
{
    protected $model = HostingPackage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'hosting-'.Str::lower(Str::random(6));

        return [
            'plan_id' => null,
            'slug' => $slug,
            // The name as the PANEL knows it, which is deliberately not the
            // platform slug: renaming a plan in the catalogue must not change
            // what createacct is given.
            'panel_package_name' => 'lyn_'.Str::lower(Str::random(6)),
            'disk_quota_mib' => 10_240,
            'bandwidth_quota_mib' => 512_000,
            'max_addon_domains' => 5,
            'max_subdomains' => 25,
            'max_databases' => 10,
            'max_email_accounts' => 50,
            'is_active' => true,
        ];
    }

    public function named(string $panelPackageName): static
    {
        return $this->state(fn (): array => ['panel_package_name' => $panelPackageName]);
    }

    public function diskQuotaMib(?int $mib): static
    {
        return $this->state(fn (): array => ['disk_quota_mib' => $mib]);
    }

    /**
     * A package that promises kernel-enforced limits.
     *
     * Only CloudLinux enforces these. The platform records them either way and
     * says plainly whether the node an account landed on can hold to them.
     */
    public function withKernelLimits(): static
    {
        return $this->state(fn (): array => [
            'cpu_limit_percent' => 100,
            'memory_limit_mib' => 1024,
            'io_limit_kbps' => 1024,
            'process_limit' => 100,
            'entry_process_limit' => 20,
        ]);
    }
}
