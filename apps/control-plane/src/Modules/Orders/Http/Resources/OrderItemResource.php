<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Http\Concerns\SerialisesMoney;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;

/**
 * One purchased line.
 *
 * The currency is passed in from the order rather than read through
 * `$item->order->currency`. An order line has no currency of its own — it is
 * priced in the order's — and reaching back through the relation to find out
 * would load the parent once per line for a list that already has it.
 *
 * @mixin OrderItem
 */
final class OrderItemResource extends JsonResource
{
    use SerialisesMoney;

    public function __construct(OrderItem $resource, private readonly string $currency)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'billing_period' => $this->billing_period->value,
            'quantity' => $this->quantity,

            // The catalogue plan this line came from, so a client can link back
            // to it. The specification of what was sold is the snapshot below,
            // not the plan — the plan may have been repriced or withdrawn since.
            'plan_id' => $this->plan_id,
            'resources' => $this->resources_snapshot,

            'unit_recurring' => $this->moneyOfMinor($this->unit_recurring_minor, $this->currency),
            'unit_setup' => $this->moneyOfMinor($this->unit_setup_minor, $this->currency),
            'discount' => $this->moneyOfMinor($this->discount_minor, $this->currency),
            'tax' => $this->moneyOfMinor($this->tax_minor, $this->currency),
            'total' => $this->moneyOfMinor($this->total_minor, $this->currency),

            'tax_rate' => (string) $this->tax_rate,
            'tax_name' => $this->tax_name,
        ];
    }
}
