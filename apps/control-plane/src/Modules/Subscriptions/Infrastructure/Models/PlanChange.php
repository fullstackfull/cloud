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
 * Written once by ApplyPlanChange, inside the transaction that moved the plan,
 * and never updated. The settlement listener reads it to build the machine to
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
        ];
    }
}
