<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Spending stored credit.
 *
 * The cases the brief names, and the two that matter most are the ones about
 * repeating: a balance can be spent once, and an invoice can be settled once.
 */
final class PayingAnInvoiceFromCreditTest extends WalletApiTestCase
{
    /**
     * @return array<string, string>
     */
    private function key(string $value = 'wallet-pay-0001'): array
    {
        return ['Idempotency-Key' => $value];
    }

    #[Test]
    public function credit_that_covers_the_whole_invoice_settles_it(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'subtotal_minor' => 9_000,
            'total_minor' => 9_000,
        ]);

        $this->actingAs($owner)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertOk()
            ->assertJsonPath('data.status', InvoiceStatus::Paid->value);

        $this->assertSame(0, (int) $invoice->fresh()?->amount_due_minor);
        $this->assertSame(0, (int) $this->walletFor($customer)->fresh()?->balance_minor);

        // Settlement learns about this the same way it learns about a card
        // payment: a charge row, succeeded, attached to the invoice.
        $charge = Transaction::query()->where('invoice_id', $invoice->getKey())->firstOrFail();
        $this->assertSame('wallet', $charge->provider);
        $this->assertSame(TransactionKind::Charge, $charge->kind);
        $this->assertSame(TransactionStatus::Succeeded, $charge->status);
        $this->assertSame(9_000, (int) $charge->amount_minor);
    }

    #[Test]
    public function spending_credit_is_written_into_the_trail_once_however_often_it_is_asked(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'subtotal_minor' => 9_000,
            'total_minor' => 9_000,
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->actingAs($owner)
                ->withHeaders($this->key('wallet-audited-0001'))
                ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
                ->assertOk();
        }

        /*
         * One row, not three. This is the only payment path with no external
         * processor keeping its own record, so the trail is the whole of what
         * a dispute months later can be settled against — and a trail that
         * counts every retry as another spend would misstate the balance's
         * history rather than merely repeat itself.
         */
        $entries = DB::table('audit_log')
            ->where('action', 'wallet.credit_spent')
            ->where('subject_id', (string) $invoice->getKey())
            ->get();

        $this->assertCount(1, $entries);

        /** @var array<string, mixed> $context */
        $context = json_decode((string) $entries->firstOrFail()->context, true);

        $this->assertSame(9_000, $context['applied_minor']);
        $this->assertSame('KWD', $context['currency']);
    }

    #[Test]
    public function credit_that_covers_part_of_it_leaves_the_rest_payable(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 3_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'subtotal_minor' => 10_000,
            'total_minor' => 10_000,
        ]);

        $this->actingAs($owner)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertOk()
            // Still open: a partial payment is a payment, not a settlement.
            ->assertJsonPath('data.status', InvoiceStatus::Open->value);

        $this->assertSame(3_000, (int) $invoice->fresh()?->amount_paid_minor);
        $this->assertSame(7_000, (int) $invoice->fresh()?->amount_due_minor);
        $this->assertSame(0, (int) $this->walletFor($customer)->fresh()?->balance_minor);
    }

    #[Test]
    public function an_empty_wallet_is_refused_rather_than_settling_nothing(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->walletFor($customer);

        $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        $this->actingAs($owner)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wallet.no_credit_in_currency');

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function a_customer_with_no_wallet_in_that_currency_at_all_gets_the_same_answer(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        $this->actingAs($owner)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wallet.no_credit_in_currency');

        // Asking to pay did not open a wallet. A refusal must not leave a row
        // behind that the customer never asked for.
        $this->assertDatabaseCount('wallets', 0);
    }

    #[Test]
    public function an_invoice_that_is_already_paid_takes_nothing_more(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'total_minor' => 9_000,
            'amount_paid_minor' => 9_000,
            'status' => InvoiceStatus::Paid,
        ]);

        $this->actingAs($owner)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.not_payable');

        $this->assertSame(9_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
    }

    #[Test]
    public function an_open_invoice_with_nothing_left_to_pay_is_refused_by_the_wallet(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        // Collectible, and owing nothing: a zero-total invoice is issued for a
        // free order, and it is the case the wallet's own guard exists for.
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'subtotal_minor' => 0,
            'total_minor' => 0,
        ]);

        $this->actingAs($owner)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wallet.nothing_is_owed');

        $this->assertSame(9_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
    }

    #[Test]
    public function credit_is_never_converted_between_currencies(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer, 'KWD'), 50_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'USD',
            'total_minor' => 1_000,
        ]);

        $this->actingAs($owner)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wallet.no_credit_in_currency');

        // The KWD balance is untouched. A rate the platform would honour does
        // not exist, and inventing one settles an invoice at a number nobody
        // agreed.
        $this->assertSame(50_000, (int) $this->walletFor($customer, 'KWD')->fresh()?->balance_minor);
    }

    #[Test]
    public function repeating_the_request_with_the_same_key_spends_the_balance_once(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'total_minor' => 9_000,
        ]);

        $first = $this->actingAs($owner)->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")->assertOk();

        $second = $this->actingAs($owner)->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")->assertOk();

        $this->assertSame($first->json('data.status'), $second->json('data.status'));
        $this->assertSame(0, (int) $this->walletFor($customer)->fresh()?->balance_minor);

        // One debit, and one charge. A second charge attached to nothing would
        // read to the rest of billing as money that arrived and was never
        // applied, which is what StartInvoicePayment refuses to collect
        // against.
        $this->assertSame(1, WalletTransaction::query()
            ->where('kind', WalletTransactionKind::Payment->value)->count());
        $this->assertSame(1, Transaction::query()->where('provider', 'wallet')->count());
    }

    #[Test]
    public function a_different_key_on_a_settled_invoice_takes_nothing_more(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 20_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'total_minor' => 9_000,
        ]);

        $this->actingAs($owner)->withHeaders($this->key('first-attempt-key'))
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")->assertOk();

        // A genuinely new request, not a replay. The invoice is settled, so
        // there is nothing to apply and the remaining balance stays the
        // customer's.
        $this->actingAs($owner)->withHeaders($this->key('second-attempt-key'))
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invoice.not_payable');

        $this->assertSame(11_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
    }

    #[Test]
    public function the_request_is_refused_without_an_idempotency_key(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        $this->actingAs($owner)
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertStatus(422);

        $this->assertSame(9_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
    }

    #[Test]
    public function the_quote_says_what_would_happen_without_doing_it(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 3_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'total_minor' => 10_000,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertOk()
            ->assertJsonPath('data.available.minor_units', 3_000)
            ->assertJsonPath('data.applicable.minor_units', 3_000)
            ->assertJsonPath('data.remaining.minor_units', 7_000)
            ->assertJsonPath('data.is_payable', true);

        $this->assertSame(3_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
        $this->assertSame(0, (int) $invoice->fresh()?->amount_paid_minor);
    }

    #[Test]
    public function a_member_who_may_not_pay_cannot_spend_the_balance(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $engineer = $this->memberOf($customer, CustomerRole::Technical);
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        $this->actingAs($engineer)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertStatus(403);

        $this->assertSame(9_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
        $this->assertNotSame((string) $engineer->getKey(), (string) $owner->getKey());
    }

    #[Test]
    public function another_customers_invoice_is_not_found_rather_than_forbidden(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $this->credit($this->walletFor($mine), 9_000);
        $theirInvoice = Invoice::factory()->create(['customer_id' => $theirs->getKey(), 'currency' => 'KWD']);

        $this->actingAs($me)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$theirInvoice->getKey()}/wallet-credit")
            ->assertNotFound();

        $this->assertSame(9_000, (int) $this->walletFor($mine)->fresh()?->balance_minor);
        $this->assertSame(0, (int) $theirInvoice->fresh()?->amount_paid_minor);
    }

    #[Test]
    public function a_draft_invoice_cannot_be_paid_from_credit(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->draft()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
        ]);

        // A draft is not on the customer surface at all, so the invoice is not
        // in the scoped result set and the answer is 404 rather than a refusal
        // that would confirm it exists.
        $this->actingAs($owner)
            ->withHeaders($this->key())
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertNotFound();

        $this->assertSame(9_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
    }
}
