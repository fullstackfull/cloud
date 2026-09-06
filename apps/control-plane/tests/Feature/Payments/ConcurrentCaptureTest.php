<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\RecordPaymentCapture;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two workers, two real database connections, one payment.
 *
 * This test deliberately does not use RefreshDatabase. That trait wraps the
 * whole test in a transaction on one connection, which is precisely what makes
 * a concurrency test meaningless: a second connection cannot see the fixtures,
 * and two queries issued on a single connection are serialised by definition,
 * so they can never race. Rows are therefore committed for real and removed
 * again in tearDown.
 *
 * The window under test is the one a check-then-insert cannot close. Worker A
 * looks for an existing transaction, finds none, and is interrupted before its
 * insert; worker B — on its own connection, in its own transaction — records
 * the same capture and commits. A's insert then meets a unique index violation
 * raised by a row A never saw, which is the only thing standing between a
 * redelivered webhook and a customer charged twice in the ledger.
 */
final class ConcurrentCaptureTest extends TestCase
{
    private const string SECOND_CONNECTION = 'worker_b';

    private const string REFERENCE = 'fake_pi_succeeded_KWD_9000_concur01';

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();

        // A genuinely separate connection to the same database: the same
        // credentials, a different PDO handle and therefore a different
        // transaction.
        config([
            'database.connections.'.self::SECOND_CONNECTION => config(
                'database.connections.'.config('database.default'),
            ),
        ]);

        $this->customer = Customer::factory()->create();
    }

    protected function tearDown(): void
    {
        DB::purge(self::SECOND_CONNECTION);
        $this->wipe();

        parent::tearDown();
    }

    #[Test]
    public function two_workers_recording_the_same_capture_produce_one_transaction(): void
    {
        Event::fake([PaymentCaptured::class]);

        $default = (string) config('database.default');
        $interleaved = false;

        Event::listen('eloquent.creating: '.Transaction::class, function () use (&$interleaved, $default): void {
            if ($interleaved) {
                return;
            }

            $interleaved = true;

            /*
             * Worker B runs to completion — including its commit — while
             * worker A sits between its locked read and its insert. Swapping
             * the default connection is what puts B on its own handle: A's
             * transaction belongs to the connection instance it began on and
             * is untouched by the switch.
             */
            DB::setDefaultConnection(self::SECOND_CONNECTION);

            try {
                app(RecordPaymentCapture::class)->execute('fake', $this->captureEvent());
            } finally {
                DB::setDefaultConnection($default);
            }
        });

        $transaction = app(RecordPaymentCapture::class)->execute('fake', $this->captureEvent());

        $this->assertTrue($interleaved, 'The second worker never ran inside the window under test.');

        // One payment, one row — enforced by the unique index rather than by
        // the read that both workers passed.
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(self::REFERENCE, $transaction->provider_reference);
        $this->assertSame(TransactionStatus::Succeeded, $transaction->status);
        $this->assertTrue($transaction->amount()->equals(Money::ofMinor(9000, 'KWD')));

        // And one settlement announced. A second PaymentCaptured would settle
        // the invoice twice however carefully the ledger row was deduplicated.
        Event::assertDispatchedTimes(PaymentCaptured::class, 1);
    }

    #[Test]
    public function the_loser_of_the_race_converges_on_the_row_the_winner_wrote(): void
    {
        $default = (string) config('database.default');
        $winnerId = null;
        $interleaved = false;

        // The flag is raised before the nested call, not after it: worker B
        // creates a transaction row of its own, which re-enters this listener.
        Event::listen('eloquent.creating: '.Transaction::class, function () use (&$winnerId, &$interleaved, $default): void {
            if ($interleaved) {
                return;
            }

            $interleaved = true;

            DB::setDefaultConnection(self::SECOND_CONNECTION);

            try {
                $winnerId = app(RecordPaymentCapture::class)->execute('fake', $this->captureEvent())->id;
            } finally {
                DB::setDefaultConnection($default);
            }
        });

        $loser = app(RecordPaymentCapture::class)->execute('fake', $this->captureEvent());

        // The retry re-reads rather than inventing a second row, so the caller
        // that lost the race still gets the transaction its event refers to.
        $this->assertNotNull($winnerId);
        $this->assertSame($winnerId, $loser->id);
        $this->assertSame(1, Transaction::query()->count());

        /*
         * This assertion is what separates a real race from a simulation. Had
         * both workers shared one connection, B's insert would have been a
         * savepoint inside A's transaction and A's rollback would have erased
         * it — A would then have inserted a row of its own and the surviving
         * id would be A's, not B's. The winner's row outliving the loser's
         * rollback is only possible across two committed transactions.
         */
        $this->assertNotSame(
            DB::connection((string) config('database.default'))->getPdo(),
            DB::connection(self::SECOND_CONNECTION)->getPdo(),
        );
    }

    private function captureEvent(): ProviderEvent
    {
        return new ProviderEvent(
            providerEventId: 'evt_concurrent_1',
            type: 'payment.succeeded',
            kind: ProviderEventKind::PaymentSucceeded,
            amount: Money::ofMinor(9000, 'KWD'),
            currency: 'KWD',
            providerReference: self::REFERENCE,
            payload: ['data' => ['reference' => self::REFERENCE]],
            metadata: ['customer_id' => $this->customer->id],
        );
    }

    /**
     * Committed rows outlive the test, so they are removed either side of it
     * rather than rolled back.
     */
    private function wipe(): void
    {
        DB::statement('truncate table webhook_events, refunds, transactions, customers restart identity cascade');
    }
}
