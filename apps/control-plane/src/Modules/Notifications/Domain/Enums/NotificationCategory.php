<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Domain\Enums;

/**
 * What kind of message this is, which decides whether a customer may turn it
 * off.
 *
 * The distinction is not cosmetic. A customer who has silenced "your card was
 * declined" and is then suspended has a legitimate complaint, and a platform
 * that let them do it has no answer. So the categories a customer may disable
 * are stated here rather than being a per-type flag somebody sets by hand.
 */
enum NotificationCategory: string
{
    /**
     * Sign-ins, password changes, two-factor, new devices. Never optional: a
     * customer cannot opt out of being told their account was accessed.
     */
    case Security = 'security';

    /**
     * Money. Invoices, payments, refunds, dunning. Never optional either —
     * these carry the obligation to pay and the warning before service is
     * taken away.
     */
    case Billing = 'billing';

    /**
     * A service was created, failed, suspended, restored, reinstalled or
     * destroyed. Optional per channel but not in-app: a customer may silence
     * the emails, and the record still has to exist somewhere they can find
     * it after their server disappears.
     */
    case Service = 'service';

    /**
     * Planned maintenance and incidents affecting this customer. Optional by
     * email, always in-app, for the same reason.
     */
    case Operational = 'operational';

    /**
     * Whether a customer may switch this category off entirely on a channel.
     */
    public function isOptional(): bool
    {
        return match ($this) {
            self::Security, self::Billing => false,
            self::Service, self::Operational => true,
        };
    }

    /**
     * Channels a customer may disable for this category.
     *
     * In-app is absent from every list on purpose. The inbox is the platform's
     * own record of what it did to somebody's account, and a customer who
     * silenced it would be looking at an empty page after their service was
     * terminated.
     *
     * @return list<NotificationChannel>
     */
    public function disableableChannels(): array
    {
        return $this->isOptional() ? [NotificationChannel::Email] : [];
    }
}
