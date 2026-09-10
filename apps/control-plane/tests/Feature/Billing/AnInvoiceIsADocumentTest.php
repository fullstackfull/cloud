<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;

/**
 * An issued invoice is a document about a moment, and it does not change when
 * the world does.
 *
 * The customer surface used to publish totals and nothing else: no address, no
 * tax id, no lines that could be printed, and no record of the card that was
 * declined last Tuesday. That is not an invoice, it is a balance — and a
 * customer who needs to give their accountant a document had nothing to give
 * them.
 */
final class AnInvoiceIsADocumentTest extends BillingApiTestCase
{
    #[Test]
    public function the_document_carries_the_billing_details_as_they_stood_when_it_was_issued(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $invoice = $this->invoiceFor($customer, [
            'status' => InvoiceStatus::Open,
            'issued_at' => now()->subMonth(),
            'billing_snapshot' => [
                'customer_id' => (string) $customer->id,
                'display_name' => 'Premier Care',
                'tax_id' => 'KW-TAX-4471',
                'address' => ['line1' => 'Block 4, Salmiya', 'country' => 'KW'],
                'captured_at' => now()->subMonth()->toIso8601String(),
            ],
        ]);

        InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        // The customer has since moved and renamed the account.
        $customer->forceFill([
            'display_name' => 'Premier Care Holding',
            'address_line1' => 'Block 9, Sharq',
            'tax_id' => 'KW-TAX-9999',
        ])->save();

        $document = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $this->assertSame('Premier Care', $document->json('data.billing_snapshot.display_name'));
        $this->assertSame('KW-TAX-4471', $document->json('data.billing_snapshot.tax_id'));
        $this->assertSame('Block 4, Salmiya', $document->json('data.billing_snapshot.address.line1'));

        // Nothing about today's profile appears on last month's document.
        $body = (string) $document->getContent();
        $this->assertStringNotContainsString('Premier Care Holding', $body);
        $this->assertStringNotContainsString('KW-TAX-9999', $body);
        $this->assertStringNotContainsString((string) $customer->id, $body);
    }

    #[Test]
    public function the_document_shows_every_payment_including_the_ones_that_failed(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $invoice = $this->invoiceFor($customer, [
            'status' => InvoiceStatus::Open,
            'issued_at' => now(),
        ]);

        Transaction::factory()->forCustomer($customer)->create([
            'invoice_id' => $invoice->id,
            'status' => 'failed',
            'failure_code' => 'card_declined',
            'failure_message' => 'The card was declined.',
            'amount_minor' => 9000,
            'currency' => 'KWD',
        ]);

        Transaction::factory()->forCustomer($customer)->create([
            'invoice_id' => $invoice->id,
            'status' => 'pending',
            'amount_minor' => 9000,
            'currency' => 'KWD',
        ]);

        $document = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $payments = (array) $document->json('data.payments');

        $this->assertCount(2, $payments);
        $this->assertSame(
            ['failed', 'pending'],
            collect($payments)->pluck('status')->sort()->values()->all(),
        );
        $this->assertSame('card_declined', collect($payments)->firstWhere('status', 'failed')['failure_code']);

        // A list of invoices does not carry any of this.
        $list = $this->actingAs($user)->getJson('/api/v1/invoices')->assertOk();
        $this->assertArrayNotHasKey('payments', (array) $list->json('data.0'));
    }

    #[Test]
    public function wallet_credit_and_a_card_appear_as_two_different_rows(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $invoice = $this->invoiceFor($customer, [
            'status' => InvoiceStatus::Open,
            'issued_at' => now(),
        ]);

        $ledger = app(WalletLedger::class);
        $wallet = $ledger->walletFor($customer, 'KWD');

        $ledger->credit(
            wallet: $wallet,
            amount: Money::ofMinor(4000, 'KWD'),
            kind: WalletTransactionKind::Topup,
            description: 'Top-up by card ending 4242',
        );

        // Four dinars of credit spent on this invoice, recorded in the ledger
        // that is the source of truth for a balance.
        $ledger->debit(
            wallet: $wallet->refresh(),
            amount: Money::ofMinor(4000, 'KWD'),
            kind: WalletTransactionKind::Payment,
            description: 'Applied to invoice',
            invoiceId: (string) $invoice->id,
        );

        Transaction::factory()->forCustomer($customer)->create([
            'invoice_id' => $invoice->id,
            'amount_minor' => 5000,
            'currency' => 'KWD',
        ]);

        $document = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $this->assertCount(1, (array) $document->json('data.payments'));
        $this->assertCount(1, (array) $document->json('data.wallet_credits'));

        // Signed, so a ledger row cannot be read the wrong way round: a debit
        // is negative and says `debit` as well.
        $this->assertSame('debit', $document->json('data.wallet_credits.0.direction'));
        $this->assertSame(-4000, $document->json('data.wallet_credits.0.amount.minor_units'));
        $this->assertSame(5000, $document->json('data.payments.0.amount.minor_units'));
    }

    #[Test]
    public function another_accounts_invoice_is_a_404(): void
    {
        [$acting, $other, $user] = $this->twoAccountsOneLogin();

        $theirs = $this->invoiceFor($other, ['status' => InvoiceStatus::Open, 'issued_at' => now()]);

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', (string) $acting->id)
            ->getJson("/api/v1/invoices/{$theirs->id}")
            ->assertNotFound();
    }

    #[Test]
    public function every_money_field_is_published_as_minor_units_and_a_currency(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $invoice = $this->invoiceFor($customer, [
            'status' => InvoiceStatus::Open,
            'issued_at' => now(),
        ]);

        InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        $document = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        foreach (['subtotal', 'discount', 'tax', 'total', 'amount_paid', 'amount_refunded', 'amount_due'] as $field) {
            $this->assertIsInt($document->json("data.{$field}.minor_units"), $field);
            $this->assertSame('KWD', $document->json("data.{$field}.currency"), $field);
        }

        foreach (['unit_amount', 'discount', 'tax', 'total'] as $field) {
            $this->assertIsInt($document->json("data.items.0.{$field}.minor_units"), $field);
        }

        // Three minor digits for the dinar, formatted server side.
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{3}$/',
            (string) $document->json('data.total.amount'),
        );
    }

    #[Test]
    public function a_zero_total_invoice_says_nothing_is_owed_rather_than_offering_a_payment(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $invoice = $this->invoiceFor($customer, [
            'status' => InvoiceStatus::Paid,
            'issued_at' => now(),
            'paid_at' => now(),
        ]);

        $document = $this->actingAs($user)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk();

        $this->assertFalse($document->json('data.is_payable'));
        $this->assertTrue($document->json('data.is_settled'));

        $this->actingAs($user)
            ->postJson("/api/v1/invoices/{$invoice->id}/payments")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.not_payable');
    }
}
