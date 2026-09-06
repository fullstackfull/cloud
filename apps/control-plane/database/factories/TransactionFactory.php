<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'provider' => FakePaymentProvider::NAME,
            // Shaped like the fake provider's own references so that a
            // factory-made transaction can be retrieved and refunded through
            // the real adapter rather than needing a special case.
            'provider_reference' => 'fake_pi_succeeded_KWD_9000_'.Str::lower(Str::random(8)),
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 9000,
            'currency' => 'KWD',
            'processed_at' => now(),
        ];
    }

    public function amount(Money $amount): static
    {
        return $this->state(fn (): array => [
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'provider_reference' => sprintf(
                'fake_pi_succeeded_%s_%d_%s',
                $amount->currency(),
                $amount->minorUnits(),
                Str::lower(Str::random(8)),
            ),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Pending,
            'processed_at' => null,
        ]);
    }

    public function failed(string $failureCode = 'card_declined'): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Failed,
            'failure_code' => $failureCode,
            'failure_message' => 'The issuer declined the payment.',
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => ['customer_id' => $customer->id]);
    }
}
