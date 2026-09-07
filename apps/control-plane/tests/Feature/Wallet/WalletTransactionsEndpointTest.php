<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Http\Requests\ListWalletTransactionsRequest;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/wallet/transactions.
 *
 * A statement is the evidence behind a balance, so what matters here is that
 * it is complete for the account asking, empty of every other account, and
 * carries none of the machinery — idempotency keys, redacted provider
 * payloads, the operator who posted an adjustment — that the ledger stores
 * alongside the lines.
 */
final class WalletTransactionsEndpointTest extends WalletApiTestCase
{
    #[Test]
    public function a_customer_reads_their_own_ledger_newest_first(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $wallet = $this->walletFor($customer);
        $topup = $this->credit($wallet, 25_000);
        $payment = $this->debit($wallet, 9_500);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk();

        $response
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.1.id', $topup->id)

            // Signed as stored: the debit went out negative, and the sign is
            // not restated as a separate magnitude that could disagree.
            ->assertJsonPath('data.0.amount.minor_units', -9_500)
            ->assertJsonPath('data.0.amount.currency', 'KWD')
            ->assertJsonPath('data.0.amount.amount', '-9.500')
            ->assertJsonPath('data.0.direction', 'debit')
            ->assertJsonPath('data.0.kind', WalletTransactionKind::Payment->value)

            // Stamped by the ledger when the entry was written, so the
            // statement can be audited without replaying every row above it.
            ->assertJsonPath('data.0.balance_after.minor_units', 15_500)
            ->assertJsonPath('data.0.balance_after.amount', '15.500')

            ->assertJsonPath('data.1.direction', 'credit')
            ->assertJsonPath('data.1.amount.minor_units', 25_000)
            ->assertJsonPath('data.1.description', 'Top-up by card ending 4242')

            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 25);
    }

    #[Test]
    public function the_ledger_shows_nothing_belonging_to_another_account(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $theirEntry = $this->credit($this->walletFor($theirs), 40_000, description: 'Their top-up');

        $response = $this->actingAs($mine)
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->assertStringNotContainsString($theirEntry->id, $response->getContent() ?: '');
    }

    /**
     * The cross-tenant case for this endpoint, made concrete.
     *
     * The route takes no id, so there is no path parameter to point at another
     * tenant's entry — which is itself the property under test. The nearest a
     * caller can come is naming a currency only the other account holds a
     * wallet in, and the answer must be an empty page rather than that
     * account's lines: an empty page is the same answer a currency nobody
     * holds gets, so it confirms nothing about whether the wallet exists.
     */
    #[Test]
    public function naming_a_currency_only_another_account_holds_returns_an_empty_page(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $theirEntry = $this->credit($this->walletFor($theirs, 'USD'), 3_000, description: 'Their USD top-up');

        $response = $this->actingAs($mine)
            ->getJson('/api/v1/wallet/transactions?currency=USD')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->assertStringNotContainsString($theirEntry->id, $response->getContent() ?: '');

        // Indistinguishable from a currency no account holds at all.
        $this->actingAs($mine)
            ->getJson('/api/v1/wallet/transactions?currency=JPY')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function the_ledger_can_be_filtered_by_kind_and_by_currency(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $kwd = $this->walletFor($customer, 'KWD');
        $usd = $this->walletFor($customer, 'USD');

        $topup = $this->credit($kwd, 25_000);
        $this->debit($kwd, 5_000);
        $usdTopup = $this->credit($usd, 3_000);

        $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions?kind='.WalletTransactionKind::Payment->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'debit');

        $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions?currency=USD')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $usdTopup->id)
            // Each line carries its own wallet's currency; there is no account
            // currency imposed on a foreign entry.
            ->assertJsonPath('data.0.amount.currency', 'USD')
            ->assertJsonPath('data.0.amount.amount', '30.00');

        // Lower case is the same filter, not an empty statement.
        $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions?currency=kwd')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotSame($topup->id, $usdTopup->id);
    }

    #[Test]
    public function every_currency_appears_by_default_because_a_partial_statement_does_not_add_up(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $this->credit($this->walletFor($customer, 'KWD'), 25_000);
        $this->credit($this->walletFor($customer, 'USD'), 3_000);

        $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function a_caller_cannot_ask_for_the_whole_ledger_in_one_page(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $wallet = $this->walletFor($customer);

        foreach (range(1, 5) as $n) {
            $this->credit($wallet, 1_000, description: 'Top-up '.$n);
        }

        $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', ListWalletTransactionsRequest::MAX_PER_PAGE)
            ->assertJsonPath('meta.max_per_page', 100)
            ->assertJsonPath('meta.total', 5);

        // Nonsense page sizes fall back to the default rather than becoming a
        // slow walk through the whole ledger one row at a time.
        $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    #[Test]
    public function paging_is_stable_across_entries_written_in_the_same_second(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $wallet = $this->walletFor($customer);

        $ids = [];
        foreach (range(1, 4) as $n) {
            $ids[] = $this->credit($wallet, 1_000, description: 'Top-up '.$n)->id;
        }

        // created_at has one-second resolution on this table, so without the
        // ULID tie-break a row could appear on both pages or on neither.
        $first = $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions?per_page=2&page=1')
            ->assertOk()
            ->json('data.*.id');

        $second = $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions?per_page=2&page=2')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame(array_reverse($ids), array_merge($first, $second));
        $this->assertSame([], array_intersect($first, $second));
    }

    #[Test]
    public function a_nonsense_filter_is_refused_with_the_fields_that_were_wrong(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions?kind=free_money&currency=USDD&page=0')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields'], 'request_id']])
            ->assertJsonPath('error.details.fields.kind.0', fn ($messages): bool => $messages !== null)
            ->assertJsonPath('error.details.fields.currency.0', fn ($messages): bool => $messages !== null)
            ->assertJsonPath('error.details.fields.page.0', fn ($messages): bool => $messages !== null);
    }

    #[Test]
    public function a_wallet_id_in_the_query_string_buys_a_caller_nothing(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $theirWallet = $this->walletFor($theirs);
        $theirEntry = $this->credit($theirWallet, 40_000, description: 'Their top-up');

        // Ignored, not honoured: which wallets are read follows from the
        // acting customer and never from anything the caller sends.
        $response = $this->actingAs($mine)
            ->getJson('/api/v1/wallet/transactions?wallet_id='.$theirWallet->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertStringNotContainsString($theirEntry->id, $response->getContent() ?: '');
    }

    #[Test]
    public function no_entry_carries_the_ledgers_internals(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $wallet = $this->walletFor($customer);

        $entry = $this->credit(
            $wallet,
            25_000,
            metadata: [
                'provider' => 'knet',
                'card_token' => 'tok_live_should_never_be_published',
            ],
            idempotencyKey: 'topup:should-never-be-published',
            invoiceId: (string) Str::ulid(),
        );

        $response = $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk();

        $line = $response->json('data.0');
        $body = $response->getContent() ?: '';

        // The metadata document carries the reserved idempotency key the
        // ledger's replay check reads, plus whatever slice of a provider
        // response the caller attached. Neither is the customer's business.
        $this->assertArrayNotHasKey('metadata', $line);
        $this->assertStringNotContainsString('topup:should-never-be-published', $body);
        $this->assertStringNotContainsString('card_token', $body);
        $this->assertStringNotContainsString('tok_live_should_never_be_published', $body);

        // The operator behind an adjustment is a staff identity; the customer
        // is owed the reason, which is the description, not the name.
        $this->assertArrayNotHasKey('created_by_user_id', $line);
        $this->assertArrayNotHasKey('created_by', $line);

        // Raw columns never go out on their own: an amount without its
        // currency is a number a client will scale wrongly.
        $this->assertArrayNotHasKey('amount_minor', $line);
        $this->assertArrayNotHasKey('balance_after_minor', $line);

        // The join key HasManyThrough adds to the select is machinery, not a
        // field of the statement.
        $this->assertArrayNotHasKey('laravel_through_key', $line);

        $this->assertSame([
            'id',
            'wallet_id',
            'kind',
            'amount',
            'direction',
            'balance_after',
            'description',
            'invoice_id',
            'transaction_id',
            'created_at',
        ], array_keys($line));

        $this->assertSame($entry->invoice_id, $line['invoice_id']);
    }

    /**
     * Two accounts, the same currency, both with a ledger.
     *
     * The empty-versus-empty version of this test cannot tell a correctly
     * scoped query from one that simply found nothing. Here the caller has
     * lines of their own, so a query joined to the wrong customer — or joined
     * to none — would show up as extra rows rather than as no rows.
     */
    #[Test]
    public function two_accounts_holding_the_same_currency_do_not_see_each_others_lines(): void
    {
        [$mineAccount, $mine] = $this->accountWithOwner();
        [$theirsAccount] = $this->accountWithOwner();

        $myTopup = $this->credit($this->walletFor($mineAccount), 5_000, description: 'My top-up');
        $theirTopup = $this->credit($this->walletFor($theirsAccount), 40_000, description: 'Their top-up');

        $response = $this->actingAs($mine)
            ->getJson('/api/v1/wallet/transactions?currency=KWD')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $myTopup->id)
            ->assertJsonPath('meta.total', 1);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString($theirTopup->id, $body);
        $this->assertStringNotContainsString('Their top-up', $body);
        $this->assertStringNotContainsString((string) $theirsAccount->id, $body);
    }

    /**
     * `?wallet_id=` is ignored, which is not the same as narrowing to nothing.
     *
     * A caller whose own statement is empty cannot tell the two apart: both
     * answer with zero rows. So the caller here has a line of their own, and
     * the assertion is that it is still there — the parameter changed nothing,
     * rather than having been honoured as a filter that happened to match no
     * row of theirs.
     */
    #[Test]
    public function a_wallet_id_in_the_query_string_neither_widens_nor_narrows_the_statement(): void
    {
        [$mineAccount, $mine] = $this->accountWithOwner();
        [$theirsAccount] = $this->accountWithOwner();

        $myTopup = $this->credit($this->walletFor($mineAccount), 5_000, description: 'My top-up');

        $theirWallet = $this->walletFor($theirsAccount);
        $theirTopup = $this->credit($theirWallet, 40_000, description: 'Their top-up');

        $response = $this->actingAs($mine)
            ->getJson('/api/v1/wallet/transactions?wallet_id='.$theirWallet->id)
            ->assertOk()
            // Unchanged: still exactly the caller's own statement.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $myTopup->id);

        $this->assertStringNotContainsString($theirTopup->id, $response->getContent() ?: '');
    }

    /**
     * A currency filter that is not three ASCII letters is refused.
     *
     * Laravel's unqualified `alpha` matches any Unicode letter and `size`
     * counts characters, so a Cyrillic "КWD" passed validation, survived a
     * byte-wise strtoupper() unchanged, matched no wallet, and came back as a
     * 200 with an empty statement — a client shipping a mis-encoded currency
     * filter would have shown its customers an empty ledger and no error.
     */
    #[Test]
    public function a_currency_filter_that_is_not_ascii_is_refused_rather_than_answered_empty(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 5_000);

        $this->actingAs($user)
            // "КWD" — a Cyrillic К, three characters, four bytes.
            ->getJson('/api/v1/wallet/transactions?currency='.rawurlencode("\u{041a}WD"))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['currency']]]]);
    }

    /**
     * The strongest form of the "nothing internal" assertion: an entry that
     * actually has an operator behind it.
     *
     * The other test posts entries with no actor, so a resource that leaked
     * `created_by_user_id` would have leaked a null. This one posts an
     * attributed adjustment and asserts the staff identity appears nowhere in
     * the body at all.
     */
    #[Test]
    public function an_attributed_adjustment_does_not_name_the_operator_who_posted_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $operator = User::factory()->create([
            'name' => 'Internal Operator',
            'email' => 'noc-operator@lynomia.internal',
        ]);

        $entry = $this->ledger()->credit(
            wallet: $this->walletFor($customer),
            amount: Money::ofMinor(1_000, 'KWD'),
            kind: WalletTransactionKind::Adjustment,
            description: 'Goodwill credit for the March outage',
            actor: $operator,
        );

        // The entry really is attributed; the assertions below are about what
        // the resource withholds, not about an entry that has nothing to hide.
        $this->assertSame($operator->id, $entry->created_by_user_id);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk()
            // The customer is owed the reason.
            ->assertJsonPath('data.0.description', 'Goodwill credit for the March outage');

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString($operator->id, $body);
        $this->assertStringNotContainsString('noc-operator@lynomia.internal', $body);
        $this->assertStringNotContainsString('Internal Operator', $body);
    }

    #[Test]
    public function a_member_who_may_not_see_the_money_cannot_read_the_ledger(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 25_000);

        $member = $this->memberOf($customer, CustomerRole::Member);

        $this->actingAs($member)
            ->getJson('/api/v1/wallet/transactions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->actingAs($owner)
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_surface_is_read_only(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $wallet = $this->walletFor($customer);
        $this->credit($wallet, 5_000);

        // A customer cannot credit their own balance. Money reaches a wallet
        // from a confirmed payment or an operator's attributed adjustment, and
        // neither is reachable from here.
        foreach ([
            ['post', '/api/v1/wallet'],
            ['post', '/api/v1/wallet/topup'],
            ['post', '/api/v1/wallet/transactions'],
            ['patch', '/api/v1/wallet'],
            ['delete', '/api/v1/wallet'],
        ] as [$method, $uri]) {
            $status = $this->actingAs($user)
                ->json($method, $uri, ['amount' => 1_000_000, 'currency' => 'KWD'])
                ->getStatusCode();

            // 405 where the path exists for GET, 404 where it does not exist
            // at all. Either way there is no route, and neither answer is a
            // credit.
            $this->assertContains(
                $status,
                [404, 405],
                sprintf('%s %s answered %d; it should not be routable.', strtoupper($method), $uri, $status),
            );
        }

        $this->assertSame(5_000, $wallet->fresh()?->balance_minor);
        $this->assertSame(1, WalletTransaction::query()->count());
    }
}
