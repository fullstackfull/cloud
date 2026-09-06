<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * @extends Factory<HostingNode>
 */
class HostingNodeFactory extends Factory
{
    protected $model = HostingNode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'shared-'.Str::lower(Str::random(6));

        return [
            'datacenter_id' => Datacenter::factory(),
            'slug' => $slug,
            'hostname' => $slug.'.lynomia.test',
            'panel' => HostingPanel::Fake,
            'panel_version' => '11.126.0',
            'api_endpoint' => 'https://'.$slug.'.lynomia.test:2087',
            'credentials_reference' => $slug,
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            // Licensed by default so that a test which does not care about
            // licensing is not silently testing the unlicensed path.
            'panel_licensed' => true,
            'licence_checked_at' => now(),
            'licence_status' => 'active',
            'cloudlinux' => false,
            'litespeed' => false,
            'max_accounts' => 200,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
            'load_average' => 1.5,
            'last_synced_at' => now(),
        ];
    }

    public function panel(HostingPanel $panel): static
    {
        return $this->state(fn (): array => ['panel' => $panel]);
    }

    public function status(HostingNodeStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * A node whose panel licence has lapsed.
     *
     * The licence_status text is kept as the vendor would word it, because
     * that is what an operator reads in a support ticket.
     */
    public function unlicensed(string $state = 'expired'): static
    {
        return $this->state(fn (): array => [
            'panel_licensed' => false,
            'licence_status' => $state,
            'licence_checked_at' => now(),
        ]);
    }

    public function draining(): static
    {
        return $this->state(fn (): array => ['status' => HostingNodeStatus::Draining]);
    }

    public function inMaintenance(): static
    {
        return $this->state(fn (): array => ['status' => HostingNodeStatus::Maintenance]);
    }

    /**
     * Disk filled to a given percentage, which is what makes a node look full
     * to the scheduler without creating the accounts that filled it.
     */
    public function diskUsedPercent(float $percent): static
    {
        return $this->state(function (array $attributes) use ($percent): array {
            $total = (int) ($attributes['disk_total_mib'] ?? 2_097_152);

            return ['disk_used_mib' => (int) round($total * $percent / 100)];
        });
    }

    public function withAccounts(int $count, ?int $max = null): static
    {
        return $this->state(fn (): array => array_filter([
            'account_count' => $count,
            'max_accounts' => $max,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function loadAverage(float $load): static
    {
        return $this->state(fn (): array => ['load_average' => $load]);
    }

    public function withCloudLinux(): static
    {
        return $this->state(fn (): array => ['cloudlinux' => true]);
    }
}
