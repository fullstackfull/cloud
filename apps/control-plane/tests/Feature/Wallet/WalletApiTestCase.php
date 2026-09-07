<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use Tests\TestCase;

/**
 * Shared scaffolding for the wallet endpoints.
 *
 * Balances here are always built by posting through WalletLedger rather than
 * by writing balance_minor. A test that seeded a balance directly would prove
 * the endpoint can read a column; these prove it reads the balance the
 * platform would actually let the customer spend, with the ledger behind it.
 */
abstract class WalletApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * A customer account with an accepted owner membership, and the user who
     * holds it.
     *
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(CustomerRole $role = CustomerRole::Owner): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = $this->memberOf($customer, $role);

        return [$customer, $user];
    }

    protected function memberOf(Customer $customer, CustomerRole $role = CustomerRole::Owner, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            // An invitation that was never accepted grants nothing.
            'accepted_at' => now(),
        ]);

        return $user;
    }

    protected function ledger(): WalletLedger
    {
        return app(WalletLedger::class);
    }

    protected function walletFor(Customer $customer, string $currency = 'KWD'): Wallet
    {
        return $this->ledger()->walletFor($customer, $currency);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function credit(
        Wallet $wallet,
        int $minorUnits,
        WalletTransactionKind $kind = WalletTransactionKind::Topup,
        string $description = 'Top-up by card ending 4242',
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $invoiceId = null,
    ): WalletTransaction {
        return $this->ledger()->credit(
            wallet: $wallet,
            amount: Money::ofMinor($minorUnits, $wallet->currency),
            kind: $kind,
            description: $description,
            metadata: $metadata,
            idempotencyKey: $idempotencyKey,
            invoiceId: $invoiceId,
        );
    }

    protected function debit(
        Wallet $wallet,
        int $minorUnits,
        WalletTransactionKind $kind = WalletTransactionKind::Payment,
        string $description = 'Applied to invoice LYN-000012',
    ): WalletTransaction {
        return $this->ledger()->debit(
            wallet: $wallet,
            amount: Money::ofMinor($minorUnits, $wallet->currency),
            kind: $kind,
            description: $description,
        );
    }
}
