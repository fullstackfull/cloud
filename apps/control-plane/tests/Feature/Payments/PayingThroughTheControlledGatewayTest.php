<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;

/**
 * The whole payment journey, from "pay this invoice" to a settled invoice,
 * with nothing skipped and nothing faked in the middle.
 *
 * Every step here is the production step: the intent is created by the
 * provider adapter, the browser is sent to a page it can see, the decision is
 * recorded on the provider's side, the outcome arrives as a genuinely signed
 * webhook, and the signature is verified before the invoice moves. The only
 * thing that is not real is the provider, which takes no money.
 *
 * The property the platform depends on is asserted rather than assumed: at no
 * point does anything a browser says settle an invoice.
 */
final class PayingThroughTheControlledGatewayTest extends PaymentsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The fake records approvals in a file so that a decision taken in one
        // request is visible to a later retrieve, exactly as a real provider's
        // own records would be.
        config(['payments.fake.state_path' => storage_path('framework/testing/fake-payments-'.uniqid().'.json')]);
    }

    protected function tearDown(): void
    {
        $path = config('payments.fake.state_path');

        if (is_string($path) && is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_payment_starts_by_sending_the_browser_to_the_providers_page(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        $started = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.next_action.type', 'redirect')
            ->assertJsonPath('data.next_action.is_awaiting_provider', false);

        $this->assertIsString($started->json('data.next_action.redirect_url'));

        // Starting a payment settles nothing. The invoice is exactly as it was.
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()->status);
        $this->assertSame(
            TransactionStatus::Pending,
            Transaction::query()->where('provider_reference', $started->json('data.reference'))->sole()->status,
        );

        // The page names the amount from the platform's own record.
        $reference = (string) $started->json('data.reference');

        $this->actingAs($user)
            ->getJson("/api/v1/fake-gateway/payments/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.payment.amount.minor_units', 9000)
            ->assertJsonPath('data.payment.status', 'pending');
    }

    #[Test]
    public function authorising_on_the_page_settles_the_invoice_through_a_signed_webhook(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        $reference = (string) $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->json('data.reference');

        $this->actingAs($user)
            ->postJson("/api/v1/fake-gateway/payments/{$reference}/approve")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'succeeded')
            ->assertJsonPath('data.webhook_status', 'processed');

        $settled = $invoice->fresh();

        $this->assertSame(InvoiceStatus::Paid, $settled->status);
        $this->assertSame(9000, $settled->amount_paid_minor);
        $this->assertSame(0, $settled->amount_due_minor);
        $this->assertNotNull($settled->paid_at);

        // The invoice document now shows the payment that settled it.
        $this->actingAs($user)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.status', 'succeeded')
            ->assertJsonPath('data.payments.0.is_settled', true);
    }

    #[Test]
    public function authorising_twice_settles_once(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        $reference = (string) $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->json('data.reference');

        $this->actingAs($user)->postJson("/api/v1/fake-gateway/payments/{$reference}/approve")->assertOk();

        // A customer who reloads the provider's confirmation page, or a
        // provider that retries its callback, must not be charged twice.
        $this->actingAs($user)->postJson("/api/v1/fake-gateway/payments/{$reference}/approve")->assertOk();

        $settled = $invoice->fresh();

        $this->assertSame(9000, $settled->amount_paid_minor);
        $this->assertSame(
            1,
            Transaction::query()->where('invoice_id', $invoice->id)->where('status', TransactionStatus::Succeeded)->count(),
        );
    }

    #[Test]
    public function a_decline_is_visible_and_leaves_the_invoice_unpaid(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        $reference = (string) $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->json('data.reference');

        $this->actingAs($user)
            ->postJson("/api/v1/fake-gateway/payments/{$reference}/decline")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.payment.failure_code', 'card_declined');

        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->amount_paid_minor);

        // And the failure is on the document rather than swallowed: a customer
        // who is not told the card failed learns about it from a suspension.
        $this->actingAs($user)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.status', 'failed')
            ->assertJsonPath('data.payments.0.failure_code', 'card_declined')
            ->assertJsonPath('data.is_payable', true);
    }

    #[Test]
    public function a_provider_that_declines_at_once_is_reported_as_failed_and_not_as_pending(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // The fake declines by amount, so this is a real decline through the
        // real adapter rather than a stubbed exception.
        $declined = FakePaymentProvider::declineAmount(Money::ofMinor(9000, 'KWD'));
        $invoice = $this->openInvoice($customer, $declined);

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.next_action.type', 'failed')
            ->assertJsonPath('data.next_action.redirect_url', null)
            ->assertJsonPath('data.next_action.client_secret', null)
            ->assertJsonPath('data.next_action.is_awaiting_provider', false)
            ->assertJsonPath('data.failure_code', 'card_declined');

        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()->status);
    }

    #[Test]
    public function the_other_confirmation_shape_keeps_the_customer_here_and_needs_the_credential(): void
    {
        config(['payments.fake.next_action' => FakePaymentProvider::NEXT_ACTION_CLIENT_CONFIRMATION]);

        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        $started = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->assertJsonPath('data.next_action.type', 'client_confirmation')
            ->assertJsonPath('data.next_action.redirect_url', null);

        $secret = (string) $started->json('data.next_action.client_secret');
        $reference = (string) $started->json('data.reference');

        $this->assertNotSame('', $secret);

        $this->actingAs($user)
            ->postJson("/api/v1/fake-gateway/payments/{$reference}/confirm", ['client_secret' => $secret])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'succeeded');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
    }

    #[Test]
    public function another_customers_payment_cannot_be_authorised(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        [$otherCustomer, $otherUser] = $this->accountWithOwner();

        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        $reference = (string) $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->json('data.reference');

        // A stranger with the reference — which appears in a redirect URL, and
        // therefore in a browser's history and any shared screenshot.
        $this->actingAs($otherUser)
            ->postJson("/api/v1/fake-gateway/payments/{$reference}/approve")
            ->assertNotFound();

        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()->status);
    }

    #[Test]
    public function a_server_side_retrieve_agrees_with_the_decision_taken_in_the_browser(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        $reference = (string) $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated()
            ->json('data.reference');

        /** @var FakePaymentProvider $provider */
        $provider = app(PaymentProviderRegistry::class)->get('fake');

        // Before anyone authorises it, the provider says it is waiting.
        $this->assertSame('requires_action', $provider->retrievePayment($reference)->status->value);

        $this->actingAs($user)->postJson("/api/v1/fake-gateway/payments/{$reference}/approve")->assertOk();

        /*
         * And afterwards it says succeeded — which is what makes the
         * reconciliation run able to finish a payment whose webhook was lost.
         * Without the recorded decision the reference alone would always
         * answer "requires action" and a lost webhook would never be
         * recovered.
         */
        $this->assertSame('succeeded', $provider->retrievePayment($reference)->status->value);
    }

    #[Test]
    public function credit_and_a_card_settle_one_invoice_and_the_document_shows_both(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        /** @var WalletLedger $ledger */
        $ledger = app(WalletLedger::class);
        $wallet = $ledger->walletFor($customer, 'KWD');
        $ledger->credit(
            wallet: $wallet,
            amount: Money::ofMinor(4000, 'KWD'),
            kind: WalletTransactionKind::Topup,
            description: 'Top-up',
        );

        // Credit first: the server decides how much of it applies, which is
        // the smaller of the balance and what is owed.
        $paid = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'mixed-payment-credit')
            ->postJson("/api/v1/invoices/{$invoice->id}/wallet-credit", [])
            ->assertOk();

        $this->assertSame(4000, $paid->json('data.amount_paid.minor_units'));
        $this->assertSame(5000, $paid->json('data.amount_due.minor_units'));
        $this->assertTrue($paid->json('data.is_payable'));

        // And the card pays exactly the rest, never the original total.
        $started = $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertCreated();

        $this->assertSame(5000, $started->json('data.payment.amount.minor_units'));

        $reference = (string) $started
            ->json('data.reference');

        $this->actingAs($user)
            ->postJson("/api/v1/fake-gateway/payments/{$reference}/approve")
            ->assertOk();

        $document = $this->actingAs($user)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk();

        $this->assertSame('paid', $document->json('data.status'));
        $this->assertSame(9000, $document->json('data.amount_paid.minor_units'));
        $this->assertSame(0, $document->json('data.amount_due.minor_units'));

        /*
         * Two payment rows, because two different things happened: the wallet
         * is a payment method of its own — recorded against the provider
         * `wallet`, which is the platform itself — and the card is the other.
         * An invoice that showed one line reading "paid" would leave the
         * customer unable to see where nine dinars went.
         */
        $payments = collect((array) $document->json('data.payments'))
            ->keyBy('provider')
            ->map(fn (array $row): int => (int) $row['amount']['minor_units'])
            ->all();

        $this->assertSame(['wallet' => 4000, 'fake' => 5000], $payments);

        // And the ledger says the same thing from the wallet's side.
        $this->assertSame(-4000, $document->json('data.wallet_credits.0.amount.minor_units'));

        // The wallet is empty, and its ledger says where the money went.
        $this->assertSame(0, $wallet->refresh()->balance_minor);
    }

    #[Test]
    public function a_wallet_in_another_currency_pays_nothing_towards_this_invoice(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $invoice = $this->openInvoice($customer, Money::ofMinor(9000, 'KWD'));

        /** @var WalletLedger $ledger */
        $ledger = app(WalletLedger::class);

        // Plenty of money, in the wrong currency. Converting it would invent
        // an exchange rate nobody agreed to.
        $ledger->credit(
            wallet: $ledger->walletFor($customer, 'USD'),
            amount: Money::ofMinor(100000, 'USD'),
            kind: WalletTransactionKind::Topup,
            description: 'Top-up',
        );

        $this->actingAs($user)
            ->getJson("/api/v1/invoices/{$invoice->id}/wallet-credit")
            ->assertOk()
            ->assertJsonPath('data.available.minor_units', 0)
            ->assertJsonPath('data.applicable.minor_units', 0)
            ->assertJsonPath('data.available.currency', 'KWD');

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'cross-currency-credit')
            ->postJson("/api/v1/invoices/{$invoice->id}/wallet-credit", [])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wallet.no_credit_in_currency');

        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->amount_paid_minor);
    }
}
