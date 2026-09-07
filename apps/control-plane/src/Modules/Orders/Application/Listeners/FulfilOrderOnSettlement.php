<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Events\OrderFinanciallySettled;
use Lynomia\Modules\Catalog\Application\Actions\RedeemCoupon;
use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Orders\Application\Actions\TransitionOrder;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Application\Actions\ProvisionOrderedService;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\StartSubscription;
use Throwable;

/**
 * The point at which a settled order becomes a delivered one.
 *
 * It listens to OrderFinanciallySettled rather than to InvoicePaid, and the
 * difference is the whole reason that event exists: an order whose total is
 * zero owes nothing, produces no invoice, and used to sit in PAID for ever with
 * nothing delivered. Both routes to "nothing further is owed" arrive here now.
 *
 * Four things happen here, in this order and for these reasons:
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
 *  4. **The service is created and its provisioning requested.** One service
 *     per purchased line, one job per service, both idempotent on the order
 *     item — including at the database, which holds a unique index on
 *     services.order_item_id so that two workers cannot both decide to build.
 *
 * Idempotent throughout. The transition converges when the order is already
 * paid, StartSubscription refuses to create a second subscription for a line
 * that has one, and ProvisionOrderedService returns the existing service rather
 * than a second machine — so a redelivered webhook, a retried job, or a
 * settlement that ran twice all produce one fulfilment.
 */
final class FulfilOrderOnSettlement implements ShouldQueue
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
        private readonly ProvisionOrderedService $provisionService,
    ) {}

    public function handle(OrderFinanciallySettled $event): void
    {
        $order = Order::query()->with(['items', 'customer'])->find($event->orderId);

        if ($order === null) {
            Log::warning('A settled order no longer exists.', [
                'invoice_id' => $event->invoiceId,
                'order_id' => $event->orderId,
                'basis' => $event->basis->value,
            ]);

            return;
        }

        $order = $this->transition->execute(
            $order,
            OrderStatus::Paid,
            actorType: 'system',
            reason: $event->invoiceId !== null
                ? 'invoice '.$event->invoiceId.' settled'
                : 'nothing was owed on this order',
        );

        $this->redeem($order);

        foreach ($order->items as $item) {
            if ($item->plan_id === null) {
                // Add-on lines ride along with the plan they belong to; they do
                // not get a renewal clock of their own.
                continue;
            }

            $subscription = $this->startSubscription->execute($order, $item);

            /*
             * And the thing the customer actually bought.
             *
             * This is the step that was missing entirely: an order could be
             * paid, its subscription started and its coupon redeemed, and
             * nothing anywhere created a service or asked a provider for a
             * machine. The end-to-end test was called "purchase to active
             * service" and asserted a subscription.
             */
            $this->provisionService->execute($order, $item, $subscription);
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
