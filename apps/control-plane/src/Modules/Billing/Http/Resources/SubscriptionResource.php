<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Billing\Http\Resources\Concerns\SerialisesMoney;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * What a customer may see of their own recurring commitment.
 *
 * The three clocks the model keeps apart are kept apart here too, because
 * collapsing them into one "renews on" field is how a client ends up telling a
 * customer the wrong date:
 *
 *  - current_period_start/end — the window that has been paid for;
 *  - next_invoice_at — when the renewal worker will bill, which may lead the
 *    period end so the invoice arrives before service continues;
 *  - cancel_at — when a scheduled cancellation takes effect.
 *
 * Absent on purpose:
 *
 *  - customer_id: the caller already knows whose account this is.
 *  - failed_payment_count and suspended_at: dunning machinery. How many
 *    attempts the platform has made, and on which internal clock, is an
 *    operational detail; `status` is the customer-visible answer, and
 *    `grace_period_ends_at` is the deadline that actually concerns them.
 *  - coupon_id and coupon_cycles_remaining: the id is an internal identifier
 *    for a campaign, and the remaining-cycles counter is a lever the customer
 *    would reasonably read as a promise about future pricing that the
 *    catalogue does not make.
 *
 * @mixin Subscription
 */
final class SubscriptionResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'billing_period' => $this->billing_period->value,
            'recurring_amount' => $this->money($this->recurring_amount_minor, $this->currency),

            // The catalogue plan and the order this came from — both within
            // the acting account, so a client can link back to either.
            'plan_id' => $this->plan_id,
            'order_id' => $this->order_id,

            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'next_invoice_at' => $this->next_invoice_at?->toIso8601String(),

            'auto_renew' => $this->auto_renew,
            'cancel_at' => $this->cancel_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),

            // The deadline a past-due customer is actually working against.
            // Null unless a payment has failed.
            'grace_period_ends_at' => $this->grace_period_ends_at?->toIso8601String(),

            // Both asked of the model that decides, so neither can drift away
            // from what the platform will do. A scheduled cancellation is
            // honoured by serviceIsRunning even before the sweep moves the
            // status, so a client is never told a service is up on a day the
            // customer has not paid for.
            'is_scheduled_to_cancel' => $this->resource->isScheduledToCancel(),
            'service_is_running' => $this->resource->serviceIsRunning(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
