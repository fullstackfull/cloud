<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerType;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CustomerType::Individual,
            'status' => CustomerStatus::Active,
            'display_name' => fake()->name(),
            'currency' => 'KWD',
            'billing_email' => fake()->unique()->safeEmail(),
            'country' => 'KW',
            'tax_exempt' => false,
            'requires_manual_review' => false,
        ];
    }

    public function organization(): static
    {
        return $this->state(fn (): array => [
            'type' => CustomerType::Organization,
            'display_name' => fake()->company(),
            'legal_name' => fake()->company().' W.L.L.',
            'registration_number' => (string) fake()->numberBetween(100000, 999999),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => CustomerStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
