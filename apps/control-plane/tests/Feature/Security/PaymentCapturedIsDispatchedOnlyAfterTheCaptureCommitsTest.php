<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: PaymentCaptured must not leave while the capture is uncommitted.
 *
 * RecordPaymentCapture used to call event() as soon as its own inner
 * DB::transaction closed. That is not the outermost transaction on the path
 * every real capture takes: IngestWebhookEvent::execute() wraps
 * handleUnderLock() in DB::transaction(), so the event left at transaction
 * level 1 with the capture row still invisible to every other connection.
 *
 * SettleInvoiceOnPaymentCaptured is `implements ShouldQueue`, every connection
 * in config/queue.php sets `'after_commit' => false`, and production runs
 * QUEUE_CONNECTION=redis — a store with no knowledge of the PostgreSQL
 * transaction at all. A worker that dequeued before the commit found
 * Transaction::find() returning null. The webhook had already answered 200 and
 * the webhook_events row was Processed, so nothing redelivered: money
 * captured, invoice never settled, order never fulfilled, no retry.
 *
 * The dispatch now goes through DB::afterCommit(), the same way SettleInvoice
 * already announces InvoicePaid. This test does not try to win the race: it
 * holds a genuinely separate connection open and asks it, at the moment the
 * job would be pushed, whether the capture is readable yet.
 */
final class PaymentCapturedIsDispatchedOnlyAfterTheCaptureCommitsTest extends TestCase
{
    use RefreshDatabase;

    private const string WORKER_CONNECTION = 'pgsql_queue_worker';

    /**
     * No wrapping transaction: the whole question is what a second connection
     * can see, and RefreshDatabase's own transaction would hide the rows for
     * an entirely different reason and make the test prove nothing.
     *
     * @var list<string>
     */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.'.self::WORKER_CONNECTION => config(
                'database.connections.'.config('database.default')
            ),
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge(self::WORKER_CONNECTION);
        DB::statement('TRUNCATE customers, users, webhook_events RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    #[Test]
    public function a_queue_worker_on_another_connection_can_see_the_capture_the_job_names(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()
            ->open()
            ->totalling(Money::ofMinor(10350, 'KWD'))
            ->create(['customer_id' => $customer->id]);

        $observed = [];

        // Stands in for the queue worker: it runs at the moment the job would
        // be pushed, and it reads through its own connection.
        Event::listen(function (PaymentCaptured $event) use (&$observed): void {
            $worker = DB::connection(self::WORKER_CONNECTION);

            $observed = [
                'transaction_level' => DB::connection()->transactionLevel(),
                'capture_visible' => $worker->table('transactions')
                    ->where('id', $event->transactionId)
                    ->exists(),
            ];
        });

        /** @var FakePaymentProvider $provider */
        $provider = app(PaymentProviderRegistry::class)->get('fake');

        $signed = $provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_uncommitted_dispatch',
            Money::ofMinor(10350, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
        );

        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($signed->headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->call(
            'POST',
            route('webhooks.receive', ['provider' => 'fake']),
            server: $server,
            content: $signed->rawPayload,
        )->assertOk();

        $this->assertNotSame([], $observed, 'PaymentCaptured was never dispatched.');

        $this->assertSame(
            0,
            $observed['transaction_level'],
            'PaymentCaptured must not be dispatched inside an open database transaction.',
        );

        $this->assertTrue(
            $observed['capture_visible'],
            'A worker picking the job up at dispatch time must be able to read the capture; '
            .'otherwise the listener finds nothing to settle and the charge stands with no service.',
        );

        // And the settlement the job exists to perform actually happened.
        $this->assertSame(10350, (int) $invoice->fresh()->amount_paid_minor);
    }
}
