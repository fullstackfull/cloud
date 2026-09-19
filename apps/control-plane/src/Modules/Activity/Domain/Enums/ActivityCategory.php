<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Domain\Enums;

/**
 * The part of the account an activity row belongs to.
 *
 * Deliberately coarse, and deliberately the customer's own division of their
 * account rather than the platform's module list. A customer filtering their
 * history thinks "show me the domain things", not "show me rows the Domains
 * module wrote" — and the two would diverge the moment a domain renewal
 * started producing an invoice.
 *
 * These are the only values `?category=` accepts, and the filter is applied in
 * SQL. A category that filtered one page of results in the browser would show
 * three rows out of a hundred and call itself complete.
 */
enum ActivityCategory: string
{
    /** Machines: cloud servers, dedicated servers, their power and rebuilds. */
    case Cloud = 'cloud';

    /** Shared hosting accounts and WordPress sites. */
    case Hosting = 'hosting';

    /** Names and zones. */
    case Domains = 'domains';

    /** Orders, invoices, payments, subscriptions. */
    case Billing = 'billing';

    /** Support requests. */
    case Support = 'support';

    /** Backups and restores. */
    case Backups = 'backups';
}
