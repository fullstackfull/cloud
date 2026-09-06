<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\PaymentMethodKind;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentMethod;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'provider' => FakePaymentProvider::NAME,
            'provider_reference' => 'fake_pm_'.Str::lower(Str::random(20)),
            'kind' => PaymentMethodKind::Card,
            'brand' => 'visa',
            // The four digits a receipt may show. Nothing more of the card
            // ever exists in this database.
            'last_four' => '4242',
            'expiry_month' => 12,
            'expiry_year' => (int) now()->addYears(2)->year,
            'is_default' => true,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expiry_month' => 1,
            'expiry_year' => (int) now()->subYear()->year,
        ]);
    }

    public function knet(): static
    {
        return $this->state(fn (): array => [
            'kind' => PaymentMethodKind::Knet,
            'brand' => 'knet',
            'last_four' => null,
            'expiry_month' => null,
            'expiry_year' => null,
        ]);
    }
}
