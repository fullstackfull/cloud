<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    /**
     * Wallets are always born empty. There is deliberately no state that seeds
     * a balance: a balance that exists without the ledger entries behind it is
     * the exact corruption WalletLedger::reconcile() is there to catch, and a
     * factory that manufactures one would let a test pass against a ledger
     * that never happened.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'currency' => 'KWD',
            'balance_minor' => 0,
        ];
    }

    public function currency(string $currency): static
    {
        return $this->state(fn (): array => ['currency' => strtoupper($currency)]);
    }
}
