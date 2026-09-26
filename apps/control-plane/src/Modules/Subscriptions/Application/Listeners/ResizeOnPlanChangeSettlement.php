<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\QueuePlanChangeAtProvider;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\PlanResources;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * The upgrade was paid for, so now the customer may have it.
 *
 * ---------------------------------------------------------------------------
 * Why the resize is not queued when the change is requested
 * ---------------------------------------------------------------------------
 *
 * An upgrade is a purchase. The platform's rule everywhere else is that
 * nothing is handed over against an open invoice — an order provisions on
 * settlement, never on placement — and a plan change had been the one place
 * that broke it: the machine grew the moment the customer pressed confirm and
 * the money was a separate question afterwards. So
 * {@see ApplyPlanChange}
 * now issues the proration invoice and queues nothing, and the resize waits
 * here for the same event that fulfils an order.
 *
 * A downgrade and a like-for-like change do not come through here at all.
 * Neither owes anything, so neither produces an invoice, and both are queued
 * at the moment they are requested.
 *
 * ---------------------------------------------------------------------------
 * Telling a proration invoice from a renewal invoice
 * ---------------------------------------------------------------------------
 *
 * Both carry a subscription id, and a renewal being paid must not resize
 * anything. The line kinds are what separate them: a renewal bills
 * {@see InvoiceItemKind::Plan}, and only a plan change writes a
 * {@see InvoiceItemKind::Proration} line. That is read off the invoice rather
 * than inferred from the subscription's state, because by the time the money
 * arrives the subscription looks identical either way.
 *
 * ---------------------------------------------------------------------------
 * Which plan gets built
 * ---------------------------------------------------------------------------
 *
 * The one this invoice paid for, read from the {@see PlanChange} row that
 * ApplyPlanChange wrote in the same transaction as the invoice: its target
 * plan and the shape the quote stated. Not the plan the subscription holds
 * when the money arrives. This used to read the subscription, on the theory
 * that "the current plan is the right answer anyway", and the re-audit paid a
 * 0.667 KWD invoice for small -> mid and was handed the 90.000 KWD large plan
 * a later, unpaid change had moved the subscription onto (F-01).
 *
 * A later change can still make this invoice's shape the wrong one to build.
 * If a change recorded after it has already been settled - it owed nothing
 * and queued its own resize, or its own invoice was paid - that change
 * decides the machine, and this one builds nothing. If every later change is
 * still waiting on its invoice, this one is the newest thing paid for, and it
 * is built.
 *
 * A proration invoice with no recorded change can only be one issued before
 * the record existed: every change since writes its row in the transaction
 * that issues its invoice. Such an invoice was issued under the old rule, and
 * while it was open no further change could be made, so the plan the
 * subscription holds is the plan it bought. It is built as it always was,
 * from that plan, and the fallback says so in the log. Building nothing would
 * take a customer's money for an upgrade issued the day before this record
 * was deployed and never deliver it.
 *
 * Queued on payments beside the other settlement work, and idempotent twice
 * over: the provisioning job is keyed on the invoice that paid for it, so a
 * redelivered settlement finds the job the first delivery made instead of
 * resizing the machine again.
 */
final class ResizeOnPlanChangeSettlement implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    /**
     * A wait before every retry. Five tries with no ladder is not five
     * attempts; it is one attempt five times inside the same outage, on the
     * queue that moves money (F-08).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }

    public function __construct(
        private readonly QueuePlanChangeAtProvider $queueAtProvider,
    ) {}

    public function handle(InvoicePaid $event): void
    {
        if ($event->subscriptionId === null) {
            return;
        }

        $carriesProration = InvoiceItem::query()
            ->where('invoice_id', $event->invoiceId)
            ->where('kind', InvoiceItemKind::Proration->value)
            ->exists();

        if (! $carriesProration) {
            // A renewal, or any other invoice that happens to name a
            // subscription. Nothing about the product changed.
            return;
        }

        $change = PlanChange::query()->where('proration_invoice_id', $event->invoiceId)->first();

        if ($change === null) {
            $this->buildTheCurrentPlanForAnInvoiceIssuedBeforeTheRecord($event);

            return;
        }

        $subscription = Subscription::query()->find($change->subscription_id);

        if ($subscription === null || $change->to_plan_id === null) {
            Log::warning('A paid proration invoice names a subscription or a plan that no longer exists.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $change->subscription_id,
            ]);

            return;
        }

        if ($this->aLaterChangeHasBeenSettled($change)) {
            return;
        }

        $this->queueAtProvider->execute(
            subscription: $subscription,
            planId: $change->to_plan_id,
            resources: PlanResources::fromArray($change->resources),
            idempotencyKey: 'invoice:'.$event->invoiceId,
        );
    }

    /**
     * The rule proration invoices issued before `subscription_plan_changes`
     * existed were issued under: build the plan the subscription holds.
     */
    private function buildTheCurrentPlanForAnInvoiceIssuedBeforeTheRecord(InvoicePaid $event): void
    {
        $subscription = Subscription::query()->find($event->subscriptionId);
        $plan = $subscription?->plan()->first();

        if ($subscription === null || $plan === null) {
            Log::warning('A paid proration invoice names a subscription or a plan that no longer exists.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $event->subscriptionId,
            ]);

            return;
        }

        Log::info('A paid proration invoice predates the plan-change record; building the plan the subscription holds.', [
            'invoice_id' => $event->invoiceId,
            'subscription_id' => $event->subscriptionId,
        ]);

        $this->queueAtProvider->execute(
            subscription: $subscription,
            planId: (string) $plan->getKey(),
            resources: PlanResources::fromArray($plan->resources),
            idempotencyKey: 'invoice:'.$event->invoiceId,
        );
    }

    /**
     * Whether a plan change made after this one has already been settled -
     * it owed nothing, or its invoice was paid - and so has queued the
     * machine's shape itself.
     */
    private function aLaterChangeHasBeenSettled(PlanChange $change): bool
    {
        return PlanChange::query()
            ->where('subscription_id', $change->subscription_id)
            ->where(static fn ($later) => $later
                ->where('changed_at', '>', $change->changed_at)
                ->orWhere(static fn ($same) => $same
                    ->where('changed_at', $change->changed_at)
                    ->where('id', '>', $change->id)))
            ->where(static fn ($settled) => $settled
                ->whereNull('proration_invoice_id')
                ->orWhereExists(static fn ($paid) => $paid
                    ->selectRaw('1')
                    ->from('invoices')
                    ->whereColumn('invoices.id', 'subscription_plan_changes.proration_invoice_id')
                    ->where('invoices.status', InvoiceStatus::Paid->value)))
            ->exists();
    }
}
