<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Domain\DTOs\SignedWebhookPayload;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payments\Doubles\TimingOutPaymentProvider;

/**
 * What happens after the provider has said no.
 *
 * The endpoint's repeat-safety comes from reusing the open attempt's id as the
 * provider's idempotency key, which is exactly right while the payment is still
 * live: a double-clicked pay button must not open two payments. It stops being
 * right the moment the provider has answered. An idempotency key that has been
 * answered is answered forever — a real provider replays its stored response
 * rather than doing the work again — so replaying the key of a refused intent
 * hands the customer back the refusal, whatever card they reach for next.
 *
 * Nothing marks the attempt closed when that refusal arrives: RecordPaymentFailure
 * writes the ledger row and does not touch payment_attempts. So the attempt is
 * still "pending" long after the payment behind it is dead, and these tests are
 * about the endpoint noticing that for itself rather than trusting the flag.
 */
final class RetryingARefusedPaymentTest extends PaymentsApiTestCase
{
    #[Test]
    public function a_payment_the_provider_refused_can_be_paid_with_another_card(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $first = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        $firstReference = (string) $first->json('data.reference');
        $firstPaymentId = (string) $first->json('data.payment.id');
        $firstAttempt = PaymentAttempt::query()->sole();

        /*
         * The customer fails the challenge at the provider's end. The news
         * arrives the only way the platform accepts it — a signed webhook —
         * so the fixture is built by the module's own confirmation path rather
         * than by the code under test.
         */
        $this->deliverWebhook($this->fakeProvider()->emitWebhook(
            ProviderEventKind::PaymentFailed,
            $firstReference,
            Money::ofMinor(9000, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
            failureCode: 'card_declined',
        ))->assertOk();

        $this->assertSame(TransactionStatus::Failed, Transaction::query()->sole()->status);

        // Nothing closed the attempt: the endpoint has to work that out from
        // the ledger, not from the flag.
        $this->assertSame(PaymentAttemptStatus::Pending, $firstAttempt->refresh()->status);

        $second = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        // A refused intent must not be replayed. A new key at the provider, so
        // a new intent, so a card that works can actually be charged.
        $this->assertNotSame($firstReference, $second->json('data.reference'));
        $this->assertNotSame($firstPaymentId, $second->json('data.payment.id'));
        $this->assertSame(TransactionStatus::Pending->value, $second->json('data.payment.status'));
        $this->assertSame('redirect', $second->json('data.next_action.type'));
        $this->assertSame(2, $second->json('meta.attempt_number'));

        // The refusal stays recorded rather than being rewritten as pending:
        // dunning counts refusals, and support has to be able to answer why a
        // payment did not go through.
        $refused = Transaction::query()->findOrFail($firstPaymentId);
        $this->assertSame(TransactionStatus::Failed, $refused->status);
        $this->assertSame('card_declined', $refused->failure_code);

        $this->assertSame(PaymentAttemptStatus::Failed, $firstAttempt->refresh()->status);
        $this->assertSame(2, PaymentAttempt::query()->count());
    }

    #[Test]
    public function a_refusal_that_arrives_after_a_timeout_is_not_rewritten_as_pending(): void
    {
        $provider = new TimingOutPaymentProvider;
        app(PaymentProviderRegistry::class)->swap(FakePaymentProvider::NAME, $provider);

        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        // The provider creates the intent and then stops answering. The
        // platform has stopped waiting; the provider has not stopped working.
        $provider->timeOutNextCall();

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertStatus(502);

        // The attempt survives a timeout on purpose — its id is the key the
        // provider may already have seen — and there is no ledger row yet.
        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->status);
        $this->assertNull($attempt->transaction_id);
        $this->assertSame(0, Transaction::query()->count());

        // The intent the platform never saw is refused, and the provider says so.
        $reference = $provider->unseenReference();

        $this->deliverWebhook($this->fakeProvider()->emitWebhook(
            ProviderEventKind::PaymentFailed,
            $reference,
            Money::ofMinor(9000, 'KWD'),
            metadata: ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
            failureCode: 'card_declined',
        ))->assertOk();

        $this->assertSame(TransactionStatus::Failed, Transaction::query()->sole()->status);

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        /*
         * The retry replays the same key, so the provider hands back the same
         * refused intent. Writing "pending" over the recorded refusal would
         * un-say it: the invoice would look like it had a live payment against
         * an intent the provider has already closed.
         */
        $recorded = Transaction::query()->where('provider_reference', $reference)->sole();
        $this->assertSame(TransactionStatus::Failed, $recorded->status);
        $this->assertSame('card_declined', $recorded->failure_code);
    }

    private function fakeProvider(): FakePaymentProvider
    {
        return new FakePaymentProvider;
    }

    private function deliverWebhook(SignedWebhookPayload $signed): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($signed->headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call(
            'POST',
            route('webhooks.receive', ['provider' => 'fake']),
            server: $server,
            content: $signed->rawPayload,
        );
    }
}
