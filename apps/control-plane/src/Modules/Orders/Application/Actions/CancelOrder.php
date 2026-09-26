<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\CompensateUncollectableCapture;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\Exceptions\OrderCannotBeCancelledException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * Withdraws an order the customer has not paid for.
 *
 * Cancellation is deliberately narrow. It is the customer saying "I have not
 * paid and I no longer want this", and the only statuses where that sentence is
 * true are DRAFT, PENDING_PAYMENT and PAYMENT_FAILED. Everything past capture —
 * PAID and every status downstream of it — is a refund: money has moved, a
 * subscription may exist, hardware may be reserved, and unwinding all of that is
 * a different operation with different authorisation. Refusing here is not a
 * missing feature; it is the boundary between the two.
 *
 * MANUAL_REVIEW is excluded even though the state machine permits the move and
 * an order can reach it before capture. An order in review is being looked at by
 * a human, and letting the account under review close the case from the outside
 * is not the customer's call to make.
 *
 * Capacity is released by arithmetic rather than by hand. Plan stock and an
 * unredeemed coupon hold are both counted from live orders and both exclude
 * CANCELLED, so they come back the moment the status changes — there is no
 * compensating write for either that could fail halfway and leave the two
 * disagreeing.
 *
 * ---------------------------------------------------------------------------
 * The invoice is not released by arithmetic, and used not to be released at all
 * ---------------------------------------------------------------------------
 *
 * That sentence above was once written about everything, and it was wrong
 * about the one thing that is a row rather than a count. The order went to
 * CANCELLED and its invoice stayed OPEN — and OPEN is the only collectible
 * status, so the customer's pay button went on working for an order they had
 * just withdrawn.
 *
 * What followed was worse than a stale button. CANCELLED is terminal, so a
 * capture that landed afterwards settled the invoice, announced the order
 * settled, and fulfilment tried to move a cancelled order to PAID — which the
 * state machine refuses. Inside a queued job that is five retries and a
 * permanent failure: money captured, invoice paid, nothing delivered, nothing
 * given back.
 *
 * So the document is withdrawn in the same transaction as the order. Not
 * afterwards: a crash between the two would leave exactly the state this
 * exists to prevent, and the pair either both happen or neither does.
 *
 * A capture that was already in flight when cancellation won is a different
 * problem and is not solved here — it is solved by
 * {@see CompensateUncollectableCapture},
 * because by then the money exists and refusing it would not un-take it.
 *
 * ---------------------------------------------------------------------------
 * "Paid" is the invoice's word before it is the order's (F-05)
 * ---------------------------------------------------------------------------
 *
 * The refusals below used to read the order alone, and the order is the last
 * to hear about money. SettleInvoice marks the invoice paid and announces it;
 * the order leaves PENDING_PAYMENT, and paid_at is stamped, only when the
 * queued FulfilOrderOnSettlement runs on the payments worker. Between the two
 * the order still looked cancellable, a wallet payment (which settles at once)
 * followed by a cancel request inside that window was accepted, and fulfilment
 * then found a cancelled order it could not move to PAID: money taken, nothing
 * delivered, the job in failed_jobs.
 *
 * So the invoice is asked too, and asked under its own row lock inside the
 * cancel transaction. That lock is the one SettleInvoice takes before it
 * applies a payment, so the two serialise: whichever commits first, the other
 * sees it. A cancel that wins voids a document no money has reached, and a
 * capture that lands afterwards is compensated as above. A settlement that
 * wins leaves money on the invoice, and this refuses with
 * `order.already_paid`, which is the truth. Any money applied counts, not
 * only a fully paid document: VoidInvoice refuses to void an invoice a payment
 * was applied to, and a partial payment is still the customer's money.
 */
final readonly class CancelOrder
{
    /** @var list<OrderStatus> */
    private const CANCELLABLE = [
        OrderStatus::Draft,
        OrderStatus::PendingPayment,
        OrderStatus::PaymentFailed,
    ];

    public function __construct(
        private TransitionOrder $transition,
        private VoidInvoice $voidInvoice,
    ) {}

    /**
     * Whether execute() would accept this order right now.
     *
     * Public so that the HTTP layer can tell a client whether to offer the
     * button without restating the rule — a second copy of "which statuses may
     * be cancelled" is a second copy that drifts, and the copy that drifts is
     * always the one the customer sees.
     *
     * An already-cancelled order reports false: execute() converges on it, but
     * there is nothing left to offer. So does an order whose invoice has
     * taken money that fulfilment has not yet recorded on the order — the
     * button would lead only to `order.already_paid`. Read without a lock: an
     * answer for a screen, which execute() re-asks under one.
     */
    public static function isCancellable(Order $order): bool
    {
        return $order->status !== OrderStatus::Cancelled
            && ! $order->status->isPaid()
            && $order->paid_at === null
            && in_array($order->status, self::CANCELLABLE, true)
            && ! self::hasTakenMoney(Invoice::query()->where('order_id', $order->getKey())->get()->all());
    }

    /**
     * @throws OrderCannotBeCancelledException
     * @throws IllegalStateTransitionException when payment lands between the
     *                                         check below and the locked write inside TransitionOrder. The lost race
     *                                         is reported rather than papered over: the customer's order was paid, and
     *                                         answering "cancelled" would be a lie about where their money is.
     */
    public function execute(Order $order, ?User $actor = null, ?string $reason = null): Order
    {
        // Already cancelled is the answer the caller wanted, so a retried
        // request converges instead of failing. TransitionOrder treats a
        // re-entrant transition the same way; returning early here keeps the
        // refusals below from firing on it first.
        if ($order->status === OrderStatus::Cancelled) {
            return $order;
        }

        /*
         * paid_at, not the status, decides *which* refusal the customer is
         * told about, because paid_at is the column that records a capture and
         * the status is not: OrderStatus::isPaid() answers true for
         * MANUAL_REVIEW, which PENDING_PAYMENT reaches when a risk check
         * diverts an order before anybody's card is touched. Telling that
         * customer "this order has already been paid — cancelling it is a
         * refund" is a statement about their money that is simply false, and it
         * points them at a refund request for a payment that never happened.
         */
        if ($order->paid_at !== null) {
            throw OrderCannotBeCancelledException::becauseItIsPaid(
                (string) $order->getKey(),
                $order->status,
            );
        }

        // Both arms refuse. The status test stays as a second lock so that an
        // order whose status implies payment while paid_at is somehow unstamped
        // is still never cancelled from here — it is only described differently.
        if ($order->status->isPaid() || ! in_array($order->status, self::CANCELLABLE, true)) {
            throw OrderCannotBeCancelledException::becauseOfItsStatus(
                (string) $order->getKey(),
                $order->status,
            );
        }

        $why = $reason ?? 'cancelled by the customer';

        return DB::transaction(function () use ($order, $actor, $why): Order {
            /*
             * The invoice first, under the lock SettleInvoice takes: see the
             * class docblock. Locked before the order (TransitionOrder locks
             * that), which is the order SettleInvoice's own chain reaches the
             * two rows in — it never takes the order's lock at all.
             */
            $invoices = Invoice::query()
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->get()
                ->all();

            if (self::hasTakenMoney($invoices)) {
                throw OrderCannotBeCancelledException::becauseItIsPaid(
                    (string) $order->getKey(),
                    $order->status,
                );
            }

            $cancelled = $this->transition->execute(
                $order,
                OrderStatus::Cancelled,
                actorType: $actor !== null ? 'user' : 'system',
                actor: $actor,
                reason: $why,
            );

            $this->withdrawTheInvoice($cancelled, $invoices, $why);

            return $cancelled;
        });
    }

    /**
     * Whether any of these invoices has had money applied to it.
     *
     * A paid document, or an open one carrying a partial payment. Either way
     * the customer has handed money over for this order, and cancelling it is
     * a refund.
     *
     * @param  list<Invoice>  $invoices
     */
    private static function hasTakenMoney(array $invoices): bool
    {
        foreach ($invoices as $invoice) {
            if ($invoice->status === InvoiceStatus::Paid || $invoice->amount_paid_minor > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stops the order's invoice being collectible.
     *
     * Only a collectible one is touched. An invoice that has already taken
     * money cannot be voided — VoidInvoice refuses it, and rightly, because
     * voiding a document a payment was applied to would say that payment never
     * happened — but an order in that position never reaches here either: the
     * refusal on the locked invoices in execute() has already turned it away.
     *
     * The reason is written onto the invoice as well as onto the order's
     * transition, so an operator reading the document alone can see why a
     * number in their series was withdrawn without joining back to the order.
     *
     * @param  list<Invoice>  $invoices  the order's invoices, already locked by execute()
     */
    private function withdrawTheInvoice(Order $order, array $invoices, string $reason): void
    {
        foreach ($invoices as $invoice) {
            if (! $invoice->status->isCollectible()) {
                continue;
            }

            $this->voidInvoice->execute($invoice, sprintf('order %s was cancelled: %s', $order->number, $reason));
        }
    }
}
