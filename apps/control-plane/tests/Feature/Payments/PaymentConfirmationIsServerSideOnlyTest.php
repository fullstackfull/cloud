<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rule the rest of the platform is built on: a service is provisioned only
 * after server-side confirmation.
 *
 * A browser returning from a provider controls its own URL and its own request
 * body. If any endpoint accepted "the payment succeeded" from a client, the
 * platform could be paid with a bookmark — and every one of these tests is
 * about that single sentence rather than about any particular response shape.
 */
final class PaymentConfirmationIsServerSideOnlyTest extends PaymentsApiTestCase
{
    #[Test]
    public function no_route_lets_a_client_say_that_a_payment_succeeded(): void
    {
        $suspicious = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            // Anything that looks like a customer-facing confirmation of a
            // payment. The webhook, which is the only thing allowed to settle
            // one, lives outside api/ under webhooks/.
            if (preg_match('#payment.*(confirm|capture|settle|succe|complete)#i', $uri) === 1) {
                $suspicious[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        /*
         * The controlled gateway is the provider's side of the boundary, not
         * the client's, and it is not exempted here — it is held to a stricter
         * rule instead, in the two tests below: it must be refused whenever a
         * real payment provider is configured, and refused in production
         * whatever is configured. What it does when it is available is emit a
         * genuinely signed webhook, which settles the invoice through the same
         * verification a real provider's callback goes through; it cannot mark
         * anything paid itself.
         *
         * Anything else matching the pattern is the defect this test exists to
         * catch: an endpoint through which a browser's claim becomes money.
         */
        $controlled = 'api/v1/fake-gateway/payments/{reference}/confirm';
        $unexpected = array_values(array_filter(
            $suspicious,
            static fn (string $route): bool => ! str_ends_with($route, $controlled),
        ));

        $this->assertSame([], $unexpected, "A client-callable payment confirmation exists:\n  ".implode("\n  ", $unexpected));

        // And the three that should exist, do.
        $this->assertTrue(Route::has('api.v1.invoices.payments.store'));
        $this->assertTrue(Route::has('api.v1.payments.index'));
        $this->assertTrue(Route::has('api.v1.payments.show'));
    }

    #[Test]
    public function the_controlled_gateway_is_refused_when_a_real_provider_is_configured(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $started = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        $reference = (string) $started->json('data.reference');

        // The environment now takes real payments. Nothing in the platform may
        // approve one of them on a customer's word.
        config(['billing.providers.payment' => 'stripe']);

        foreach (['approve', 'decline'] as $decision) {
            $this->actingAs($user)
                ->postJson("/api/v1/fake-gateway/payments/{$reference}/{$decision}")
                ->assertNotFound()
                ->assertJsonPath('error.code', 'payment.controlled_gateway_unavailable');
        }

        $this->actingAs($user)
            ->postJson("/api/v1/fake-gateway/payments/{$reference}/confirm", [
                'client_secret' => str_repeat('a', 32),
            ])
            ->assertNotFound();

        $this->assertSame(
            InvoiceStatus::Open,
            $invoice->fresh()->status,
            'A refused controlled-gateway call must leave the invoice exactly as it was.',
        );
    }

    #[Test]
    public function a_client_credential_that_does_not_belong_to_the_payment_confirms_nothing(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $started = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        $reference = (string) $started->json('data.reference');

        $this->actingAs($user)
            ->postJson("/api/v1/fake-gateway/payments/{$reference}/confirm", [
                'client_secret' => $reference.'_secret_deadbeefdeadbeef',
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'payment.controlled_gateway_unavailable');

        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()->status);
        $this->assertSame(
            TransactionStatus::Pending,
            Transaction::query()->where('provider_reference', $reference)->sole()->status,
        );
    }

    #[Test]
    public function the_obvious_confirmation_urls_do_not_exist(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);
        $payment = Transaction::factory()->forCustomer($customer)->pending()->create();

        $attempts = [
            ['post', "/api/v1/payments/{$payment->id}/confirm"],
            ['post', "/api/v1/payments/{$payment->id}/capture"],
            ['post', "/api/v1/invoices/{$invoice->id}/payments/confirm"],
            ['post', "/api/v1/invoices/{$invoice->id}/pay"],
            ['patch', "/api/v1/payments/{$payment->id}"],
            ['put', "/api/v1/payments/{$payment->id}"],
        ];

        foreach ($attempts as [$method, $url]) {
            $response = $this->actingAs($user)->json($method, $url, ['status' => 'succeeded']);

            $this->assertContains(
                $response->status(),
                [404, 405],
                sprintf('%s %s answered %d; nothing may accept a client-side confirmation.', $method, $url, $response->status()),
            );
        }

        $this->assertSame(TransactionStatus::Pending, $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
    }

    #[Test]
    public function a_body_that_claims_success_changes_nothing(): void
    {
        Event::fake([PaymentCaptured::class]);

        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer);

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments", [
                'status' => 'succeeded',
                'paid' => true,
                'captured' => true,
                'provider_reference' => 'fake_pi_succeeded_KWD_9000_deadbeef',
            ])
            ->assertCreated()
            // The provider has been asked to collect; nobody has said it did.
            ->assertJsonPath('data.payment.status', TransactionStatus::Pending->value);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(0, $invoice->amount_paid_minor);
        $this->assertNull($invoice->paid_at);

        $transaction = Transaction::query()->sole();
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        // The reference is the provider's, derived from the intent it created,
        // not the one the client posted.
        $this->assertNotSame('fake_pi_succeeded_KWD_9000_deadbeef', $transaction->provider_reference);

        // Nothing downstream was told money arrived, so nothing provisions.
        Event::assertNotDispatched(PaymentCaptured::class);
    }
}
