<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceNotPayableException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Application\DTOs\StartedPayment;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentResult;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Domain\Exceptions\InvoicePaymentRefusedException;
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderManager;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Opens a payment for an invoice and hands back what the browser must do next.
 *
 * The one rule the rest of the platform is built on: **this action never
 * settles anything.** It writes an attempt and, at most, a *pending* ledger
 * row; it does not touch the invoice, does not emit PaymentCaptured, and does
 * not provision. A payment becomes real when the provider says so through a
 * verified webhook (IngestWebhookEvent) or when the platform asks the provider
 * directly (ConfirmPaymentFromReturn) — both of which converge on
 * RecordPaymentCapture. Nothing a browser sends is evidence of payment, which
 * is why there is no method here, and no endpoint anywhere, that a client can
 * call to report success.
 *
 * **The amount is the invoice's.** It is read from the row inside the same
 * locked transaction that checks the invoice is collectible, so nothing the
 * caller sends can influence what is collected. A request body carrying an
 * `amount` is not validated and rejected — it is simply never read.
 *
 * **Repeating the request is safe, and the ordering is what makes it safe.**
 * The attempt row is written and committed *before* the provider is called,
 * and its id is the idempotency key sent to the provider. If the provider call
 * times out, the platform has stopped waiting but the provider has not stopped
 * working: the attempt survives, a retry reuses the same key, and the provider
 * replays the intent it already created instead of creating a second one. That
 * is also why a failed provider call does not delete the attempt — throwing
 * away the key is throwing away the only thing that makes the retry safe.
 *
 * There is deliberately no client-supplied idempotency key. There is no column
 * to persist one against on payment_attempts, and inventing a second mechanism
 * beside the one the provider already honours would give two answers to "is
 * this the same payment?" that can disagree.
 */
final readonly class StartInvoicePayment
{
    public function __construct(
        private PaymentProviderManager $providers,
    ) {}

    /**
     * @param  Invoice  $invoice  Already scoped to the acting customer by the caller. The payer recorded
     *                            against the payment is this invoice's own customer, never anything from
     *                            the request.
     * @param  string|null  $returnUrl  Where the provider should send the browser back to. It is a hint to the
     *                                  provider, not a source of truth: whatever the customer arrives back
     *                                  with is checked against the provider before it means anything.
     *
     * @throws InvoiceNotPayableException
     * @throws InvoicePaymentRefusedException
     * @throws PaymentProviderException
     */
    public function execute(Invoice $invoice, ?string $returnUrl = null): StartedPayment
    {
        $provider = $this->providers->default();

        [$attempt, $amount] = $this->reserveAttempt($invoice);

        /*
         * Outside every transaction on purpose. A network call inside one
         * holds a row lock open for the duration of somebody else's outage,
         * and a provider that answers after we have given up must still find
         * the attempt row committed.
         */
        $result = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: $amount,
            idempotencyKey: (string) $attempt->getKey(),
            description: sprintf('Invoice %s', $invoice->number),
            // The attribution the provider echoes back on every webhook about
            // this payment. It is what lets a capture be credited to an
            // account without trusting whoever delivered the news.
            metadata: [
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->getKey(),
            ],
            returnUrl: $returnUrl,
            // Never confirmed here. Confirming would take the money inside an
            // HTTP request and leave the capture known only to a response the
            // client may never receive.
            confirm: false,
        ));

        $transaction = $this->recordPendingTransaction($invoice, $provider->name(), $result, $amount, $attempt);

        return new StartedPayment(
            provider: $provider->name(),
            transaction: $transaction,
            attempt: $attempt->refresh(),
            intent: $result,
        );
    }

    /**
     * The attempt this payment will be made under, and the amount to collect.
     *
     * Re-reads the invoice under a row lock rather than trusting the instance
     * passed in: the status and the amount that decide whether money may be
     * collected must be the committed ones, not a copy that was loaded before
     * a concurrent webhook settled the document.
     *
     * @return array{0: PaymentAttempt, 1: Money}
     */
    private function reserveAttempt(Invoice $invoice): array
    {
        return DB::transaction(function () use ($invoice): array {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isCollectible()) {
                throw InvoiceNotPayableException::forStatus((string) $locked->getKey(), $locked->status);
            }

            $amount = $locked->amountDue();

            if (! $amount->isPositive()) {
                throw InvoicePaymentRefusedException::nothingIsOwed((string) $locked->getKey());
            }

            $this->assertNoCaptureIsWaitingToBeApplied($locked);

            $open = $this->openAttemptFor($locked, $amount);

            if ($open !== null) {
                return [$open, $amount];
            }

            /** @var PaymentAttempt $attempt */
            $attempt = PaymentAttempt::query()->create([
                'invoice_id' => $locked->getKey(),
                'attempt_number' => 1 + (int) PaymentAttempt::query()
                    ->where('invoice_id', $locked->getKey())
                    ->max('attempt_number'),
                'status' => PaymentAttemptStatus::Pending,
            ]);

            return [$attempt, $amount];
        });
    }

    /**
     * Refuses while money is in and settlement has not caught up.
     *
     * The per-attempt check below inspects only the newest pending attempt,
     * which is the right attempt to reuse but not a complete answer to "has
     * this invoice already been paid?". An attempt that was abandoned - because
     * the amount moved while it was open - is not pending any more, and its
     * capture can still arrive afterwards. The invoice is then open, its
     * amount_due still positive because settlement has not run, and nothing in
     * the newest attempt says otherwise. The customer is sent to pay a second
     * time.
     *
     * The test is deliberately not arithmetic. Comparing the sum of succeeded
     * charges against amount_paid_minor looks equivalent and is not: settlement
     * subtracts anything an earlier overpayment diverted to the wallet, so the
     * two are permanently unequal for any invoice that has ever been overpaid,
     * and a guard written that way would make those invoices unpayable forever.
     *
     * `transactions.invoice_id` is settlement's own marker - the code that sets
     * it says so: attaching is "the closest thing settlement has to an applied
     * marker". A succeeded capture on this invoice's attempts with a null
     * invoice_id is exactly, and only, money that has arrived and has not been
     * applied. No overpayment arithmetic enters into it.
     */
    private function assertNoCaptureIsWaitingToBeApplied(Invoice $invoice): void
    {
        /** @var Transaction|null $unapplied */
        $unapplied = Transaction::query()
            ->whereNull('invoice_id')
            ->where('status', TransactionStatus::Succeeded)
            ->whereHas('attempts', static fn ($query) => $query->where('invoice_id', $invoice->getKey()))
            ->first();

        if ($unapplied !== null) {
            throw InvoicePaymentRefusedException::aCaptureIsAlreadyRecorded(
                (string) $invoice->getKey(),
                (string) $unapplied->getKey(),
            );
        }
    }

    /**
     * The pending attempt a repeat of this request should reuse, if there is
     * one that still describes the same payment.
     *
     * An attempt with no transaction yet is the timed-out case — we called the
     * provider and never heard back — and is exactly the one that must be
     * reused, because its id is the key the provider may already have seen.
     *
     * An attempt whose transaction is for a different amount no longer
     * describes this payment: the invoice has moved since. Sending different
     * parameters under a key the provider has already answered is how a
     * provider is made to replay the *old* amount, so the stale attempt is
     * abandoned and a fresh key is opened instead.
     *
     * An attempt whose transaction has already succeeded means the money is
     * in. The invoice is still open only because settlement has not caught up,
     * and starting a second payment would collect it twice.
     *
     * An attempt whose transaction has already *failed* is over, whatever its
     * own status column still says — nothing marks an attempt closed when the
     * refusal arrives by webhook, because RecordPaymentFailure writes the
     * ledger row and does not touch payment_attempts. Reusing it would replay
     * an idempotency key the provider has already answered, and a key that has
     * been answered is answered forever: the provider hands back the same
     * refusal for every card the customer tries after it, and the invoice
     * becomes unpayable. The ledger is the authority here, not the flag.
     */
    private function openAttemptFor(Invoice $invoice, Money $amount): ?PaymentAttempt
    {
        /** @var PaymentAttempt|null $pending */
        $pending = PaymentAttempt::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', PaymentAttemptStatus::Pending)
            ->with('transaction')
            ->orderByDesc('attempt_number')
            ->orderByDesc('id')
            ->first();

        if ($pending === null) {
            return null;
        }

        $transaction = $pending->transaction;

        if ($transaction === null) {
            return $pending;
        }

        if ($transaction->status === TransactionStatus::Succeeded) {
            throw InvoicePaymentRefusedException::aCaptureIsAlreadyRecorded(
                (string) $invoice->getKey(),
                (string) $transaction->getKey(),
            );
        }

        if ($transaction->status !== TransactionStatus::Pending) {
            /*
             * The provider has already refused this one. Close the attempt so
             * the count of refusals is right for dunning and support, and open
             * a fresh key rather than asking the provider to answer a question
             * it has already answered no to.
             */
            $this->closeRefusedAttempt($pending, $transaction);

            return null;
        }

        if ($transaction->amount()->equals($amount)) {
            return $pending;
        }

        $pending->forceFill(['status' => PaymentAttemptStatus::Abandoned])->save();

        return null;
    }

    /**
     * Marks an attempt the provider has already refused, so it stops looking
     * live to the next request and to dunning.
     */
    private function closeRefusedAttempt(PaymentAttempt $attempt, Transaction $transaction): void
    {
        $attempt->forceFill([
            'status' => PaymentAttemptStatus::Failed,
            'failure_code' => $transaction->failure_code,
            'failure_message' => $transaction->failure_message,
        ])->save();
    }

    /**
     * Mirrors the provider's answer into the ledger, without settling it.
     *
     * The row is keyed on (provider, provider_reference) — the same unique
     * index RecordPaymentCapture relies on — so the capture that arrives later
     * updates this row rather than creating a second one, and the customer
     * sees one payment in their history instead of two.
     */
    private function recordPendingTransaction(
        Invoice $invoice,
        string $provider,
        PaymentIntentResult $result,
        Money $amount,
        PaymentAttempt $attempt,
    ): Transaction {
        try {
            return $this->writeTransaction($invoice, $provider, $result, $amount, $attempt);
        } catch (UniqueConstraintViolationException) {
            // A webhook for this very intent landed between our locked read
            // and our insert. Its row is committed by now, so the second pass
            // finds it and defers to it.
            return $this->writeTransaction($invoice, $provider, $result, $amount, $attempt);
        }
    }

    private function writeTransaction(
        Invoice $invoice,
        string $provider,
        PaymentIntentResult $result,
        Money $amount,
        PaymentAttempt $attempt,
    ): Transaction {
        return DB::transaction(function () use ($invoice, $provider, $result, $amount, $attempt): Transaction {
            /** @var Transaction|null $existing */
            $existing = Transaction::query()
                ->where('provider', $provider)
                ->where('provider_reference', $result->reference)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status->isFinal()) {
                /*
                 * The provider has already answered for this intent and the
                 * ledger has recorded it — a capture that landed while we were
                 * still describing the intent, or a refusal for an intent
                 * whose creation call we never heard back from. Either way the
                 * row holds the truth and writing "pending" over it would
                 * un-say it: a recorded capture the invoice would then be
                 * settled against but nothing can find, or a recorded refusal
                 * that reappears as a live payment.
                 *
                 * The attempt is closed against the row rather than against
                 * the intent result, so the next request opens a fresh key
                 * instead of asking the provider the same question again.
                 */
                $this->linkAttempt($attempt, $existing, $result);

                return $existing;
            }

            $attributes = [
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->getKey(),
                'provider' => $provider,
                'provider_reference' => $result->reference,
                'kind' => TransactionKind::Charge,
                // A declined intent is a failed transaction, not an error:
                // the integration worked and the answer was no.
                'status' => $result->status->toTransactionStatus(),
                // What we asked to collect, taken from the invoice. The
                // capture event overwrites it with what was actually taken.
                'amount_minor' => $amount->minorUnits(),
                'currency' => $amount->currency(),
                'failure_code' => $result->failureCode,
                'failure_message' => $result->failureMessage,
                // Never the client secret: PaymentIntentResult carries it for
                // the browser and it is not among the fields copied here.
                'provider_metadata' => [
                    'intent_status' => $result->status->value,
                    'object' => $result->metadata,
                ],
                'processed_at' => null,
            ];

            if ($existing !== null) {
                $existing->fill($attributes)->save();
                $transaction = $existing;
            } else {
                /** @var Transaction $transaction */
                $transaction = Transaction::query()->create($attributes);
            }

            $this->linkAttempt($attempt, $transaction, $result);

            return $transaction;
        });
    }

    /**
     * Points the attempt at its transaction and records a decline on it.
     *
     * A successful attempt is never marked here. "Succeeded" on an attempt
     * means the capture was recorded, which is the webhook's decision to make
     * and not this action's.
     *
     * Whether the attempt is dead is read from the ledger row rather than from
     * the intent result. The two agree on the ordinary path, because the row
     * was just written from that result; where they disagree the row is the one
     * that has survived a webhook, and believing the result instead would leave
     * an attempt looking live against a payment the provider has closed.
     */
    private function linkAttempt(PaymentAttempt $attempt, Transaction $transaction, PaymentIntentResult $result): void
    {
        if ($attempt->status !== PaymentAttemptStatus::Pending) {
            return;
        }

        $declined = $transaction->status !== TransactionStatus::Pending
            && $transaction->status !== TransactionStatus::Succeeded;

        $attempt->forceFill([
            'transaction_id' => $transaction->getKey(),
            'status' => $declined ? PaymentAttemptStatus::Failed : PaymentAttemptStatus::Pending,
            'failure_code' => $result->failureCode ?? $transaction->failure_code,
            'failure_message' => $result->failureMessage ?? $transaction->failure_message,
        ])->save();
    }
}
