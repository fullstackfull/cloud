<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * @extends Factory<HostingAccount>
 */
class HostingAccountFactory extends Factory
{
    protected $model = HostingAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $username = 'lyn'.Str::lower(Str::random(8));

        return [
            'hosting_node_id' => HostingNode::factory(),
            'hosting_package_id' => HostingPackage::factory(),
            'customer_id' => Customer::factory(),
            'service_id' => null,
            'username' => $username,
            'primary_domain' => $username.'.example.test',
            'status' => HostingAccountStatus::Active,
            'ssl_status' => SslStatus::Active,
        ];
    }

    public function status(HostingAccountStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function suspended(string $reason = 'non-payment', ?int $daysAgo = null): static
    {
        return $this->state(fn (): array => [
            'status' => HostingAccountStatus::Suspended,
            'suspended_at' => $daysAgo === null ? now() : now()->subDays($daysAgo),
            'suspension_reason' => $reason,
        ]);
    }

    /**
     * Usage the panel has already reported, so a sync test can prove that a
     * later empty answer does not overwrite it.
     */
    public function withUsage(int $diskUsedMib, int $bandwidthUsedMib): static
    {
        return $this->state(fn (): array => [
            'disk_used_mib' => $diskUsedMib,
            'bandwidth_used_mib' => $bandwidthUsedMib,
            'usage_synced_at' => now()->subHour(),
        ]);
    }

    public function named(string $username): static
    {
        return $this->state(fn (): array => [
            'username' => $username,
            'primary_domain' => $username.'.example.test',
        ]);
    }
}
