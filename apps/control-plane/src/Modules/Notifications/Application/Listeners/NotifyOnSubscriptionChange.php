<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Domain\Events\SubscriptionStatusChanged;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Tells a customer when their service is about to be, or has been, taken away.
 *
 * These are the messages a hosting platform cannot afford to get wrong. A
 * customer whose server is switched off without warning has a complaint the
 * platform cannot answer, and one who pays and is not told their service is
 * back opens a ticket.
 *
 * Keyed on the edge rather than the destination — the same reason the
 * enforcement listener is. A subscription that reaches active from past_due
 * was never switched off, and telling that customer their service has been
 * "restored" would be announcing an outage they did not have.
 */
final class NotifyOnSubscriptionChange implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(
        private readonly NotifyCustomer $notify,
    ) {}

    public function handle(SubscriptionStatusChanged $event): void
    {
        $type = $this->typeFor($event);

        if ($type === null) {
            return;
        }

        $subscription = Subscription::query()->find($event->subscriptionId);

        $this->notify->execute(
            customerId: $event->customerId,
            type: $type,
            /*
             * The edge and the moment. A subscription can legitimately go
             * past_due, recover, and go past_due again months later, and both
             * deserve a message — so the key carries the timestamp of this
             * particular transition rather than only the pair of statuses.
             */
            idempotencyKey: sprintf(
                'subscription:%s:%s->%s:%d',
                $event->subscriptionId,
                $event->from->value,
                $event->to->value,
                $event->changedAt->getTimestamp(),
            ),
            subject: $subscription,
            data: [
                'service' => $this->label($event->subscriptionId),
                'date' => $event->changedAt->toDateString(),
                'grace_ends' => $subscription?->grace_period_ends_at?->toDateString() ?? '',
                'amount' => '',
            ],
            link: '/subscriptions',
        );
    }

    private function typeFor(SubscriptionStatusChanged $event): ?NotificationType
    {
        return match (true) {
            // The grace period opening. This is the message that gives a
            // customer the chance to fix their card before anything happens.
            $event->to === SubscriptionStatus::PastDue => NotificationType::GracePeriodStarted,

            $event->to === SubscriptionStatus::Suspended => NotificationType::ServiceSuspended,

            // Only from suspended. Arriving at active from past_due means the
            // customer paid before anything was switched off, and calling that
            // a restoration announces an outage they did not have.
            $event->to === SubscriptionStatus::Active
                && $event->from === SubscriptionStatus::Suspended => NotificationType::ServiceRestored,

            $event->to === SubscriptionStatus::Terminated => NotificationType::ServiceTerminated,

            /*
             * Cancellation is deliberately absent, in both directions.
             *
             * Arranging one changes no status at all — only a date — so this
             * listener would never see it, and the action that arranges it
             * sends that message itself. And a cancellation taking effect is
             * announced by the listener that ends the service, because the
             * sentence has to quote the date the data goes and only that
             * listener has written it down. Two queued listeners on one event
             * have no order between them, and a message that raced the fact it
             * describes would quote an empty date.
             */

            default => null,
        };
    }

    /**
     * The customer's own name for the thing, not a subscription id.
     */
    private function label(string $subscriptionId): string
    {
        $service = Service::query()->where('subscription_id', $subscriptionId)->first();

        $label = $service?->label;

        return is_string($label) && $label !== '' ? $label : 'your service';
    }
}
