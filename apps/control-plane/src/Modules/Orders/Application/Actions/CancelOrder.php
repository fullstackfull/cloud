<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Actions;

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
 * Nothing is released by hand. Plan stock and an unredeemed coupon hold are both
 * counted from live orders and both exclude CANCELLED, so they come back the
 * moment the status changes — there is no compensating write here that could
 * fail halfway and leave the two disagreeing.
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
     * there is nothing left to offer.
     */
    public static function isCancellable(Order $order): bool
    {
        return $order->status !== OrderStatus::Cancelled
            && ! $order->status->isPaid()
            && $order->paid_at === null
            && in_array($order->status, self::CANCELLABLE, true);
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

        return $this->transition->execute(
            $order,
            OrderStatus::Cancelled,
            actorType: $actor !== null ? 'user' : 'system',
            actor: $actor,
            reason: $reason ?? 'cancelled by the customer',
        );
    }
}
