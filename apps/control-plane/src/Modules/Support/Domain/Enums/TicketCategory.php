<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Domain\Enums;

/**
 * What the ticket is about, chosen by the person who opened it.
 *
 * Deliberately short. A category list long enough to describe everything is a
 * list nobody reads to the end of, and every ticket lands in the first
 * plausible entry — which is worse than five categories used accurately,
 * because it looks like data.
 */
enum TicketCategory: string
{
    case Technical = 'technical';
    case Billing = 'billing';
    case Provisioning = 'provisioning';

    /**
     * A name, rather than a machine.
     *
     * Its own category because domain problems are answered by different
     * people with different tools — a stuck transfer is a conversation with a
     * losing registrar, not a look at a server — and because the deadlines are
     * real: a ticket about a name in its redemption window has days, and one
     * about a slow VPS does not.
     */
    case Domains = 'domains';

    case Abuse = 'abuse';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
