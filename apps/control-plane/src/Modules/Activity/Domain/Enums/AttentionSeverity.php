<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Domain\Enums;

/**
 * How much a thing needing attention actually matters.
 *
 * Three levels, and the server assigns them — not the screen. The dashboard's
 * whole purpose is to put the right thing at the top, and ordering by
 * translated sentence text or by whatever the query returned first is how a
 * customer meets an unpaid invoice below a domain that expires in a month.
 *
 * The rank is explicit so the sort is deterministic across languages: an
 * Arabic dashboard and an English one show the same list in the same order.
 */
enum AttentionSeverity: string
{
    /**
     * Something is broken or about to cost the customer something: an invoice
     * already overdue, a name in its redemption window, a result nobody knows.
     */
    case Critical = 'critical';

    /**
     * Something needs doing soon, and nothing is lost yet: an invoice due this
     * week, a name expiring this month, a request waiting on a reply.
     */
    case Warning = 'warning';

    /** Worth knowing about, safe to ignore today. */
    case Info = 'info';

    /** Lower sorts first. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
