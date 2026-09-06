<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => ProductKind::Vps,
            'slug' => 'product-'.Str::lower(Str::random(8)),
            'is_active' => true,
            'is_public' => true,
            'name' => ['en' => 'Cloud VPS', 'ar' => 'خادم سحابي'],
            'description' => ['en' => 'Virtual machines on shared hypervisors.', 'ar' => 'أجهزة افتراضية.'],
        ];
    }

    public function dedicated(): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductKind::Dedicated,
            'name' => ['en' => 'Dedicated Servers', 'ar' => 'خوادم مخصصة'],
        ]);
    }

    public function sharedHosting(): static
    {
        return $this->state(fn (): array => [
            'kind' => ProductKind::SharedHosting,
            'name' => ['en' => 'Shared Hosting', 'ar' => 'استضافة مشتركة'],
        ]);
    }

    public function unlisted(): static
    {
        return $this->state(fn (): array => ['is_public' => false]);
    }
}
