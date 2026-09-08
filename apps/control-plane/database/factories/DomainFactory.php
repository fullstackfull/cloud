<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = Str::lower(Str::random(10));

        return [
            'customer_id' => Customer::factory(),
            // `.test` is reserved by the RFCs for exactly this, so a fixture
            // can never name a domain somebody really owns.
            'name' => $label.'.test',
            'tld' => 'test',
            /*
             * Pending, not active. A factory whose default is a registered
             * domain makes it easy to write a test that never exercises the
             * part that matters, which is how a name gets held in the first
             * place.
             */
            'state' => DomainState::RegistrationPending,
            'provider' => FakeDomainRegistrarProvider::NAME,
            'term_years' => 1,
            'auto_renew' => true,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'state' => DomainState::Active,
            'registered_at' => CarbonImmutable::now()->subMonths(2),
            'expires_at' => CarbonImmutable::now()->addMonths(10),
            'transfer_locked' => true,
            'provider_reference' => 'fake-'.Str::lower(Str::random(16)),
        ]);
    }

    public function expiringIn(int $days): self
    {
        return $this->active()->state(fn (): array => [
            'expires_at' => CarbonImmutable::now()->addDays($days),
        ]);
    }

    public function named(string $name): self
    {
        $tld = (string) Str::afterLast($name, '.');

        return $this->state(fn (): array => ['name' => $name, 'tld' => $tld]);
    }

    public function heldBy(Customer $customer): self
    {
        return $this->state(fn (): array => ['customer_id' => $customer->getKey()]);
    }
}
