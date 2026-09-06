<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;

/**
 * @extends Factory<Datacenter>
 */
class DatacenterFactory extends Factory
{
    protected $model = Datacenter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'slug' => 'kw-dc-'.Str::lower(Str::random(8)),
            'name' => 'Kuwait DC',
            'facility' => 'Zajil',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
