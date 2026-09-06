<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;

/**
 * @extends Factory<InvoiceItem>
 */
class InvoiceItemFactory extends Factory
{
    protected $model = InvoiceItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'kind' => InvoiceItemKind::Plan,
            'description' => fake()->words(3, true),
            'quantity' => 1,
            'unit_amount_minor' => 9000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 9000,
            'tax_rate' => '0',
            'tax_name' => null,
        ];
    }
}
