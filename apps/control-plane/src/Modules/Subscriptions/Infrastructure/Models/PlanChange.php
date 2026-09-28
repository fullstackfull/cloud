<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One plan change, as it happened: which plan it left and which it moved
 * onto, the money on each side, and the invoice it left behind when it owed
 * something.
 *
 * Written by ApplyPlanChange, inside the transaction that moved the plan; the
 * settlement listener then stamps at most one of `delivered_at` (heard while
 * the subscription was live) or `returned_at` with `return_reason` (the change
 * could no longer be delivered when its payment was captured, and the money
 * went back to the wallet). `returned_at` and `return_reason` are also
 * stamped when the change's payment goes back because its subscription or
 * its service ended before it was delivered (ReturnAnUpgradeTheEndPrevented)
 * - after `delivered_at`, for a change whose settlement was heard and whose
 * resize then stopped on a service that ended - and when an operator returns
 * a paid change held on a live service (ReturnAHeldPaidChange), including one
 * taken out of play with nothing returned because a later change was settled
 * from it (its `return_reason` says so). Nothing else on it is ever
 * updated. The
 * settlement listener reads it to build the machine to
 * what a paid invoice bought, and the period's credit ceiling reads
 * `wallet_credit_minor` to know what earlier changes already gave back.
 *
 * @property string $id
 * @property string $subscription_id
 * @property ?string $from_plan_id
 * @property ?string $to_plan_id
 * @property string $currency
 * @property int $units
 * @property int $credit_minor
 * @property int $charge_minor
 * @property int $wallet_credit_minor
 * @property ?int $from_recurring_amount_minor
 * @property ?string $proration_invoice_id
 * @property array<string, mixed> $resources
 * @property ?string $changed_by_user_id
 * @property CarbonImmutable $changed_at
 * @property ?CarbonImmutable $delivered_at when its settlement was heard while the subscription was live
 * @property ?CarbonImmutable $returned_at when it was taken out of play undelivered: at its settlement, because it could no longer be delivered; at the end of its subscription or service; or by an operator on a live service - its payment returned, or nothing returned when a later change was settled from it
 * @property ?string $return_reason why, in an operator's words (PlanChangeDelivery::refusal(), or ReturnAnUpgradeTheEndPrevented's)
 */
final class PlanChange extends Model
{
    use HasUlids;

    protected $table = 'subscription_plan_changes';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'credit_minor' => 'integer',
            'charge_minor' => 'integer',
            'wallet_credit_minor' => 'integer',
            'from_recurring_amount_minor' => 'integer',
            'resources' => 'array',
            'changed_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }
}
