<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'amount_minor' => 1000,
            'currency' => 'KWD',
            'status' => RefundStatus::Succeeded,
            'reason' => 'requested_by_customer',
            'processed_at' => now(),
        ];
    }

    public function amount(Money $amount): static
    {
        return $this->state(fn (): array => [
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
        ]);
    }

    public function status(RefundStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'processed_at' => $status === RefundStatus::Succeeded ? now() : null,
        ]);
    }
}
