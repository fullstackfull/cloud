<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One balance, several payments, at the same instant.
 *
 * This cannot be tested inside one PHP process. A single process does one
 * thing at a time, so a "race" written as two sequential calls proves only
 * that a program cannot race itself — which nobody doubted. So the payments
 * run as real operating system processes, started at one agreed instant,
 * against rows that are actually committed.
 *
 * Committed is the other half of it: `RefreshDatabase` wraps a test in a
 * transaction that no other connection can see, so fixtures written the usual
 * way would be invisible to the children and every one of them would fail on a
 * missing customer. They are written on a second connection and removed in the
 * teardown.
 *
 * The three properties, and each of them is a way somebody loses money:
 *
 *  1. A balance never goes below zero. The floor is checked after the lock,
 *     inside the transaction, so a debit that would breach it writes nothing.
 *  2. The same credit is never spent twice — the total applied across every
 *     process is at most what the wallet held.
 *  3. An invoice is never settled beyond what it was owed.
 */
final class WalletRaceTest extends TestCase
{
    /** A second connection to the same database, outside the test transaction. */
    private const string CONNECTION = 'wallet_race';

    /** Enough contenders that an overlap is not a matter of luck. */
    private const int CONTENDERS = 6;

    /** @var list<Model> */
    private array $committed = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.'.self::CONNECTION, config('database.connections.pgsql'));
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->committed) as $model) {
            try {
                $model->newQueryWithoutScopes()->whereKey($model->getKey())->forceDelete();
            } catch (\Throwable) {
                // A row a cascade already took with its parent.
            }
        }

        $this->committed = [];

        parent::tearDown();
    }

    #[Test]
    public function six_payments_against_one_balance_spend_it_once(): void
    {
        /** @var array{0: Customer, 1: Invoice} $fixtures */
        $fixtures = $this->outsideTheTransaction(function (): array {
            $customer = $this->committed(Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']));

            $user = $this->committed(User::factory()->create());
            $this->committed($customer->members()->create([
                'user_id' => $user->getKey(),
                'role' => CustomerRole::Owner,
                'accepted_at' => now(),
            ]));

            $wallet = $this->committed(Wallet::query()->create([
                'customer_id' => $customer->getKey(),
                'currency' => 'KWD',
                'balance_minor' => 0,
            ]));

            // Through the ledger, so the entry and the cached balance agree —
            // a balance written straight into the column would make the first
            // debit's recomputation disagree with it.
            $this->committed(app(WalletLedger::class)->credit(
                wallet: $wallet,
                amount: Money::ofMinor(5_000, 'KWD'),
                kind: WalletTransactionKind::Topup,
                description: 'Committed for the race',
            ));

            // Owed far more than the balance, so every process wants the whole
            // of it and the only limit is what is there.
            $invoice = $this->committed(Invoice::factory()->create([
                'customer_id' => $customer->getKey(),
                'currency' => 'KWD',
                'subtotal_minor' => 100_000,
                'total_minor' => 100_000,
            ]));

            return [$customer, $invoice];
        });

        [$customer, $invoice] = $fixtures;

        $results = $this->race((string) $customer->getKey(), (string) $invoice->getKey());

        $settled = array_values(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'settled'));

        /*
         * Exactly one, and this is the assertion that makes the test a race
         * rather than a formality.
         *
         * The invoice is owed far more than the wallet holds, so whoever wins
         * takes the whole balance and the other five find an empty wallet.
         * Two winners would mean two processes each read 5000 fils and each
         * spent it — the exact failure the row lock exists to prevent — and
         * the assertions below would still pass if the losers had merely been
         * slow rather than refused.
         */
        $this->assertCount(1, $settled, 'The same credit was spent by more than one process.');

        $onSecondConnection = DB::connection(self::CONNECTION);

        $balance = (int) $onSecondConnection->table('wallets')
            ->where('customer_id', $customer->getKey())->value('balance_minor');

        $this->assertGreaterThanOrEqual(0, $balance, 'A wallet balance went below zero.');

        $spent = (int) $onSecondConnection->table('wallet_transactions')
            ->where('wallet_id', $onSecondConnection->table('wallets')
                ->where('customer_id', $customer->getKey())->value('id'))
            ->where('kind', WalletTransactionKind::Payment->value)
            ->sum('amount_minor');

        // Debits are negative. Five thousand fils existed; no more than five
        // thousand may have left.
        $this->assertGreaterThanOrEqual(-5_000, $spent, 'More was spent than the wallet ever held.');
        $this->assertSame(5_000 + $spent, $balance, 'The balance and the ledger disagree.');

        $paid = (int) $onSecondConnection->table('invoices')
            ->where('id', $invoice->getKey())->value('amount_paid_minor');

        $this->assertSame(-$spent, $paid, 'The invoice was credited with something the wallet did not pay.');

        // Every process that reported success reported the same figure it
        // actually moved, so a caller cannot be told it paid more than it did.
        $this->assertSame(
            $paid,
            array_sum(array_map(static fn (array $r): int => (int) $r['applied_minor'], $settled)),
        );
    }

    #[Test]
    public function six_copies_of_one_request_spend_it_once(): void
    {
        /** @var array{0: Customer, 1: Invoice} $fixtures */
        $fixtures = $this->outsideTheTransaction(function (): array {
            $customer = $this->committed(Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']));

            $wallet = $this->committed(Wallet::query()->create([
                'customer_id' => $customer->getKey(),
                'currency' => 'KWD',
                'balance_minor' => 0,
            ]));

            $this->committed(app(WalletLedger::class)->credit(
                wallet: $wallet,
                amount: Money::ofMinor(9_000, 'KWD'),
                kind: WalletTransactionKind::Topup,
                description: 'Committed for the race',
            ));

            $invoice = $this->committed(Invoice::factory()->create([
                'customer_id' => $customer->getKey(),
                'currency' => 'KWD',
                'subtotal_minor' => 9_000,
                'total_minor' => 9_000,
            ]));

            return [$customer, $invoice];
        });

        [$customer, $invoice] = $fixtures;

        // The same key on every process: a customer double-clicking, or a
        // client library retrying while the first request is still in flight.
        $results = $this->race((string) $customer->getKey(), (string) $invoice->getKey(), 'one-key-many-clicks');

        $this->assertNotEmpty(array_filter($results, static fn (array $r): bool => $r['outcome'] === 'settled'));

        $onSecondConnection = DB::connection(self::CONNECTION);
        $walletId = $onSecondConnection->table('wallets')->where('customer_id', $customer->getKey())->value('id');

        $this->assertSame(
            1,
            (int) $onSecondConnection->table('wallet_transactions')
                ->where('wallet_id', $walletId)
                ->where('kind', WalletTransactionKind::Payment->value)
                ->count(),
            'One key must post one debit however many times it is sent.',
        );

        // And one charge. A second charge attached to nothing would read to the
        // rest of billing as money that arrived and was never applied.
        $this->assertSame(
            1,
            (int) $onSecondConnection->table('transactions')
                ->where('customer_id', $customer->getKey())
                ->where('provider', 'wallet')
                ->count(),
        );

        $this->assertSame(0, (int) $onSecondConnection->table('wallets')
            ->where('id', $walletId)->value('balance_minor'));
    }

    /**
     * Starts the contenders at one agreed instant and collects what they said.
     *
     * @return list<array<string, mixed>>
     */
    private function race(string $customerId, string $invoiceId, ?string $sharedKey = null): array
    {
        $script = __DIR__.'/support/pay-from-wallet.php';
        $startAt = microtime(true) + 1.5;

        $processes = [];
        $pipes = [];

        for ($i = 0; $i < self::CONTENDERS; $i++) {
            $key = $sharedKey ?? sprintf('race-key-%02d-%s', $i, substr($invoiceId, -8));

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $process = proc_open(
                ['php', $script, $customerId, $invoiceId, $key, (string) $startAt],
                $descriptors,
                $handles,
                base_path(),
                $this->environment(),
            );

            if ($process === false) {
                $this->fail('Could not start a contender.');
            }

            $processes[] = $process;
            $pipes[] = $handles;
        }

        $results = [];

        foreach ($processes as $index => $process) {
            $out = trim((string) stream_get_contents($pipes[$index][1]));
            $err = trim((string) stream_get_contents($pipes[$index][2]));

            fclose($pipes[$index][1]);
            fclose($pipes[$index][2]);
            proc_close($process);

            if ($out === '') {
                $this->fail(sprintf('A contender printed nothing. Standard error was: %s', $err));
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * What the children run with.
     *
     * Built by name rather than by spreading `$_SERVER`, which carries `argv`
     * as an array and would be converted to the string "Array" on its way into
     * the child — a failure that reads as a mystifying warning rather than as
     * a bad environment.
     *
     * `APP_ENV=testing` so the child reads `.env.testing` and reaches the same
     * database this test is committing to. Without it the children would
     * happily pay invoices in the development database, and the test would
     * report that nothing had been settled.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $environment = ['APP_ENV' => 'testing'];

        foreach (['PATH', 'HOME', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function committed(Model $model): Model
    {
        $this->committed[] = $model;

        return $model;
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $build
     * @return TResult
     */
    private function outsideTheTransaction(callable $build): mixed
    {
        $previous = DB::getDefaultConnection();

        DB::setDefaultConnection(self::CONNECTION);

        Event::listen('eloquent.created: *', function (string $event, array $payload): void {
            foreach ($payload as $model) {
                if ($model instanceof Model && ! $model instanceof WalletTransaction) {
                    $this->committed[] = $model;
                }
            }
        });

        try {
            return $build();
        } finally {
            Event::forget('eloquent.created: *');

            DB::setDefaultConnection($previous);
        }
    }
}
