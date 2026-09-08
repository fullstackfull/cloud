<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Domain\Contracts;

use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;

/**
 * One way of getting a notification to somebody.
 *
 * The seam that keeps the twenty places which raise a notification from
 * knowing that email exists. A channel added later — SMS, push — implements
 * this and is bound in the registry; nothing that sends a notification
 * changes.
 *
 * `deliver()` returns the destination it used, for the delivery record, and
 * throws to report failure. It must not swallow its own errors: a channel that
 * returned quietly on failure would leave a delivery row saying `sent` for a
 * message nobody received, which is worse than no record at all.
 */
interface NotificationDeliveryChannel
{
    public function channel(): NotificationChannel;

    /**
     * Whether this channel can reach this particular notification's audience.
     *
     * Checked before an attempt is recorded, so that "the customer has no
     * email address" is not counted as a delivery failure — it is an absence,
     * and filling the failure screen with it hides the real bounces.
     */
    public function canReach(Notification $notification): bool;

    /**
     * @return string the destination used, for the record
     */
    public function deliver(Notification $notification): string;
}
