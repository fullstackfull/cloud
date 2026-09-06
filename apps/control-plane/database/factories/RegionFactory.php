<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    protected $model = Region::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Random rather than sequential: the slug is unique, and a
            // sequence collides the moment two tests build fixtures in the
            // same transaction.
            'slug' => 'kw-'.Str::lower(Str::random(8)),
            'name' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
            'country' => 'KW',
            'city' => 'Kuwait City',
            'is_active' => true,
            'accepts_new_services' => true,
        ];
    }

    /**
     * Still running for existing customers, closed to new orders.
     */
    public function closedToNewServices(): static
    {
        return $this->state(fn (): array => ['accepts_new_services' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false, 'accepts_new_services' => false]);
    }
}
