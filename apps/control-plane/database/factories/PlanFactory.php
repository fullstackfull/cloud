<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'slug' => 'plan-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'is_public' => true,
            'name' => ['en' => 'CX-2', 'ar' => 'CX-2'],
            'resources' => [
                'vcpu' => 2,
                'memory_mib' => 4096,
                'disk_gib' => 40,
                'bandwidth_tib' => 2,
                'ipv4_count' => 1,
                'ipv6_count' => 1,
            ],
        ];
    }

    public function withStock(int $limit): static
    {
        return $this->state(fn (): array => ['stock_limit' => $limit]);
    }

    public function limitedPerCustomer(int $limit): static
    {
        return $this->state(fn (): array => ['per_customer_limit' => $limit]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
