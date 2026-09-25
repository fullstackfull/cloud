<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\QueuePlanChangeAtProvider;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\PlanResources;
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
 * The shape is re-derived from the plan the subscription holds now, not from
 * the invoice, which records money rather than resources. The subscription was
 * moved onto the target plan when the change was requested, so in the ordinary
 * case the two are the same. Where they are not — a second plan change while
 * the first invoice was still open — the current plan is the right answer
 * anyway: the machine should end up matching what the customer is being billed
 * for, not an instruction that has since been superseded.
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

        $subscription = Subscription::query()->find($event->subscriptionId);

        if ($subscription === null) {
            /*
             * Recorded rather than thrown, for the same reason its sibling on
             * this event does: five retries cannot make the row reappear, and
             * failing the job would leave a settled payment looking unhandled.
             */
            Log::warning('A paid proration invoice names a subscription that does not exist.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $event->subscriptionId,
            ]);

            return;
        }

        $plan = $subscription->plan()->first();

        if ($plan === null) {
            Log::warning('A paid proration invoice names a subscription with no plan.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $event->subscriptionId,
            ]);

            return;
        }

        $this->queueAtProvider->execute(
            subscription: $subscription,
            planId: (string) $plan->getKey(),
            resources: PlanResources::fromArray($plan->resources),
            idempotencyKey: 'invoice:'.$event->invoiceId,
        );
    }
}
