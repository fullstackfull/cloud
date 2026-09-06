<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => fn (): string => self::anInvoiceId(),
            'attempt_number' => 1,
            'status' => PaymentAttemptStatus::Pending,
        ];
    }

    public function failed(string $code = 'card_declined'): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentAttemptStatus::Failed,
            'failure_code' => $code,
            'failure_message' => 'The issuer declined the payment.',
            'next_retry_at' => now()->addDay(),
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentAttemptStatus::Succeeded,
            'next_retry_at' => null,
        ]);
    }

    /**
     * An attempt cannot exist without the invoice it is attempting to collect,
     * and the invoice model belongs to the Billing module. The row is inserted
     * directly so that this factory stays usable without reaching across a
     * module boundary for a fixture.
     */
    private static function anInvoiceId(): string
    {
        $customer = Customer::factory()->create();
        $id = (string) Str::ulid();

        DB::table('invoices')->insert([
            'id' => $id,
            'customer_id' => $customer->id,
            'number' => 'LYN-'.Str::upper(Str::random(10)),
            'status' => 'open',
            'currency' => 'KWD',
            'total_minor' => 9000,
            'billing_snapshot' => json_encode([]),
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
