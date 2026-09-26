<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Domain\Events\InvoiceVoided;
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * A plan-change invoice was voided, so the upgrade it billed is undone.
 *
 * {@see ApplyPlanChange} moves the subscription onto the new plan, and its
 * recurring amount with it, the moment an upgrade is confirmed, and hands over
 * the bigger machine only once the invoice is paid. A voided invoice will
 * never be paid. Left alone, the subscription stayed on the plan it never
 * bought: every renewal billed the higher amount for the smaller machine, and
 * the next plan change credited the unpaid plan's remainder as though it had
 * been paid for - an operator's void of an 18.000 upgrade turned into a
 * 30.000 credit against the customer's next change.
 *
 * So the subscription goes back to the plan and the recurring amount the
 * change recorded it came from. Nothing is resized: the machine was never
 * grown, because an upgrade is only delivered on payment.
 *
 * Only when the subscription is still exactly where the voided change put it,
 * no change has been made since, and the subscription has not ended.
 * Otherwise the void is of a change the subscription has already left, or of
 * the open invoices of a subscription being wound up, and there is nothing to
 * put back - an ended subscription is not moved or audited as a plan change.
 *
 * The plan it goes back to always has room: while an upgrade is unpaid, the
 * unit it left keeps counting against that plan (PlanCapacity::claimed()), so
 * nobody else can have been sold it. The plan row is still locked, through
 * the lock every checkout takes, so the move and any checkout serialise.
 *
 * Synchronous, and inside VoidInvoice's transaction (see InvoiceVoided): the
 * void and the restore are one unit. Queued, it left a window in which the
 * invoice was void, nothing open refused a change, and the customer could
 * step off the unpaid plan and be credited for it.
 */
final readonly class RestorePlanOnVoidedUpgrade
{
    public function __construct(
        private RecordAuditEntry $audit,
        private PlanCapacity $capacity,
    ) {}

    public function handle(InvoiceVoided $event): void
    {
        if ($event->subscriptionId === null) {
            return;
        }

        /** @var PlanChange|null $change */
        $change = PlanChange::query()->where('proration_invoice_id', $event->invoiceId)->first();

        if ($change === null || $change->from_plan_id === null || $change->from_recurring_amount_minor === null) {
            // Not a plan-change invoice, or one recorded before the amount it
            // replaced was: nothing to put back.
            return;
        }

        DB::transaction(function () use ($change, $event): void {
            /** @var Subscription|null $subscription */
            $subscription = Subscription::query()->lockForUpdate()->find($change->subscription_id);

            if ($subscription === null
                || $subscription->status->isTerminal()
                || $subscription->plan_id !== $change->to_plan_id) {
                return;
            }

            $superseded = PlanChange::query()
                ->where('subscription_id', $change->subscription_id)
                ->where(static fn ($later) => $later
                    ->where('changed_at', '>', $change->changed_at)
                    ->orWhere(static fn ($same) => $same
                        ->where('changed_at', $change->changed_at)
                        ->where('id', '>', $change->id)))
                ->exists();

            if ($superseded) {
                return;
            }

            $this->capacity->lock([(string) $change->from_plan_id]);

            $subscription->plan_id = $change->from_plan_id;
            $subscription->recurring_amount_minor = $change->from_recurring_amount_minor;
            $subscription->save();

            $this->audit->execute(
                action: AuditAction::PlanChanged,
                subject: $subscription,
                customerId: $subscription->customer_id,
                context: [
                    'from_plan_id' => $change->to_plan_id,
                    'to_plan_id' => $change->from_plan_id,
                    'reason' => 'proration_invoice_voided',
                    'proration_invoice_id' => $event->invoiceId,
                    'plan_change_id' => (string) $change->getKey(),
                ],
            );
        });
    }
}
