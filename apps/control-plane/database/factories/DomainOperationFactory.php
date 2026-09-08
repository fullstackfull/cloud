<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * @extends Factory<DomainOperation>
 */
class DomainOperationFactory extends Factory
{
    protected $model = DomainOperation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => Str::lower(Str::random(10)).'.test',
            'kind' => DomainOperationKind::Register,
            'state' => DomainOperationState::Requested,
            'term_years' => 1,
            'currency' => 'KWD',
            'price_minor' => 3_500,
            'cost_minor' => 2_800,
            'idempotency_key' => 'op-'.Str::lower(Str::random(24)),
            'provider' => FakeDomainRegistrarProvider::NAME,
        ];
    }

    public function queued(): self
    {
        return $this->state(fn (): array => ['state' => DomainOperationState::Queued]);
    }

    /**
     * The case the whole module is shaped around: an attempt whose outcome
     * nobody knows.
     */
    public function indeterminate(): self
    {
        return $this->state(fn (): array => [
            'state' => DomainOperationState::Indeterminate,
            'attempts' => 1,
            'failure_code' => 'domain.registrar_unreachable',
            'failure_message' => 'The registrar did not answer within the timeout.',
        ]);
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'state' => DomainOperationState::Completed,
            'attempts' => 1,
            'completed_at' => now(),
        ]);
    }

    public function ofKind(DomainOperationKind $kind): self
    {
        return $this->state(fn (): array => ['kind' => $kind]);
    }
}
