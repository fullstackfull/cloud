<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'number' => 'LYN-'.Str::upper(Str::random(8)),
            /*
             * Open, not Draft.
             *
             * A draft is an invoice the platform has begun writing and has not
             * issued, and the customer surface deliberately cannot see one -
             * CustomerInvoices excludes them. A factory that produces drafts by
             * default therefore builds fixtures that no endpoint can read, and
             * every test that wanted "an invoice" had to discover that. Open is
             * what an invoice is for almost all of its life: issued, unpaid,
             * and the thing a customer is looking at when they open the page.
             *
             * ->draft() is still there for the tests that mean it.
             */
            'status' => InvoiceStatus::Open,
            'currency' => 'KWD',
            'subtotal_minor' => 9000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 9000,
            'amount_paid_minor' => 0,
            'amount_refunded_minor' => 0,
            'billing_snapshot' => [
                'display_name' => fake()->name(),
                'address' => ['line1' => fake()->streetAddress(), 'country' => 'KW'],
            ],
        ];
    }

    /**
     * Issued and awaiting payment.
     */
    /**
     * An invoice mid-write: created and not yet issued.
     *
     * Reachable only inside IssueInvoice's transaction in real life, so a
     * committed one is either a fixture or a bug.
     */
    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => InvoiceStatus::Draft]);
    }

    public function open(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Open,
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => InvoiceStatus::Paid,
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
            'amount_paid_minor' => $attributes['total_minor'],
            'paid_at' => now(),
        ]);
    }

    /**
     * Sets the currency and the totals together: an invoice whose currency and
     * amounts were set independently could not be trusted by a money test.
     */
    public function totalling(Money $total): static
    {
        return $this->state(fn (): array => [
            'currency' => $total->currency(),
            'subtotal_minor' => $total->minorUnits(),
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => $total->minorUnits(),
        ]);
    }
}
