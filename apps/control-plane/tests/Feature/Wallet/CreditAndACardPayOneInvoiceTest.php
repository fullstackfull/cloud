<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;

/**
 * One invoice, two tenders: some credit and the rest on a card.
 *
 * The wallet's own suite proves credit settles an invoice it covers and leaves
 * the remainder payable when it does not. Settlement's own suite proves
 * partial payments accumulate. Neither proves the combination, and the
 * combination is the one a customer actually reaches: a balance from a refund
 * rarely matches the next invoice exactly.
 *
 * What could go wrong is arithmetic that treats the tenders differently — a
 * remainder computed from the invoice total rather than from what is still
 * due, a wallet charge that settlement counts twice because it does not look
 * like a gateway one. Both would be invisible until a customer was charged
 * twice or not at all.
 */
final class CreditAndACardPayOneInvoiceTest extends WalletApiTestCase
{
    #[Test]
    public function credit_pays_what_it_can_and_a_card_pays_exactly_the_rest(): void
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
            ->withHeaders(['Idempotency-Key' => 'mixed-tender-0001'])
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertOk()
            ->assertJsonPath('data.status', InvoiceStatus::Open->value);

        $afterCredit = $invoice->fresh();
        self::assertNotNull($afterCredit);
        $this->assertSame(7_000, (int) $afterCredit->amount_due_minor);

        /*
         * The gateway is asked for what is *due*, not for the total. The quote
         * the customer was shown said 7.000 KWD; a card charged 10.000 here
         * would overpay by exactly the credit they had just spent.
         */
        $settled = app(SettleInvoice::class)->execute(
            $afterCredit,
            $this->cardCapture($afterCredit, Money::ofMinor(7_000, 'KWD')),
        );

        $this->assertSame(InvoiceStatus::Paid, $settled->invoice->status);
        $this->assertTrue($settled->invoice->amountDue()->isZero());
        $this->assertTrue($settled->invoice->amountPaid()->equals(Money::ofMinor(10_000, 'KWD')));

        // Two charges against one invoice, and the wallet is empty rather than
        // refunded: the credit was spent, not merely reserved.
        $this->assertSame(
            ['fake', 'wallet'],
            Transaction::query()
                ->where('invoice_id', $invoice->getKey())
                ->where('kind', TransactionKind::Charge)
                ->orderBy('provider')
                ->pluck('provider')
                ->all(),
        );

        $this->assertSame(0, (int) $this->walletFor($customer)->fresh()?->balance_minor);
    }

    #[Test]
    public function a_card_that_pays_the_whole_total_after_credit_overpays_into_the_wallet(): void
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
            ->withHeaders(['Idempotency-Key' => 'mixed-tender-0002'])
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertOk();

        $afterCredit = $invoice->fresh();
        self::assertNotNull($afterCredit);

        /*
         * A gateway that was told the total before the credit was spent — a
         * stale checkout, a customer with two tabs open. The invoice must not
         * end up paid twice over: the surplus goes back to the balance it came
         * from, which is the same rule any other overpayment follows.
         */
        $settled = app(SettleInvoice::class)->execute(
            $afterCredit,
            $this->cardCapture($afterCredit, Money::ofMinor(10_000, 'KWD')),
        );

        $this->assertSame(InvoiceStatus::Paid, $settled->invoice->status);
        $this->assertTrue($settled->invoice->amountDue()->isZero());
        $this->assertSame(3_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
    }

    private function cardCapture(Invoice $invoice, Money $amount): Transaction
    {
        /** @var Transaction $transaction */
        $transaction = Transaction::query()->create([
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->getKey(),
            'provider' => 'fake',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'provider_reference' => 'pi_'.Str::random(16),
            'processed_at' => now(),
        ]);

        return $transaction;
    }
}
