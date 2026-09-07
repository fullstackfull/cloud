<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Orders\Application\Actions\CancelOrder;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;

/**
 * What a customer may see of their own order.
 *
 * Absent on purpose, and each for its own reason:
 *
 *  - customer_id and placed_by_user_id: the caller already knows which account
 *    they are acting for, and the ids buy them nothing but a shape to probe.
 *  - idempotency_key: a replay credential. Echoing it back lets anyone who can
 *    read one response replay a purchase against the account.
 *  - coupon_id: an internal identifier for a campaign. The code the customer
 *    typed is theirs and is returned; the row id is not.
 *  - billing_snapshot: the customer's own billing details are served by the
 *    profile endpoints. Repeating a frozen copy of a tax id and address on
 *    every order widens where that data can leak from without adding anything.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    use SerialisesMoney;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'currency' => $this->currency,

            'subtotal' => $this->moneyOfMinor($this->subtotal_minor, $this->currency),
            'discount' => $this->moneyOfMinor($this->discount_minor, $this->currency),
            'tax' => $this->moneyOfMinor($this->tax_minor, $this->currency),
            'total' => $this->moneyOfMinor($this->total_minor, $this->currency),

            // The customer's own note back to them, not an operator's.
            'notes' => $this->notes,

            /*
             * From paid_at, not from OrderStatus::isPaid(). The enum answers
             * true for MANUAL_REVIEW, which is reachable from PENDING_PAYMENT
             * before any capture, so a status-derived flag reports "paid" on an
             * order that shows `paid_at: null` two lines below it — the same
             * payload contradicting itself. paid_at is stamped by
             * TransitionOrder on the move into PAID and is the only field here
             * that means money changed hands.
             */
            'is_paid' => $this->paid_at !== null,
            // Asked of the action that decides, so the flag cannot drift away
            // from what the endpoint would actually do.
            'is_cancellable' => CancelOrder::isCancellable($this->resource),

            'coupon_code' => $this->whenLoaded('coupon', fn (): ?string => $this->coupon?->code),

            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded(
                'items',
                fn (): array => $this->items
                    ->map(fn (OrderItem $item): OrderItemResource => new OrderItemResource($item, $this->currency))
                    ->all(),
            ),

            'placed_at' => $this->placed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
