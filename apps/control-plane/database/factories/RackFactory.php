<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;

/**
 * @extends Factory<Rack>
 */
class RackFactory extends Factory
{
    protected $model = Rack::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'datacenter_id' => Datacenter::factory(),
            // Unique per run: the table has a (datacenter_id, name) unique
            // constraint, and a fixed name would make two racks in one
            // datacenter impossible to create in a single test.
            'name' => 'R'.Str::upper(Str::random(4)),
            'row' => 'A',
            'units' => 42,
        ];
    }

    public function inDatacenter(Datacenter $datacenter): static
    {
        return $this->state(fn (): array => ['datacenter_id' => $datacenter->getKey()]);
    }
}
