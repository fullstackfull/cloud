<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Provisioning\Infrastructure\Queries\ServiceIdentities;
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
            'recurring_amount' => $this->moneyOfMinor($this->recurring_amount_minor, $this->currency),

            // The catalogue plan and the order this came from — both within
            // the acting account, so a client can link back to either.
            'plan_id' => $this->plan_id,
            'order_id' => $this->order_id,

            /*
             * What this subscription is FOR.
             *
             * Without these three, a customer with two identical monthly
             * subscriptions sees two rows reading "9.000 KWD — renews on the
             * 1st" and has no way to tell which one runs the machine they
             * still need. That is the whole of the AR-9 defect, and it made
             * cancelling a subscription a guess.
             *
             * The plan name is the plan's own, in the language of the request,
             * and it is the plan the subscription is on rather than whatever
             * the catalogue is selling today. The price above comes from the
             * subscription record for the same reason: a plan whose price rose
             * last week did not raise this agreement.
             */
            'plan' => $this->whenLoaded('plan', fn (): ?array => $this->planSummary()),
            'product' => $this->whenLoaded('plan', fn (): ?array => $this->productSummary()),

            /*
             * Every service this agreement pays for, named the way the
             * customer names it: the hostname, the domain, the serial. A
             * service that is still being created has no identity yet and says
             * so with a null rather than a placeholder.
             */
            'services' => $this->whenLoaded('services', fn (): array => $this->serviceSummaries()),

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

            /*
             * Asked of the model that decides, so neither can drift away from
             * what the platform will do — but `is_scheduled_to_cancel` is
             * qualified by whether the subscription has actually ended.
             *
             * Subscription::isScheduledToCancel() is the cancellation sweep's
             * predicate: it answers "does this row still need the sweep to act
             * on it", and the sweep only ever asks it of a live row. Asked of
             * an ended one it still says yes, because `cancel_at` is left
             * standing when an immediate cancellation overtakes a scheduled
             * one. A client branching on that — which is the only reason the
             * field exists — would tell a customer their service runs until
             * the first of next month on the day it actually stopped.
             */
            'is_scheduled_to_cancel' => $this->resource->ended_at === null
                && $this->resource->isScheduledToCancel(),

            // A scheduled cancellation is honoured by serviceIsRunning even
            // before the sweep moves the status, so a client is never told a
            // service is up on a day the customer has not paid for.
            'service_is_running' => $this->resource->serviceIsRunning(),

            /*
             * How long the data behind this subscription's services is kept
             * once it ends.
             *
             * A platform-wide figure rather than a property of this row, and
             * published here anyway: the screen that asks a customer to
             * confirm a cancellation is the screen that has to tell them what
             * happens to their data, and a portal that hard-coded the number
             * would go on saying thirty the day a deployment changed it.
             */
            'data_retention_days' => max(0, (int) config('provisioning.termination.suspended_retention_days', 30)),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: string, name: string, billing_period: string}|null
     */
    private function planSummary(): ?array
    {
        $plan = $this->resource->plan;

        if ($plan === null) {
            return null;
        }

        return [
            'id' => (string) $plan->getKey(),
            'name' => $plan->nameFor(app()->getLocale()),
            'billing_period' => $this->resource->billing_period->value,
        ];
    }

    /**
     * @return array{id: string, kind: string, name: string}|null
     */
    private function productSummary(): ?array
    {
        $product = $this->resource->plan?->product;

        if ($product === null) {
            return null;
        }

        return [
            'id' => (string) $product->getKey(),
            'kind' => $product->kind->value,
            'name' => $product->nameFor(app()->getLocale()),
        ];
    }

    /**
     * @return list<array{id: string, kind: string, label: string|null, identity: string|null, state: string, is_usable: bool}>
     */
    private function serviceSummaries(): array
    {
        return $this->resource->services
            ->map(fn (Service $service): array => [
                'id' => (string) $service->getKey(),
                'kind' => (string) $service->kind,
                'label' => $service->label,
                'identity' => is_string($service->getAttribute(ServiceIdentities::ATTRIBUTE))
                    ? (string) $service->getAttribute(ServiceIdentities::ATTRIBUTE)
                    : null,
                'state' => CustomerServiceState::for($service->status, false)->value,
                'is_usable' => $service->isUsable(),
            ])
            ->values()
            ->all();
    }
}
