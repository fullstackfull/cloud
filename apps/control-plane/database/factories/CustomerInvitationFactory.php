<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;

/**
 * @extends Factory<CustomerInvitation>
 */
class CustomerInvitationFactory extends Factory
{
    protected $model = CustomerInvitation::class;

    /**
     * The token a factory-made invitation redeems with.
     *
     * Fixed rather than random so a test can write the assertion it means —
     * "this token opens this offer" — without threading a generated value
     * through the setup. Production tokens come from the CSPRNG in
     * InviteMember and never from here.
     */
    public const string TOKEN = 'aaaaaaaabbbbbbbbccccccccddddddddeeeeeeeeffffffff00000000';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = str_pad(self::TOKEN, 64, '0');

        return [
            'customer_id' => Customer::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => CustomerRole::Member,
            'token_hash' => CustomerInvitation::hashOf($token),
            'expires_at' => CarbonImmutable::now()->addDays(14),
            'sent_count' => 1,
            'last_sent_at' => CarbonImmutable::now(),
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => CustomerInvitation::hashOf($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => CarbonImmutable::now()->subDay()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['accepted_at' => CarbonImmutable::now()->subHour()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => CarbonImmutable::now()->subHour()]);
    }
}
