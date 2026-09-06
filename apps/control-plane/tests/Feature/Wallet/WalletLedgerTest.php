<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Exceptions\IdempotencyKeyConflictException;
use Lynomia\Modules\Wallet\Domain\Exceptions\InsufficientWalletBalanceException;
use Lynomia\Modules\Wallet\Domain\Exceptions\LedgerEntryIsImmutableException;
use Lynomia\Modules\Wallet\Domain\Exceptions\UnattributedAdjustmentException;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private WalletLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(WalletLedger::class);
    }

    #[Test]
    public function crediting_then_debiting_leaves_the_ledger_and_the_cache_in_agreement(): void
    {
        $wallet = Wallet::factory()->create();

        $topup = $this->ledger->credit(
            $wallet,
            Money::ofMinor(25_000, 'KWD'),
            WalletTransactionKind::Topup,
            'Top-up by card ending 4242',
        );

        $payment = $this->ledger->debit(
            $wallet,
            Money::ofMinor(9_500, 'KWD'),
            WalletTransactionKind::Payment,
            'Applied to invoice LYN-000001',
        );

        $this->assertSame(25_000, $topup->amount_minor);
        $this->assertSame(25_000, $topup->balance_after_minor);
        $this->assertTrue($topup->isCredit());

        // The debit is stored signed, so summing the column is the balance.
        $this->assertSame(-9_500, $payment->amount_minor);
        $this->assertSame(15_500, $payment->balance_after_minor);
        $this->assertTrue($payment->isDebit());

        $wallet->refresh();
        $this->assertSame(15_500, $wallet->balance_minor);
        $this->assertTrue($this->ledger->recomputeBalance($wallet)->equals(Money::ofMinor(15_500, 'KWD')));
        $this->assertTrue($this->ledger->reconcile($wallet)->isBalanced());
    }

    #[Test]
    public function an_entry_reports_its_amount_in_the_wallets_currency(): void
    {
        $wallet = Wallet::factory()->currency('usd')->create();

        $entry = $this->ledger->credit(
            $wallet,
            Money::ofMinor(1_250, 'USD'),
            WalletTransactionKind::Promotional,
            'Launch promotion',
        );

        $this->assertTrue($entry->amount()->equals(Money::ofMinor(1_250, 'USD')));
        $this->assertTrue($entry->balanceAfter()->equals(Money::ofMinor(1_250, 'USD')));
    }

    #[Test]
    public function a_debit_that_would_overdraw_throws_and_writes_nothing(): void
    {
        $wallet = Wallet::factory()->create();
        $this->ledger->credit($wallet, Money::ofMinor(5_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up');

        $rowsBefore = DB::table('wallet_transactions')->count();

        try {
            $this->ledger->debit(
                $wallet,
                Money::ofMinor(5_001, 'KWD'),
                WalletTransactionKind::Payment,
                'Applied to invoice LYN-000002',
            );
            $this->fail('Expected the overdrawing debit to be refused.');
        } catch (InsufficientWalletBalanceException $e) {
            $this->assertSame('wallet.insufficient_balance', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame(1, $e->context()['shortfall_minor']);
            $this->assertSame($wallet->id, $e->context()['wallet_id']);
        }

        $this->assertSame($rowsBefore, DB::table('wallet_transactions')->count());
        $this->assertSame(5_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));
    }

    #[Test]
    public function a_debit_may_take_the_balance_to_exactly_zero(): void
    {
        $wallet = Wallet::factory()->create();
        $this->ledger->credit($wallet, Money::ofMinor(3_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up');

        $entry = $this->ledger->debit($wallet, Money::ofMinor(3_000, 'KWD'), WalletTransactionKind::Payment, 'Paid in full');

        $this->assertSame(0, $entry->balance_after_minor);
        $this->assertTrue($this->ledger->recomputeBalance($wallet->refresh())->isZero());
    }

    #[Test]
    public function concurrent_debits_on_separate_connections_cannot_both_spend_the_same_balance(): void
    {
        /*
         * The bug this guards against needs two debits genuinely in flight at
         * once: each reads a balance of 1.000 KWD, each decides it can afford
         * 0.700, and the wallet ends up 0.400 in the red with no credit
         * product behind it. Two debits issued one after another on a single
         * connection are serialised by definition and would prove nothing, so
         * the second debit is fired from inside the first one's open
         * transaction, on its own connection.
         */
        foreach (['wallet_a', 'wallet_b'] as $name) {
            config()->set("database.connections.{$name}", config('database.connections.pgsql'));
        }

        /*
         * RefreshDatabase wraps the default connection in a transaction that is
         * never committed, so anything created there is invisible to another
         * connection. This scenario is entirely about what two connections see
         * of each other, so its fixtures are committed on one of them and
         * removed again in the finally block.
         */
        $customer = Customer::factory()->make();
        $customer->setConnection('wallet_a')->save();

        try {
            $wallet = new Wallet(['customer_id' => $customer->id, 'currency' => 'KWD', 'balance_minor' => 0]);
            $wallet->setConnection('wallet_a')->save();

            $this->ledger->credit($wallet, Money::ofMinor(1_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up');

            // The contender loads the wallet before anything is spent, so its
            // own copy says the full 1.000 KWD is available.
            $contender = Wallet::on('wallet_b')->findOrFail($wallet->id);
            $this->assertSame(1_000, $contender->balance_minor);

            /*
             * Without a lock timeout the contender would wait for a lock that
             * only the caller further up this same PHP stack can release, and
             * the test would hang rather than fail.
             */
            DB::connection('wallet_b')->statement("SET lock_timeout = '1s'");

            $outcome = null;
            $fired = false;

            // Fires inside the first debit's transaction, after it has taken
            // the wallet row and before it has written the new balance.
            WalletTransaction::created(function () use (&$outcome, &$fired, $contender): void {
                if ($fired) {
                    return;
                }

                $fired = true;

                try {
                    $this->ledger->debit(
                        $contender,
                        Money::ofMinor(700, 'KWD'),
                        WalletTransactionKind::Payment,
                        'Invoice B',
                    );
                    $outcome = 'committed';
                } catch (InsufficientWalletBalanceException) {
                    $outcome = 'refused';
                } catch (QueryException) {
                    $outcome = 'blocked';
                }
            });

            $this->ledger->debit($wallet, Money::ofMinor(700, 'KWD'), WalletTransactionKind::Payment, 'Invoice A');

            $this->assertSame(
                'blocked',
                $outcome,
                'The second debit must wait on the wallet row rather than reading a balance that is already being spent.'
            );

            $connection = DB::connection('wallet_a');
            $this->assertSame(300, (int) $connection->table('wallets')->where('id', $wallet->id)->value('balance_minor'));
            $this->assertSame(2, $connection->table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
            $this->assertSame(
                300,
                (int) $connection->table('wallet_transactions')->where('wallet_id', $wallet->id)->sum('amount_minor'),
                'The ledger itself must never add up to an overdrawn balance.'
            );
        } finally {
            WalletTransaction::flushEventListeners();
            DB::connection('wallet_b')->statement('SET lock_timeout = 0');
            // wallets and wallet_transactions cascade from the customer.
            Customer::on('wallet_a')->whereKey($customer->id)->forceDelete();
        }
    }

    #[Test]
    public function the_recomputed_balance_matches_the_cache_after_a_long_sequence(): void
    {
        $wallet = Wallet::factory()->create();

        // Seeded so a failure is reproducible rather than a once-a-week ghost.
        mt_srand(20260906);

        $expected = 0;
        $this->ledger->credit($wallet, Money::ofMinor(50_000, 'KWD'), WalletTransactionKind::Topup, 'Opening top-up');
        $expected += 50_000;

        for ($i = 0; $i < 60; $i++) {
            $amount = mt_rand(1, 900);

            if (mt_rand(0, 1) === 1 || $amount > $expected) {
                $this->ledger->credit($wallet, Money::ofMinor($amount, 'KWD'), WalletTransactionKind::Refund, "Refund {$i}");
                $expected += $amount;

                continue;
            }

            $this->ledger->debit($wallet, Money::ofMinor($amount, 'KWD'), WalletTransactionKind::Payment, "Payment {$i}");
            $expected -= $amount;
        }

        $wallet->refresh();

        $this->assertSame($expected, $wallet->balance_minor);
        $this->assertTrue($this->ledger->recomputeBalance($wallet)->equals(Money::ofMinor($expected, 'KWD')));

        $report = $this->ledger->reconcile($wallet);
        $this->assertTrue($report->isBalanced());
        $this->assertTrue($report->drift()->isZero());
        $this->assertSame([], $report->divergentEntryIds);
    }

    #[Test]
    public function reconcile_reports_a_corrupted_cache_instead_of_hiding_it(): void
    {
        $wallet = Wallet::factory()->create();
        $this->ledger->credit($wallet, Money::ofMinor(10_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up');

        // Something wrote the balance outside the ledger — the failure mode
        // reconcile() exists for.
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_minor' => 12_500]);

        $report = $this->ledger->reconcile($wallet);

        $this->assertFalse($report->isBalanced());
        $this->assertSame(12_500, $report->cached->minorUnits());
        $this->assertSame(10_000, $report->derived->minorUnits());
        $this->assertSame(2_500, $report->drift()->minorUnits());

        // Reporting is the whole contract: the cache must still be wrong
        // afterwards, so the discrepancy reaches a human.
        $this->assertSame(12_500, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));
    }

    #[Test]
    public function reconcile_reports_an_entry_whose_running_balance_was_tampered_with(): void
    {
        $wallet = Wallet::factory()->create();
        $this->ledger->credit($wallet, Money::ofMinor(4_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up');
        $middle = $this->ledger->credit($wallet, Money::ofMinor(1_000, 'KWD'), WalletTransactionKind::Refund, 'Refund');
        $this->ledger->debit($wallet, Money::ofMinor(500, 'KWD'), WalletTransactionKind::Payment, 'Invoice');

        DB::table('wallet_transactions')->where('id', $middle->id)->update(['balance_after_minor' => 9_999]);

        $report = $this->ledger->reconcile($wallet->refresh());

        // The endpoints still agree — only the chain gives the tampering away.
        $this->assertTrue($report->cached->equals($report->derived));
        $this->assertFalse($report->isBalanced());
        $this->assertSame([$middle->id], $report->divergentEntryIds);
    }

    #[Test]
    public function money_in_another_currency_is_refused(): void
    {
        $wallet = Wallet::factory()->create();

        $this->expectException(CurrencyMismatchException::class);

        $this->ledger->credit($wallet, Money::ofMinor(1_000, 'USD'), WalletTransactionKind::Topup, 'Top-up in the wrong currency');
    }

    #[Test]
    public function a_cross_currency_debit_is_refused_before_anything_is_written(): void
    {
        $wallet = Wallet::factory()->create();
        $this->ledger->credit($wallet, Money::ofMinor(1_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up');

        try {
            $this->ledger->debit($wallet, Money::ofMinor(100, 'USD'), WalletTransactionKind::Payment, 'Invoice in USD');
            $this->fail('Expected the cross-currency debit to be refused.');
        } catch (CurrencyMismatchException) {
            // Expected.
        }

        $this->assertSame(1, DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
        $this->assertSame(1_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));
    }

    #[Test]
    public function a_customer_holds_one_wallet_per_currency(): void
    {
        $customer = Customer::factory()->create();

        $kwd = $this->ledger->walletFor($customer, 'KWD');
        $again = $this->ledger->walletFor($customer, 'kwd');
        $usd = $this->ledger->walletFor($customer, 'USD');

        $this->assertSame($kwd->id, $again->id);
        $this->assertNotSame($kwd->id, $usd->id);
        $this->assertSame(2, DB::table('wallets')->where('customer_id', $customer->id)->count());
    }

    #[Test]
    public function a_repeated_idempotency_key_credits_once(): void
    {
        $wallet = Wallet::factory()->create();

        $first = $this->ledger->credit(
            $wallet,
            Money::ofMinor(7_500, 'KWD'),
            WalletTransactionKind::Topup,
            'Top-up by card ending 4242',
            idempotencyKey: 'psp-charge-01J8ZK',
        );

        // The same call arriving again because the first response was lost.
        $replay = $this->ledger->credit(
            $wallet,
            Money::ofMinor(7_500, 'KWD'),
            WalletTransactionKind::Topup,
            'Top-up by card ending 4242',
            idempotencyKey: 'psp-charge-01J8ZK',
        );

        $this->assertSame($first->id, $replay->id);
        $this->assertSame('psp-charge-01J8ZK', $replay->idempotencyKey());
        $this->assertSame(1, DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
        $this->assertSame(7_500, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));
        $this->assertTrue($this->ledger->reconcile($wallet->refresh())->isBalanced());
    }

    #[Test]
    public function a_different_idempotency_key_credits_again(): void
    {
        $wallet = Wallet::factory()->create();

        $this->ledger->credit($wallet, Money::ofMinor(1_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up', idempotencyKey: 'psp-a');
        $this->ledger->credit($wallet, Money::ofMinor(1_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up', idempotencyKey: 'psp-b');

        $this->assertSame(2_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));
    }

    #[Test]
    public function an_adjustment_without_an_actor_is_refused(): void
    {
        $wallet = Wallet::factory()->create();
        $this->ledger->credit($wallet, Money::ofMinor(2_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up');

        try {
            $this->ledger->credit(
                $wallet,
                Money::ofMinor(500, 'KWD'),
                WalletTransactionKind::Adjustment,
                'Goodwill credit',
            );
            $this->fail('Expected an unattributed adjustment to be refused.');
        } catch (UnattributedAdjustmentException $e) {
            $this->assertSame('wallet.unattributed_adjustment', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }

        $this->assertSame(1, DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
        $this->assertSame(2_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));
    }

    #[Test]
    public function an_adjustment_records_the_operator_who_made_it(): void
    {
        $wallet = Wallet::factory()->create();
        $admin = User::factory()->create();

        $entry = $this->ledger->credit(
            $wallet,
            Money::ofMinor(500, 'KWD'),
            WalletTransactionKind::Adjustment,
            'Goodwill credit after the 12 June outage',
            actor: $admin,
        );

        $this->assertSame($admin->id, $entry->created_by_user_id);
        $this->assertSame('Goodwill credit after the 12 June outage', $entry->description);
    }

    #[Test]
    public function an_entry_must_carry_a_description_an_operator_can_read_back(): void
    {
        $wallet = Wallet::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->ledger->credit($wallet, Money::ofMinor(100, 'KWD'), WalletTransactionKind::Topup, '   ');
    }

    #[Test]
    public function a_non_positive_amount_is_refused(): void
    {
        $wallet = Wallet::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->ledger->credit($wallet, Money::zero('KWD'), WalletTransactionKind::Topup, 'Nothing at all');
    }

    #[Test]
    public function stored_metadata_is_redacted_before_it_reaches_the_column(): void
    {
        $wallet = Wallet::factory()->create();

        $entry = $this->ledger->credit(
            $wallet,
            Money::ofMinor(1_000, 'KWD'),
            WalletTransactionKind::Topup,
            'Top-up by card ending 4242',
            metadata: [
                'provider' => 'fake',
                'token' => 'tok_live_should_never_be_stored',
                'response' => ['note' => 'Authorization: Bearer abcdefghijklmnop'],
            ],
            idempotencyKey: 'psp-charge-redaction',
        );

        $stored = $entry->refresh()->metadata;

        $this->assertSame('fake', $stored['provider']);
        $this->assertSame('[redacted]', $stored['token']);
        // The bearer token is masked in place; the surrounding prose stays
        // readable so an operator can still see what the note was about.
        $this->assertSame('Authorization: [redacted]', $stored['response']['note']);

        // The key the replay check reads is written after redaction, so it
        // survives even though it looks credential-shaped.
        $this->assertSame('psp-charge-redaction', $stored['idempotency_key']);
    }

    #[Test]
    public function caller_metadata_cannot_smuggle_in_an_idempotency_key(): void
    {
        $wallet = Wallet::factory()->create();

        /*
         * The replay check reads one reserved name out of a caller-controlled
         * document. If a caller could plant that name, a one-fil promotional
         * entry carrying the key of a top-up that has not happened yet would
         * make the real top-up look like a replay of itself: the customer's
         * 50 KWD would never reach their balance and the PSP callback would be
         * answered with an entry that has nothing to do with the payment.
         */
        $planted = $this->ledger->credit(
            $wallet,
            Money::ofMinor(1, 'KWD'),
            WalletTransactionKind::Promotional,
            'One fil promotion',
            metadata: ['idempotency_key' => 'psp-charge-01J9AA', 'campaign' => 'launch'],
        );

        $stored = $planted->refresh()->metadata;
        $this->assertSame('launch', $stored['campaign']);
        $this->assertArrayNotHasKey('idempotency_key', $stored);
        $this->assertNull($planted->idempotencyKey());

        $topup = $this->ledger->credit(
            $wallet,
            Money::ofMinor(50_000, 'KWD'),
            WalletTransactionKind::Topup,
            'Top-up by card ending 4242',
            idempotencyKey: 'psp-charge-01J9AA',
        );

        $this->assertNotSame($planted->id, $topup->id);
        $this->assertSame(50_000, $topup->amount_minor);
        $this->assertSame(50_001, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));
        $this->assertSame(2, DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
    }

    #[Test]
    public function an_idempotency_key_that_names_a_different_posting_is_refused(): void
    {
        $wallet = Wallet::factory()->create();

        $this->ledger->credit(
            $wallet,
            Money::ofMinor(10_000, 'KWD'),
            WalletTransactionKind::Topup,
            'Top-up',
            idempotencyKey: 'shared-key',
        );

        /*
         * Answering this debit with the earlier credit would report an invoice
         * as settled while nothing left the wallet. A key collision has to be
         * loud.
         */
        try {
            $this->ledger->debit(
                $wallet,
                Money::ofMinor(500, 'KWD'),
                WalletTransactionKind::Payment,
                'Applied to invoice LYN-000003',
                idempotencyKey: 'shared-key',
            );
            $this->fail('Expected a colliding idempotency key to be refused.');
        } catch (IdempotencyKeyConflictException $e) {
            $this->assertSame('wallet.idempotency_key_conflict', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        $this->assertSame(1, DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
        $this->assertSame(10_000, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));

        // The genuine retry of the original posting still replays cleanly.
        $replay = $this->ledger->credit(
            $wallet,
            Money::ofMinor(10_000, 'KWD'),
            WalletTransactionKind::Topup,
            'Top-up',
            idempotencyKey: 'shared-key',
        );

        $this->assertSame(1, DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
        $this->assertSame(10_000, $replay->amount_minor);
    }

    #[Test]
    public function a_credit_can_repair_a_wallet_whose_balance_went_negative(): void
    {
        $wallet = Wallet::factory()->create();
        $admin = User::factory()->create();

        // However it got there — a lost write, a bug since fixed — a wallet in
        // the red has to be repairable, and the repair is a credit. Refusing
        // it because the resulting balance is still below zero would leave the
        // only remedy unusable.
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_minor' => -1_500]);

        $entry = $this->ledger->credit(
            $wallet->refresh(),
            Money::ofMinor(1_000, 'KWD'),
            WalletTransactionKind::Adjustment,
            'Partial correction of the 3 June lost write',
            actor: $admin,
        );

        $this->assertSame(-500, $entry->balance_after_minor);
        $this->assertSame(-500, (int) DB::table('wallets')->where('id', $wallet->id)->value('balance_minor'));

        // A debit against that wallet is still refused: only debits may breach
        // the floor, and this one would take it further under.
        $this->expectException(InsufficientWalletBalanceException::class);
        $this->ledger->debit($wallet, Money::ofMinor(1, 'KWD'), WalletTransactionKind::Payment, 'Invoice');
    }

    #[Test]
    public function a_posted_entry_can_neither_be_edited_nor_removed(): void
    {
        $wallet = Wallet::factory()->create();
        $entry = $this->ledger->credit($wallet, Money::ofMinor(1_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up');

        $entry->amount_minor = 99_999;

        try {
            $entry->save();
            $this->fail('Expected a posted ledger entry to be immutable.');
        } catch (LedgerEntryIsImmutableException $e) {
            $this->assertSame('wallet.ledger_entry_immutable', $e->errorCode());
        }

        try {
            $entry->delete();
            $this->fail('Expected a posted ledger entry to be undeletable.');
        } catch (LedgerEntryIsImmutableException) {
            // Expected.
        }

        $this->assertSame(1_000, (int) DB::table('wallet_transactions')->where('id', $entry->id)->value('amount_minor'));
        $this->assertSame(1, DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->count());
    }

    #[Test]
    public function a_wallet_currency_is_stored_normalised(): void
    {
        $customer = Customer::factory()->create();

        $wallet = new Wallet(['customer_id' => $customer->id, 'currency' => 'usd', 'balance_minor' => 0]);
        $wallet->save();

        $this->assertSame('USD', DB::table('wallets')->where('id', $wallet->id)->value('currency'));

        // The wallet's own currency must never read as a mismatch against
        // itself, which is what made a differently-cased row unusable.
        $entry = $this->ledger->credit($wallet, Money::ofMinor(250, 'USD'), WalletTransactionKind::Topup, 'Top-up');

        $this->assertSame(250, $entry->balance_after_minor);
    }

    #[Test]
    public function reconcile_is_not_fooled_by_an_entry_that_lands_while_it_reads(): void
    {
        /*
         * reconcile() reads two things — the ledger and the cached balance —
         * and a top-up committing between them is counted by one and missed by
         * the other. The wallet is consistent throughout; only a torn read
         * makes it look corrupt. A reconciler that cries wolf on a live wallet
         * is worse than none, because the real alarm gets ignored with it.
         */
        foreach (['recon_a', 'recon_b'] as $name) {
            config()->set("database.connections.{$name}", config('database.connections.pgsql'));
        }

        // RefreshDatabase never commits the default connection, so fixtures the
        // second connection has to see are committed here and cleaned up below.
        $customer = Customer::factory()->make();
        $customer->setConnection('recon_a')->save();

        try {
            $wallet = new Wallet(['customer_id' => $customer->id, 'currency' => 'KWD', 'balance_minor' => 0]);
            $wallet->setConnection('recon_a')->save();

            $this->ledger->credit($wallet, Money::ofMinor(1_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up 1');
            $this->ledger->credit($wallet, Money::ofMinor(1_000, 'KWD'), WalletTransactionKind::Topup, 'Top-up 2');

            // Without a lock timeout the contender would wait on a lock only
            // the caller further up this same PHP stack can release.
            DB::connection('recon_b')->statement("SET lock_timeout = '1s'");

            $contender = Wallet::on('recon_b')->findOrFail($wallet->id);
            $fired = false;
            $outcome = null;

            // Fires while reconcile() is streaming the ledger and before it has
            // read the cached balance.
            WalletTransaction::retrieved(function () use (&$fired, &$outcome, $contender): void {
                if ($fired) {
                    return;
                }

                $fired = true;

                try {
                    $this->ledger->credit(
                        $contender,
                        Money::ofMinor(500, 'KWD'),
                        WalletTransactionKind::Topup,
                        'Top-up landing mid-reconciliation',
                    );
                    $outcome = 'committed';
                } catch (QueryException) {
                    $outcome = 'blocked';
                }
            });

            $report = $this->ledger->reconcile($wallet);

            $this->assertTrue($fired, 'The interleaved top-up never ran, so this proves nothing.');
            $this->assertSame('blocked', $outcome);
            $this->assertTrue(
                $report->isBalanced(),
                'A consistent wallet must not be reported as drifting because a top-up committed mid-scan.'
            );
            $this->assertSame(2_000, $report->cached->minorUnits());
            $this->assertSame(2_000, $report->derived->minorUnits());
        } finally {
            WalletTransaction::flushEventListeners();
            DB::connection('recon_b')->statement('SET lock_timeout = 0');
            // wallets and wallet_transactions cascade from the customer.
            Customer::on('recon_a')->whereKey($customer->id)->forceDelete();
        }
    }
}
