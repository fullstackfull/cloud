<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lynomia\Modules\Admin\Http\Controllers\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Invoices, payments and refunds across every account.
 */
final class BillingController
{
    use ListsAcrossTenants;

    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->string('customer_id')->value()))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($invoices, static fn (Invoice $invoice): array => [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status->value,
            'customer_id' => $invoice->customer_id,
            'currency' => $invoice->currency,
            'total' => Money::ofMinor($invoice->total_minor, $invoice->currency)->jsonSerialize(),
            'amount_paid' => Money::ofMinor($invoice->amount_paid_minor, $invoice->currency)->jsonSerialize(),
            'amount_due' => Money::ofMinor($invoice->amount_due_minor, $invoice->currency)->jsonSerialize(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_at' => $invoice->due_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $transactions = Transaction::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($request->filled('invoice_id'), fn ($query) => $query->where('invoice_id', $request->string('invoice_id')->value()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($transactions, static fn (Transaction $transaction): array => [
            'id' => $transaction->id,
            'kind' => $transaction->kind->value,
            'status' => $transaction->status->value,
            'provider' => $transaction->provider,
            'amount' => $transaction->amount()->jsonSerialize(),
            'invoice_id' => $transaction->invoice_id,
            'customer_id' => $transaction->customer_id,
            'processed_at' => $transaction->processed_at?->toIso8601String(),
            'created_at' => $transaction->created_at?->toIso8601String(),

            /*
             * `provider_metadata` is not published. It is the provider's raw
             * payload, it has already been shown once to carry an api_key a
             * caller put there, and an operator screen is not worth the risk of
             * being the place that displays it.
             */
        ]);
    }

    /**
     * Refund a capture, in whole or in part.
     *
     * The money is the operator's decision; the arithmetic is not. IssueRefund
     * owns the rule that the total refunded may never exceed what was captured,
     * including under two operators pressing the button at once, and this
     * endpoint's job is to describe the intent and let that action refuse it.
     */
    public function refund(Request $request, string $transaction): JsonResponse
    {
        $found = Transaction::query()->findOrFail($transaction);

        $validated = $request->validate([
            // Minor units, as everywhere. A decimal here would invite a float
            // somewhere between the form and the ledger.
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($found->status !== TransactionStatus::Succeeded) {
            throw ValidationException::withMessages([
                'transaction' => 'Only a succeeded capture can be refunded.',
            ]);
        }

        $user = $request->user();

        $refund = app(IssueRefund::class)->execute(
            transaction: $found,
            amount: Money::ofMinor($validated['amount_minor'], $found->currency),
            reason: $validated['reason'],
            // Recorded so that "who refunded this?" survives the operator
            // leaving the company.
            issuedBy: $user instanceof User ? $user : null,
            invoiceId: $found->invoice_id,
        );

        /*
         * Recorded after the fact and deliberately not inside a transaction
         * with it: the refund has been issued at the payment provider, and
         * rolling the platform's row back would make the trail less true
         * rather than more. A write that fails here is loud — the operator
         * gets a 500 and knows to look — which is the honest trade for an act
         * that cannot be undone by a rollback.
         */
        app(RecordAuditEntry::class)->execute(
            action: AuditAction::PaymentRefunded,
            subject: $refund,
            customerId: $found->customer_id,
            context: [
                'transaction_id' => (string) $found->getKey(),
                'invoice_id' => $found->invoice_id,
                'amount_minor' => $validated['amount_minor'],
                'currency' => $found->currency,
                'reason' => $validated['reason'],
            ],
        );

        return response()->json([
            'data' => [
                'id' => $refund->id,
                'transaction_id' => $found->id,
                'amount' => $refund->amount()->jsonSerialize(),
                'status' => $refund->status->value,
            ],
        ], 201);
    }

    /**
     * Cancel an invoice that should never have been issued.
     *
     * VoidInvoice has existed, correct and tested, with no caller. A duplicate
     * invoice, one raised against the wrong account, or one for an order that
     * was never fulfilled could be created by the platform and then only ever
     * sat in the customer's list, unpayable and undismissable, showing up in
     * their outstanding balance for ever.
     *
     * The action refuses an invoice that has taken money — that is a refund,
     * a different decision with a different permission — so this endpoint does
     * not need to re-check it, and deliberately does not: two guards that must
     * agree eventually disagree.
     */
    public function voidInvoice(Request $request, string $invoice): JsonResponse
    {
        $found = Invoice::query()->findOrFail($invoice);

        $validated = $request->validate([
            // Required, unlike the action's optional argument. An operator
            // voiding a customer's bill from a console can be trusted to know
            // why; a row in the audit trail with no reason is one nobody can
            // review a year later.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        /*
         * Both in one transaction. Voiding is a database write and nothing
         * else — the action refuses any invoice that has taken money — so
         * there is nothing outside Postgres to be inconsistent with, and a
         * cancelled bill with no record of who cancelled it is a hole with
         * nothing to trade against.
         */
        $voided = app(RecordActAtomically::class)->execute(
            act: static fn (): Invoice => app(VoidInvoice::class)->execute($found, $validated['reason']),
            describe: static fn (Invoice $invoice): AuditedAct => new AuditedAct(
                action: AuditAction::InvoiceVoided,
                subject: $invoice,
                customerId: $invoice->customer_id,
                context: [
                    'reason' => $validated['reason'],
                    'number' => $invoice->number,
                    'total_minor' => $invoice->total_minor,
                    'currency' => $invoice->currency,
                ],
            ),
        );

        return response()->json([
            'data' => [
                'id' => $voided->id,
                'number' => $voided->number,
                'status' => $voided->status->value,
            ],
        ]);
    }
}
