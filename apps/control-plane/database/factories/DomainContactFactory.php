<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;

/**
 * @extends Factory<DomainContact>
 */
class DomainContactFactory extends Factory
{
    protected $model = DomainContact::class;

    /**
     * Deliberately obvious fiction.
     *
     * A factory that generated realistic-looking people would put
     * realistic-looking personal data in every developer's database and every
     * CI artefact, which is the thing this table's encryption exists to avoid.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'role' => DomainContactRole::Registrant,
            'name' => 'Test Registrant',
            'organisation' => 'Test Organisation',
            'email' => 'registrant@example.test',
            'phone' => '+96500000000',
            'address_line_one' => '1 Test Street',
            'city' => 'Kuwait City',
            'country' => 'KW',
        ];
    }

    public function forRole(DomainContactRole $role): self
    {
        return $this->state(fn (): array => ['role' => $role]);
    }
}
