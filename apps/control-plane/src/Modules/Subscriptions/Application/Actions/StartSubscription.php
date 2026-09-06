<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\SubscriptionCannotStartException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Repositories\CouponTermsRepository;

/**
 * Turns a paid order line into a recurring commitment.
 *
 * The first period is the decision that matters here. It starts when the money
 * was captured — not when the provisioning worker happened to run — and it ends
 * wherever BillingPeriod::advance() puts it, which is calendar-aware: a
 * subscription bought on 31 January renews on 28 February rather than
 * overflowing into 3 March and quietly moving every subsequent anniversary.
 */
final readonly class StartSubscription
{
    public function __construct(
        private CouponTermsRepository $coupons,
    ) {}

    /**
     * @throws SubscriptionCannotStartException
     */
    public function execute(Order $order, OrderItem $item, ?DateTimeImmutable $startAt = null): Subscription
    {
        if ($item->order_id !== $order->getKey()) {
            throw SubscriptionCannotStartException::becauseLineBelongsToAnotherOrder(
                (string) $item->getKey(),
                (string) $order->getKey(),
            );
        }

        if (! $order->status->isPaid()) {
            throw SubscriptionCannotStartException::becauseOrderIsUnpaid(
                (string) $order->getKey(),
                $order->status,
            );
        }

        if ($item->plan_id === null) {
            throw SubscriptionCannotStartException::becauseLineHasNoPlan((string) $item->getKey());
        }

        $start = CarbonImmutable::instance($startAt ?? $order->paid_at ?? CarbonImmutable::now());
        $end = $item->billing_period->advance($start);

        return DB::transaction(function () use ($order, $item, $start, $end): Subscription {
            /*
             * The order row is the mutex. Provisioning is retried, and two
             * runs reaching the existence check at the same instant would
             * otherwise both create a subscription — and the customer would be
             * billed twice, every month, for one service.
             */
            Order::query()->lockForUpdate()->findOrFail($order->getKey());

            /** @var Subscription|null $existing */
            $existing = Subscription::query()
                ->where('order_id', $order->getKey())
                ->where('plan_id', $item->plan_id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $coupon = $this->coupons->find($order->coupon_id);
            // A coupon that was not sold as recurring stops at checkout; it is
            // not carried onto the subscription at all, so no later renewal can
            // rediscover it and start discounting again.
            $recurringCoupon = $coupon !== null && $coupon->appliesToRenewals ? $coupon : null;

            /** @var Subscription $subscription */
            $subscription = Subscription::query()->create([
                'customer_id' => $order->customer_id,
                'order_id' => $order->getKey(),
                'plan_id' => $item->plan_id,
                // Active is the initial state, not a transition into one:
                // there is no prior status for the state machine to check.
                'status' => SubscriptionStatus::Active,
                'currency' => $order->currency,
                'billing_period' => $item->billing_period,
                'recurring_amount_minor' => $this->recurringAmount($order, $item)->minorUnits(),
                'current_period_start' => $start,
                'current_period_end' => $end,
                'next_invoice_at' => $end,
                'auto_renew' => true,
                'failed_payment_count' => 0,
                'coupon_id' => $recurringCoupon?->id,
                'coupon_cycles_remaining' => $recurringCoupon?->durationCycles,
            ]);

            return $subscription;
        });
    }

    /**
     * What renews, which is not what was charged: the setup fee on the order
     * line was a one-off for standing the service up, and billing it again
     * every period is the single most common recurring-billing overcharge.
     */
    private function recurringAmount(Order $order, OrderItem $item): Money
    {
        return Money::ofMinor($item->unit_recurring_minor, $order->currency)
            ->multipliedBy($item->quantity);
    }
}
