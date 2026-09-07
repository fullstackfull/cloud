<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure\Channels;

use Lynomia\Modules\Notifications\Domain\Contracts\NotificationDeliveryChannel;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;

/**
 * The inbox, which needs no delivery at all.
 *
 * The notification row *is* the in-app notification: writing it is the whole
 * of the work. This channel exists so that the inbox appears in the delivery
 * record alongside email — otherwise "which channels did this go down" has an
 * answer that silently omits the one that always worked, and an operator
 * looking at a customer's notification history would see only the failures.
 *
 * It is also the reason nothing else in the system needs to special-case the
 * inbox: every channel goes through the same registry, the same job and the
 * same delivery row.
 */
final readonly class InAppChannel implements NotificationDeliveryChannel
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::InApp;
    }

    public function canReach(Notification $notification): bool
    {
        // Always. The row exists, so the customer can see it.
        return true;
    }

    public function deliver(Notification $notification): string
    {
        return 'inbox';
    }
}
