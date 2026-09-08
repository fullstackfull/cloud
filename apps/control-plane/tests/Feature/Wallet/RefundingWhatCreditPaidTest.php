<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundExceedsCaptureException;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Money goes back the way it came.
 *
 * The policy the platform commits to, stated as tests rather than as a comment
 * somebody can forget: a refund names one payment, and that payment's channel
 * decides where the money goes.
 */
final class RefundingWhatCreditPaidTest extends WalletApiTestCase
{
    #[Test]
    public function credit_that_paid_an_invoice_is_refunded_to_the_wallet(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'total_minor' => 9_000,
        ]);

        $this->actingAs($owner)
            ->withHeaders(['Idempotency-Key' => 'refund-case-0001'])
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertOk();

        $this->assertSame(0, (int) $this->walletFor($customer)->fresh()?->balance_minor);

        /** @var Transaction $charge */
        $charge = Transaction::query()->where('provider', 'wallet')->firstOrFail();

        $refund = app(IssueRefund::class)->execute(
            $charge,
            Money::ofMinor(4_000, 'KWD'),
            'Cancelled two days into the month',
        );

        $this->assertSame(RefundStatus::Succeeded, $refund->status);

        // Back into the balance it came out of, not out to a card the customer
        // never used for it.
        $this->assertSame(4_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);

        $entry = WalletTransaction::query()
            ->where('kind', WalletTransactionKind::Refund->value)
            ->firstOrFail();

        $this->assertSame(4_000, (int) $entry->amount_minor);
        $this->assertSame((string) $charge->getKey(), (string) $entry->transaction_id);
    }

    #[Test]
    public function a_capture_cannot_be_refunded_beyond_itself_however_many_times_it_is_asked(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 9_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'total_minor' => 9_000,
        ]);

        $this->actingAs($owner)
            ->withHeaders(['Idempotency-Key' => 'refund-case-0002'])
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertOk();

        /** @var Transaction $charge */
        $charge = Transaction::query()->where('provider', 'wallet')->firstOrFail();

        $refund = app(IssueRefund::class)->execute($charge, Money::ofMinor(9_000, 'KWD'), 'Full refund');

        $this->assertSame(9_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
        $this->assertSame((string) $refund->getKey(), (string) $refund->provider_reference);

        /*
         * A second refund of the same capture. Not a retry — a retry reuses
         * the refund row and its idempotency key — but a fresh decision to
         * refund money that has already gone back. The capture's refundable
         * balance is recomputed under a lock and there is none left.
         */
        try {
            app(IssueRefund::class)->execute($charge, Money::ofMinor(9_000, 'KWD'), 'And again');
            $this->fail('A capture was refunded twice over.');
        } catch (RefundExceedsCaptureException) {
            // The refusal is the assertion.
        }

        $this->assertSame(9_000, (int) $this->walletFor($customer)->fresh()?->balance_minor);
        $this->assertSame(1, WalletTransaction::query()
            ->where('kind', WalletTransactionKind::Refund->value)->count());
    }

    #[Test]
    public function a_refund_cannot_exceed_what_the_credit_actually_paid(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $this->credit($this->walletFor($customer), 3_000);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'total_minor' => 10_000,
        ]);

        $this->actingAs($owner)
            ->withHeaders(['Idempotency-Key' => 'refund-case-0003'])
            ->postJson("/api/v1/invoices/{$invoice->getKey()}/wallet-credit")
            ->assertOk();

        /** @var Transaction $charge */
        $charge = Transaction::query()->where('provider', 'wallet')->firstOrFail();

        // Only 3 KWD of credit was applied; the other 7 is still owed and was
        // never paid by anybody.
        $this->expectException(RefundExceedsCaptureException::class);

        app(IssueRefund::class)->execute($charge, Money::ofMinor(10_000, 'KWD'), 'Too much');
    }
}
