<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Catalog\Application\Actions\RedeemCoupon;
use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Orders\Application\Actions\TransitionOrder;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\StartSubscription;
use Throwable;

/**
 * The point at which a paid invoice becomes a fulfilled order.
 *
 * Three things happen here, in this order and for these reasons:
 *
 *  1. **The order is marked paid.** This is what unlocks provisioning; the
 *     state machine will not let an unpaid order reach it.
 *
 *  2. **The coupon is redeemed.** Redemption is deferred to this moment rather
 *     than taken at checkout, because a basket that is abandoned must not
 *     consume a single-use code — a coupon reserved at checkout would be held
 *     hostage by everyone who closed the tab. A redemption that fails does not
 *     fail the fulfilment: the customer has paid, and a discount already
 *     applied to their invoice is not worth withholding a service over. It is
 *     logged for reconciliation instead.
 *
 *  3. **Subscriptions are started, one per plan line.** This is what puts the
 *     service on a renewal clock.
 *
 * Idempotent throughout. The transition converges when the order is already
 * paid, and StartSubscription refuses to create a second subscription for a
 * line that has one — so a redelivered webhook, a retried job, or a settlement
 * that ran twice all produce one fulfilment.
 */
final class FulfilOrderOnInvoicePaid implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }

    public function __construct(
        private readonly TransitionOrder $transition,
        private readonly StartSubscription $startSubscription,
        private readonly RedeemCoupon $redeemCoupon,
    ) {}

    public function handle(InvoicePaid $event): void
    {
        if ($event->orderId === null) {
            // A renewal invoice has a subscription but no order. Its period was
            // already advanced when the invoice was generated; there is nothing
            // to fulfil here.
            return;
        }

        $order = Order::query()->with(['items', 'customer'])->find($event->orderId);

        if ($order === null) {
            Log::warning('Paid invoice refers to an order that no longer exists.', [
                'invoice_id' => $event->invoiceId,
                'order_id' => $event->orderId,
            ]);

            return;
        }

        $order = $this->transition->execute(
            $order,
            OrderStatus::Paid,
            actorType: 'system',
            reason: 'invoice '.$event->invoiceId.' settled',
        );

        $this->redeem($order);

        foreach ($order->items as $item) {
            if ($item->plan_id === null) {
                // Add-on lines ride along with the plan they belong to; they do
                // not get a renewal clock of their own.
                continue;
            }

            $this->startSubscription->execute($order, $item);
        }
    }

    /**
     * Records the coupon redemption the checkout only validated.
     */
    private function redeem(Order $order): void
    {
        if ($order->coupon_id === null || $order->customer === null) {
            return;
        }

        if (DB::table('coupon_redemptions')->where('order_id', $order->getKey())->exists()) {
            // Already recorded by an earlier delivery of this event.
            return;
        }

        try {
            $coupon = $order->coupon()->first();

            if ($coupon === null) {
                return;
            }

            $this->redeemCoupon->execute(
                $coupon,
                new CouponContext(
                    customer: $order->customer,
                    orderAmount: Money::ofMinor($order->subtotal_minor + $order->discount_minor, $order->currency),
                    planIds: $order->items->pluck('plan_id')->filter()->values()->all(),
                ),
                orderId: (string) $order->getKey(),
            );
        } catch (Throwable $e) {
            /*
             * The customer has paid and the discount is already on their
             * invoice. Failing the whole fulfilment — and with it the service
             * they bought — because a redemption counter could not be written
             * would be the wrong trade. Recorded for reconciliation instead.
             */
            Log::warning('Could not record a coupon redemption for a paid order.', [
                'order_id' => (string) $order->getKey(),
                'coupon_id' => $order->coupon_id,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
