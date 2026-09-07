<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/invoices/{invoice}/payments.
 */
final class StartInvoicePaymentEndpointTest extends PaymentsApiTestCase
{
    #[Test]
    public function starting_a_payment_returns_what_the_browser_must_do_next(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.payment.status', TransactionStatus::Pending->value)
            ->assertJsonPath('data.payment.kind', 'charge')
            ->assertJsonPath('data.payment.invoice_id', $invoice->id)
            ->assertJsonPath('data.payment.amount.minor_units', 9000)
            ->assertJsonPath('data.payment.amount.currency', 'KWD')
            // Three minor digits, because KWD has three. A client must never
            // have to divide by a number it guessed.
            ->assertJsonPath('data.payment.amount.amount', '9.000')
            ->assertJsonPath('data.next_action.type', 'redirect')
            ->assertJsonPath('meta.invoice_id', $invoice->id)
            ->assertJsonPath('meta.attempt_number', 1);

        $this->assertIsString($response->json('data.next_action.redirect_url'));
        $this->assertIsString($response->json('data.reference'));
    }

    #[Test]
    public function starting_a_payment_does_not_pay_the_invoice(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        // The single rule the rest of the platform depends on. An intent is a
        // question asked of a provider, not an answer, and nothing downstream
        // may act on it.
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid_minor);
        $this->assertNull($invoice->paid_at);

        $this->assertSame(TransactionStatus::Pending, Transaction::query()->sole()->status);
        $this->assertSame(PaymentAttemptStatus::Pending, PaymentAttempt::query()->sole()->status);
    }

    #[Test]
    public function the_amount_comes_from_the_invoice_and_never_from_the_request(): void
    {
        $recorder = $this->recordingProvider();

        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(12500, 'KWD'));

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
                // Everything a client might try in order to name its own price.
                'amount' => 1,
                'amount_minor' => 1,
                'total_minor' => 1,
                'currency' => 'USD',
            ])
            ->assertCreated()
            ->assertJsonPath('data.payment.amount.minor_units', 12500)
            ->assertJsonPath('data.payment.amount.currency', 'KWD');

        $intent = $recorder->onlyIntent();

        $this->assertSame(12500, $intent->amount->minorUnits());
        $this->assertSame('KWD', $intent->amount->currency());

        // And the ledger records the same figure, not the one that was posted.
        $this->assertSame(12500, Transaction::query()->sole()->amount_minor);
    }

    #[Test]
    public function the_payment_is_attributed_to_the_acting_account_and_its_invoice(): void
    {
        $recorder = $this->recordingProvider();

        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        // The metadata is how a webhook is later credited to an account
        // without trusting whoever delivered it, so it must carry the account
        // the middleware resolved rather than anything from the request.
        $this->assertSame(
            ['customer_id' => $customer->id, 'invoice_id' => $invoice->id],
            $recorder->onlyIntent()->metadata,
        );
    }

    #[Test]
    public function an_invoice_that_is_already_paid_cannot_start_a_second_payment(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()->for($customer)->paid()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.not_payable');

        $this->assertSame(0, PaymentAttempt::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_draft_invoice_cannot_be_paid(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // Never issued, so nothing is owed yet and a payment against it would
        // be money the platform cannot account for.
        $invoice = Invoice::factory()->for($customer)->create();

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.not_payable');

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function another_customers_invoice_is_not_found(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $invoice = $this->openInvoice($theirs);

        // 404 rather than 403: a 403 would confirm the id exists, which on
        // ULIDs is an enumeration oracle.
        $theirInvoice = $this->actingAs($mine)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $invented = $this->actingAs($mine)
            ->postJson('/api/v1/invoices/01JZZZZZZZZZZZZZZZZZZZZZZZ/payments')
            ->assertNotFound();

        // Byte for byte identical, or the difference sorts real ids from
        // invented ones.
        $this->assertSame($invented->json('error.code'), $theirInvoice->json('error.code'));
        $this->assertSame($invented->json('error.message'), $theirInvoice->json('error.message'));

        // And nothing was charged, opened or recorded against them.
        $this->assertSame(0, PaymentAttempt::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_malformed_return_url_is_a_validation_failure(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['return_url' => 'not a url'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed');

        $this->assertArrayHasKey('return_url', $response->json('error.details.fields'));
        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function repeating_the_request_describes_the_same_payment(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $first = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        // A double-clicked pay button, or a retry over a flaky connection.
        $second = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        $this->assertSame($first->json('data.payment.id'), $second->json('data.payment.id'));
        // The same provider idempotency key produces the same intent, so the
        // customer is never looking at two live payments for one invoice.
        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));

        $this->assertSame(1, PaymentAttempt::query()->count());
        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function a_repeat_uses_the_same_idempotency_key_at_the_provider(): void
    {
        $recorder = $this->recordingProvider();

        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments")->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/invoices/{$invoice->id}/payments")->assertCreated();

        $this->assertCount(2, $recorder->intents);
        // Same key, so a provider that already took this request replays its
        // answer instead of opening a second payment.
        $this->assertSame($recorder->intents[0]->idempotencyKey, $recorder->intents[1]->idempotencyKey);
        $this->assertSame(PaymentAttempt::query()->sole()->id, $recorder->intents[0]->idempotencyKey);
    }

    #[Test]
    public function an_invoice_whose_capture_is_recorded_but_not_yet_settled_refuses_a_second_payment(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        /*
         * The window between the webhook recording a capture and the listener
         * settling the invoice. The document is still open, so the status
         * check alone would happily open a second payment and collect the same
         * invoice twice.
         */
        $transaction = Transaction::query()->sole();
        $transaction->forceFill(['status' => TransactionStatus::Succeeded, 'processed_at' => now()])->save();

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'payment.already_captured');

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    #[Test]
    public function an_attempt_for_a_stale_amount_is_abandoned_rather_than_reused(): void
    {
        $recorder = $this->recordingProvider();

        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        // Part of the invoice was settled elsewhere — a credit, a wallet
        // payment — so the open attempt no longer describes what is owed.
        $invoice->forceFill(['amount_paid_minor' => 4000])->save();

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.payment.amount.minor_units', 5000)
            ->assertJsonPath('meta.attempt_number', 2);

        // Reusing the first key would make the provider replay an intent for
        // the old amount; a new key is opened instead, and the stale attempt
        // is closed rather than left looking live.
        $this->assertCount(2, $recorder->intents);
        $this->assertNotSame($recorder->intents[0]->idempotencyKey, $recorder->intents[1]->idempotencyKey);
        $this->assertSame(5000, $recorder->intents[1]->amount->minorUnits());

        $this->assertSame(
            PaymentAttemptStatus::Abandoned,
            PaymentAttempt::query()->find($recorder->intents[0]->idempotencyKey)?->status,
        );
    }

    #[Test]
    public function a_decline_is_reported_rather_than_thrown(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // The fake provider declines by amount, so this is a real decline
        // through the real adapter rather than a stubbed exception.
        $declined = FakePaymentProvider::declineAmount(Money::ofMinor(9000, 'KWD'));
        $invoice = $this->openInvoice($customer, $declined);

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.payment.status', TransactionStatus::Failed->value)
            ->assertJsonPath('data.failure_code', 'card_declined')
            // Nothing for the browser to do, and no bearer credential handed
            // out for a payment that cannot proceed.
            ->assertJsonPath('data.next_action.type', 'none')
            ->assertJsonPath('data.next_action.client_secret', null);

        $this->assertSame(PaymentAttemptStatus::Failed, PaymentAttempt::query()->sole()->status);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid_minor);
    }

    #[Test]
    public function a_member_without_billing_permission_cannot_start_a_payment(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $member = $this->memberOf($customer, CustomerRole::Member);
        $invoice = $this->openInvoice($customer);

        $this->actingAs($member)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->assertSame(0, Transaction::query()->count());

        // The same account, the same invoice, a role that may spend.
        $this->actingAs($owner)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();
    }

    #[Test]
    public function nothing_internal_is_returned_with_the_payment(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        $payment = $response->json('data.payment');

        $this->assertArrayNotHasKey('customer_id', $payment);
        $this->assertArrayNotHasKey('provider_reference', $payment);
        $this->assertArrayNotHasKey('provider_metadata', $payment);
        $this->assertArrayNotHasKey('credentials_reference', $payment);
        $this->assertArrayNotHasKey('internal_notes', $payment);

        // The provider's own response object is kept for operators and is not
        // part of any promise to a customer.
        $this->assertNotNull(Transaction::query()->sole()->provider_metadata);
    }
}
