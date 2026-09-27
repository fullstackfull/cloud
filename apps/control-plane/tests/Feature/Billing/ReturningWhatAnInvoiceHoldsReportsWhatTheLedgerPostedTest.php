<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ReturnWhatAnInvoiceStillHolds::toTheWallet() answers with what the ledger
 * actually posted for this call.
 *
 * Round four's re-audit: it answered with the figure it computed even when
 * the ledger replayed an entry already posted under the same key and posted
 * nothing - so a caller told "credited 1.000" reported money that this call
 * never moved. The replay is only reachable when an entry under the key is
 * not counted against the invoice (the lock and the count are what normally
 * make a second run credit nothing); this stages exactly that.
 */
final class ReturningWhatAnInvoiceHoldsReportsWhatTheLedgerPostedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_first_return_reports_what_it_posted(): void
    {
        [$customer, $invoice] = $this->partPaidInvoice(1_000);

        $this->assertSame(1_000, app(ReturnWhatAnInvoiceStillHolds::class)->toTheWallet($invoice, 'probe', 'returned'));
        $this->assertSame(1_000, $this->balanceOf($customer));
        $this->assertSame(0, app(ReturnWhatAnInvoiceStillHolds::class)->toTheWallet($invoice, 'probe', 'returned'));
    }

    #[Test]
    public function a_return_the_ledger_answers_with_a_replay_reports_nothing_posted(): void
    {
        [$customer, $invoice] = $this->partPaidInvoice(1_000);

        // An entry already under the very key, not recorded against the
        // invoice - so the invoice still holds the 1.000 and the ledger
        // replays rather than posts.
        $ledger = app(WalletLedger::class);
        $ledger->credit(
            wallet: $ledger->walletFor($customer, 'KWD'),
            amount: Money::ofMinor(1_000, 'KWD'),
            kind: WalletTransactionKind::Topup,
            description: 'posted earlier under the same key',
            idempotencyKey: sprintf('invoice:%s:probe:%d', $invoice->getKey(), 1_000),
        );

        $credited = app(ReturnWhatAnInvoiceStillHolds::class)->toTheWallet($invoice, 'probe', 'returned');

        $this->assertSame(1_000, $this->balanceOf($customer), 'Precondition: the ledger posted nothing for this call.');
        $this->assertSame(0, $credited, 'It reported money this call never moved.');
    }

    /**
     * @return array{Customer, Invoice}
     */
    private function partPaidInvoice(int $paidMinor): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 9_000,
            'total_minor' => 9_000,
            'amount_paid_minor' => 0,
            'amount_refunded_minor' => 0,
        ]);

        app(SettleInvoice::class)->execute(
            $invoice,
            Transaction::factory()->amount(Money::ofMinor($paidMinor, 'KWD'))->create(['customer_id' => $customer->getKey()]),
        );

        return [$customer, $invoice->refresh()];
    }

    private function balanceOf(Customer $customer): int
    {
        $ledger = app(WalletLedger::class);

        return $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();
    }
}
