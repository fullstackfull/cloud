<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/wallet.
 *
 * The endpoint answers one question — what can this account spend — and the
 * interesting assertions are about the ways it must not answer it: with
 * somebody else's money, with a currency conversion, or by opening a wallet
 * row because a browser loaded a dashboard.
 */
final class WalletBalanceEndpointTest extends WalletApiTestCase
{
    #[Test]
    public function a_customer_sees_the_balance_their_own_ledger_supports(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $wallet = $this->walletFor($customer);
        $this->credit($wallet, 25_000);
        $this->debit($wallet, 9_500);

        $this->actingAs($user)
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wallet_id', $wallet->id)
            ->assertJsonPath('data.0.currency', 'KWD')
            // Money is an object, never a bare number: KWD has three minor
            // digits and a client that assumes two shows 155.00 for 15.500.
            ->assertJsonPath('data.0.balance.minor_units', 15_500)
            ->assertJsonPath('data.0.balance.currency', 'KWD')
            ->assertJsonPath('data.0.balance.amount', '15.500')
            ->assertJsonPath('meta.account_currency', 'KWD')
            ->assertJsonPath('meta.currencies', 1);
    }

    #[Test]
    public function an_account_that_has_never_transacted_is_told_zero_rather_than_nothing(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wallet_id', null)
            ->assertJsonPath('data.0.currency', 'KWD')
            ->assertJsonPath('data.0.balance.minor_units', 0)
            ->assertJsonPath('data.0.balance.amount', '0.000');
    }

    #[Test]
    public function reading_a_balance_does_not_open_a_wallet(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)->getJson('/api/v1/wallet')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/wallet')->assertOk();

        // A GET that writes a row is a GET that can be made to write rows.
        $this->assertSame(0, Wallet::query()->count());
    }

    #[Test]
    public function two_currencies_are_two_balances_and_never_a_total(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $this->credit($this->walletFor($customer, 'KWD'), 9_000);
        $this->credit($this->walletFor($customer, 'USD'), 3_000);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // The account currency leads, so a single-currency client reads
            // data.0 and is right.
            ->assertJsonPath('data.0.currency', 'KWD')
            ->assertJsonPath('data.0.balance.minor_units', 9_000)
            ->assertJsonPath('data.1.currency', 'USD')
            ->assertJsonPath('data.1.balance.minor_units', 3_000)
            ->assertJsonPath('meta.currencies', 2);

        // Nothing anywhere in the payload adds 9.000 KWD to 30.00 USD: a
        // converted balance is not one the platform would honour.
        $body = $response->json();
        $this->assertArrayNotHasKey('total', $body['meta']);
        $this->assertArrayNotHasKey('total_balance', $body['meta']);
    }

    #[Test]
    public function another_accounts_balance_is_not_visible(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $theirWallet = $this->walletFor($theirs);
        $this->credit($theirWallet, 40_000);

        $response = $this->actingAs($mine)
            ->getJson('/api/v1/wallet')
            ->assertOk()
            // My own account currency, reported at zero — not their 40.000.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wallet_id', null)
            ->assertJsonPath('data.0.balance.minor_units', 0);

        // Not merely absent from the balance: their wallet's id never appears
        // anywhere in the body, so the response is not an oracle for it either.
        $this->assertStringNotContainsString($theirWallet->id, $response->getContent() ?: '');
        $this->assertStringNotContainsString((string) $theirs->id, $response->getContent() ?: '');
    }

    #[Test]
    public function the_balance_carries_nothing_internal(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $this->credit($this->walletFor($customer), 5_000);

        $response = $this->actingAs($user)->getJson('/api/v1/wallet')->assertOk();

        $balance = $response->json('data.0');

        // The account id buys a caller nothing but a shape to probe with.
        $this->assertArrayNotHasKey('customer_id', $balance);
        // The raw column never goes out on its own: the currency travels with
        // the amount or a client will guess the wrong number of minor digits.
        $this->assertArrayNotHasKey('balance_minor', $balance);
        $this->assertArrayNotHasKey('created_at', $balance);

        $this->assertSame(
            ['wallet_id', 'currency', 'balance', 'updated_at'],
            array_keys($balance),
        );
    }

    #[Test]
    public function a_member_who_may_not_see_the_money_is_refused_without_being_told_a_wallet_exists(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 5_000);

        $member = $this->memberOf($customer, CustomerRole::Member);

        $this->actingAs($member)
            ->getJson('/api/v1/wallet')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden')
            // The refusal says nothing about the balance behind it.
            ->assertJsonMissingPath('data');

        // The same account, a role that carries billing.view, and the money is
        // there — so the 403 above was about the role and not about the data.
        $this->actingAs($owner)->getJson('/api/v1/wallet')->assertOk();
    }

    /**
     * The cross-tenant case that the two-users-two-accounts version cannot
     * reach.
     *
     * One login, a member of two accounts, switching between them with the
     * header. The acting-customer middleware is the only thing deciding which
     * balance comes back — there is no id in the route and none in the body —
     * so this is where a scoping mistake would show: the same token, the same
     * user, two answers that must not contain each other's money.
     */
    #[Test]
    public function one_login_in_two_accounts_never_sees_the_other_accounts_balance(): void
    {
        $first = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $second = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $user = $this->memberOf($first, CustomerRole::Owner);
        $this->memberOf($second, CustomerRole::Owner, $user);

        $firstWallet = $this->walletFor($first);
        $secondWallet = $this->walletFor($second);
        $this->credit($firstWallet, 11_000);
        $this->credit($secondWallet, 77_000);

        $onFirst = $this->actingAs($user)
            ->getJson('/api/v1/wallet', ['X-Lynomia-Customer' => $first->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wallet_id', $firstWallet->id)
            ->assertJsonPath('data.0.balance.minor_units', 11_000);

        $this->assertStringNotContainsString($secondWallet->id, $onFirst->getContent() ?: '');

        $onSecond = $this->actingAs($user)
            ->getJson('/api/v1/wallet', ['X-Lynomia-Customer' => $second->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.wallet_id', $secondWallet->id)
            ->assertJsonPath('data.0.balance.minor_units', 77_000);

        $this->assertStringNotContainsString($firstWallet->id, $onSecond->getContent() ?: '');
    }

    /**
     * A role is held inside one account, not carried between them.
     */
    #[Test]
    public function owning_one_account_does_not_grant_billing_view_in_another(): void
    {
        $owned = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $joined = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $user = $this->memberOf($owned, CustomerRole::Owner);
        $this->memberOf($joined, CustomerRole::Member, $user);

        $this->credit($this->walletFor($joined), 77_000);

        $this->actingAs($user)
            ->getJson('/api/v1/wallet', ['X-Lynomia-Customer' => $joined->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        // The same login, the same request, the account they do own.
        $this->actingAs($user)
            ->getJson('/api/v1/wallet', ['X-Lynomia-Customer' => $owned->id])
            ->assertOk();
    }

    /**
     * `customers.currency` is client-supplied at registration and validated
     * only as three letters, so an account can carry "ZZZ" — which Brick, and
     * therefore Money, refuses. Before this was caught the refusal escaped as
     * a bare vendor RuntimeException and the customer got `server.error` with
     * nothing to branch on and nothing named to alert on.
     */
    #[Test]
    public function an_account_currency_that_is_not_a_currency_is_a_named_failure(): void
    {
        $customer = Customer::factory()->create(['currency' => 'ZZZ', 'country' => 'KW']);
        $user = $this->memberOf($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/wallet')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'wallet.unsupported_currency')
            ->assertJsonPath('error.details.currency', 'ZZZ')
            // Not answered with an empty list either: "you hold nothing" is a
            // different claim from "the platform cannot say".
            ->assertJsonMissingPath('data');
    }

    #[Test]
    public function the_billing_role_may_read_the_balance(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 5_000);

        $accountant = $this->memberOf($customer, CustomerRole::Billing);

        $this->actingAs($accountant)
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.0.balance.minor_units', 5_000);

        $this->assertNotSame($owner->id, $accountant->id);
    }
}
