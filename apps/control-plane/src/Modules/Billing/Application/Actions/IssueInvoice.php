<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricedOrder;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Services\InvoiceNumberAllocator;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Throwable;

/**
 * Turns priced lines into an issued invoice.
 *
 * Issuing is the moment a set of figures becomes a document, and three things
 * have to be true of it afterwards:
 *
 *  1. **It has exactly one number.** The number comes from the sequence inside
 *     the same transaction that writes the row, so a rolled-back issue burns a
 *     number rather than leaving one attached to nothing — and issuing twice
 *     for the same order returns the first invoice without consuming a second.
 *
 *  2. **It stops depending on anything that can change.** The customer's
 *     billing details are copied into billing_snapshot and the per-line tax
 *     rate and name are copied onto the items. A customer who moves next month,
 *     or a tax rule that is superseded, must not rewrite a document that has
 *     already been sent and possibly filed.
 *
 *  3. **Its totals are the sum of its lines.** They are added up from the
 *     drafts here rather than taken from a separately rounded figure, so the
 *     invoice cannot show lines that do not add up to what is charged.
 *
 * And the account is told it exists, once, after it has committed — see
 * announceOnceCommitted().
 */
final readonly class IssueInvoice
{
    public function __construct(
        private InvoiceNumberAllocator $numbers,
        private TransitionInvoice $transitionInvoice,
        private NotifyCustomer $notify,
    ) {}

    /**
     * @param  list<InvoiceLineDraft>  $lines
     *
     * @throws CurrencyMismatchException
     */
    public function execute(
        Customer $customer,
        array $lines,
        ?Order $order = null,
        ?string $subscriptionId = null,
        ?DateTimeImmutable $issuedAt = null,
        ?string $notes = null,
    ): Invoice {
        if ($lines === []) {
            throw new InvalidArgumentException('Cannot issue an invoice with no lines.');
        }

        $currency = $lines[0]->currency();
        $this->assertOneCurrency($lines, $currency, $order);

        if ($order !== null && $order->customer_id !== $customer->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Order %s does not belong to customer %s.',
                (string) $order->getKey(),
                (string) $customer->getKey(),
            ));
        }

        $issuedAt = $issuedAt !== null
            ? CarbonImmutable::instance($issuedAt)
            : CarbonImmutable::now();

        return DB::transaction(function () use ($customer, $lines, $order, $subscriptionId, $issuedAt, $notes, $currency): Invoice {
            if ($order !== null) {
                /*
                 * The order row is the mutex for issuing. Two renewal or
                 * checkout workers can reach the existence check at the same
                 * instant, and there is no unique index on invoices.order_id
                 * to catch the loser — so the contended row is locked and the
                 * check is made behind the lock.
                 */
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());

                /** @var Invoice|null $existing */
                $existing = Invoice::query()->where('order_id', $lockedOrder->getKey())->first();

                if ($existing !== null) {
                    // Deliberately before the sequence is touched: a repeat
                    // issue must not burn an invoice number.
                    return $existing;
                }
            }

            $totals = $this->totalsOf($lines, $currency);

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->create([
                'customer_id' => $customer->getKey(),
                'order_id' => $order?->getKey(),
                'subscription_id' => $subscriptionId,
                'number' => $this->numbers->next(),
                'status' => InvoiceStatus::Draft,
                'currency' => $currency,
                'subtotal_minor' => $totals['subtotal']->minorUnits(),
                'discount_minor' => $totals['discount']->minorUnits(),
                'tax_minor' => $totals['tax']->minorUnits(),
                'total_minor' => $totals['total']->minorUnits(),
                'amount_paid_minor' => 0,
                'amount_refunded_minor' => 0,
                'billing_snapshot' => $this->snapshotOf($customer, $issuedAt),
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->addDays($this->paymentTermsDays()),
                'notes' => $notes,
            ]);

            foreach ($lines as $line) {
                $invoice->items()->create([
                    'kind' => $line->kind,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_amount_minor' => $line->unitAmount->minorUnits(),
                    'discount_minor' => $line->totals->discount->minorUnits(),
                    'tax_minor' => $line->totals->tax->minorUnits(),
                    'total_minor' => $line->totals->total->minorUnits(),
                    // Copied, not referenced: the rate charged is part of the
                    // document, and the rule behind it may be superseded.
                    'tax_rate' => $line->totals->taxRate,
                    'tax_name' => $line->totals->taxName,
                    'period_start' => $line->periodStart,
                    'period_end' => $line->periodEnd,
                    'subscription_id' => $line->subscriptionId ?? $subscriptionId,
                ]);
            }

            // The draft exists only inside this transaction; nothing outside it
            // ever sees an invoice that has a number but has not been issued.
            $issued = $this->transitionInvoice->execute($invoice, InvoiceStatus::Open);

            $this->announceOnceCommitted($issued);

            // amount_due_minor is computed by PostgreSQL on write, so the
            // in-memory row does not know it yet.
            return $issued->refresh();
        });
    }

    /**
     * Tell the account the invoice exists, once the transaction holding it
     * has committed (F-46).
     *
     * `InvoiceIssued` was declared, translated and emailed by default, and
     * nothing raised it but the E2E seeder. The platform holds no card on
     * file, so an invoice is money somebody has to come and pay — and the
     * renewal sweep issues one at the end of every period with nobody
     * watching. This is the one place every invoice is issued, so it is the
     * one place that says so: checkout, renewal, plan change and domain alike.
     * An order that owes nothing is issued no invoice and is told nothing
     * here, which is right: there is nothing to pay.
     *
     * After commit, not inside the transaction. An issue the caller rolls
     * back — a renewal whose period advance failed, a checkout that threw —
     * leaves no invoice, and a customer told about a document that does not
     * exist goes looking for a number the sequence has already burned. A
     * repeat issue for the same order returns before reaching here, and the
     * idempotency key is the invoice itself, so a document is announced once.
     *
     * To the account's billing address, not to a person: nobody's user id is
     * named. And never at the expense of the invoice: it has committed by the
     * time this runs, and a notification that could not be raised is reported
     * rather than thrown into the checkout or the sweep that issued it.
     */
    private function announceOnceCommitted(Invoice $invoice): void
    {
        $customerId = (string) $invoice->customer_id;
        $data = [
            'number' => (string) $invoice->number,
            'amount' => Money::ofMinor((int) $invoice->total_minor, (string) $invoice->currency)->format(),
            'due_date' => $invoice->due_at?->toDateString() ?? '',
        ];

        DB::afterCommit(function () use ($invoice, $customerId, $data): void {
            try {
                $this->notify->execute(
                    customerId: $customerId,
                    type: NotificationType::InvoiceIssued,
                    idempotencyKey: 'invoice-issued:'.$invoice->getKey(),
                    subject: $invoice,
                    data: $data,
                    link: '/invoices',
                );
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Issues the invoice for an order that has just been priced.
     *
     * @param  list<PricingLine>  $pricingLines
     */
    public function fromPricedOrder(
        Customer $customer,
        PricedOrder $priced,
        array $pricingLines,
        ?Order $order = null,
        InvoiceItemKind $kind = InvoiceItemKind::Plan,
        ?string $subscriptionId = null,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
    ): Invoice {
        return $this->execute(
            customer: $customer,
            lines: InvoiceLineDraft::zip($priced, $pricingLines, $kind, $periodStart, $periodEnd, $subscriptionId),
            order: $order,
            subscriptionId: $subscriptionId,
        );
    }

    /**
     * @param  list<InvoiceLineDraft>  $lines
     * @return array{subtotal: Money, discount: Money, tax: Money, total: Money}
     */
    private function totalsOf(array $lines, string $currency): array
    {
        $subtotal = Money::zero($currency);
        $discount = Money::zero($currency);
        $tax = Money::zero($currency);
        $total = Money::zero($currency);

        foreach ($lines as $line) {
            $subtotal = $subtotal->plus($line->totals->net);
            $discount = $discount->plus($line->totals->discount);
            $tax = $tax->plus($line->totals->tax);
            $total = $total->plus($line->totals->total);
        }

        return ['subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total];
    }

    /**
     * The billing details as they stand right now, frozen onto the document.
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(Customer $customer, CarbonImmutable $issuedAt): array
    {
        return [
            'customer_id' => (string) $customer->getKey(),
            'type' => $customer->type->value,
            'display_name' => $customer->display_name,
            'legal_name' => $customer->legal_name,
            'registration_number' => $customer->registration_number,
            'tax_id' => $customer->tax_id,
            'tax_exempt' => (bool) $customer->tax_exempt,
            'billing_email' => $customer->billing_email,
            'billing_phone' => $customer->billing_phone,
            'address' => [
                'line1' => $customer->address_line1,
                'line2' => $customer->address_line2,
                'city' => $customer->city,
                'state' => $customer->state,
                'postal_code' => $customer->postal_code,
                'country' => $customer->country,
            ],
            'captured_at' => $issuedAt->toIso8601String(),
        ];
    }

    private function paymentTermsDays(): int
    {
        return (int) config('billing.payment_terms_days', 7);
    }

    /**
     * @param  list<InvoiceLineDraft>  $lines
     */
    private function assertOneCurrency(array $lines, string $currency, ?Order $order): void
    {
        foreach ($lines as $line) {
            if ($line->currency() !== $currency || $line->unitAmount->currency() !== $currency) {
                throw CurrencyMismatchException::between($currency, $line->currency());
            }
        }

        // An invoice is denominated in one currency and the platform never
        // converts, so an order priced in another one cannot be billed here.
        if ($order !== null && $order->currency !== $currency) {
            throw CurrencyMismatchException::between($currency, $order->currency);
        }
    }
}
