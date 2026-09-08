<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Domain\Enums;

/**
 * How badly this is hurting.
 *
 * Set by the customer when they open the ticket and changed only by an
 * operator afterwards. That asymmetry is deliberate: a customer's sense of
 * urgency is real information and worth capturing, and a customer who could
 * keep raising their own priority would sort the whole queue by whoever is
 * most persistent.
 */
enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /** Worst first, for the operator queue's ordering. */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::High => 1,
            self::Normal => 2,
            self::Low => 3,
        };
    }

    /**
     * What a customer may choose.
     *
     * Urgent is not among them. It means "a paying service is down right now"
     * and it is what pages somebody out of hours; leaving it to be
     * self-selected makes it meaningless within a month, and a meaningless
     * urgent flag is worse than none because it teaches the team to ignore it.
     *
     * @return list<self>
     */
    public static function customerSelectable(): array
    {
        return [self::Low, self::Normal, self::High];
    }

    /** @return list<string> */
    public static function customerSelectableValues(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::customerSelectable());
    }
}
