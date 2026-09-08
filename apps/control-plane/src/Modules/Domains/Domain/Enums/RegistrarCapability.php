<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Enums;

/**
 * What a registrar can be asked to do.
 *
 * ---------------------------------------------------------------------------
 * Why the core asks instead of assuming
 * ---------------------------------------------------------------------------
 *
 * Because registrars differ enormously, and the differences are not
 * embarrassing edge cases — they are the ordinary shape of the industry. Some
 * resellers expose transfers and some do not. Some return a registry
 * expiration date and some return their own. Some support ten-year terms and
 * some sell one year at a time. A registry operated directly by a national
 * authority may have no concept of a transfer auth code at all.
 *
 * The alternative to asking is an interface where every method exists and
 * half of them throw, which pushes the question to runtime and answers it in
 * front of a customer who has already paid.
 *
 * This is the same pattern the hosting module already uses to let cPanel and
 * DirectAdmin differ, and the reason a screen can hide a button rather than
 * offering one that fails.
 */
enum RegistrarCapability: string
{
    /** Ask whether a name can be bought. */
    case Availability = 'availability';

    case Registration = 'registration';
    case Renewal = 'renewal';

    /** Bring a name in from another registrar. */
    case TransferIn = 'transfer_in';

    /** Set or read the registry's transfer lock. */
    case TransferLock = 'transfer_lock';

    /** Produce the code that authorises a transfer away. */
    case AuthCode = 'auth_code';

    /** Change the contact set filed with the registry. */
    case Contacts = 'contacts';

    /** Change the delegation. */
    case Nameservers = 'nameservers';

    /**
     * Quote a per-name price the registry sets rather than the TLD's list
     * price. A provider without this can still sell ordinary names; it simply
     * cannot sell premium ones, and the search says so.
     */
    case PremiumPricing = 'premium_pricing';

    /** Recover a name from the registry's redemption period. */
    case Redemption = 'redemption';

    /** Terms longer than a single year. */
    case MultiYearTerms = 'multi_year_terms';

    /**
     * Read back what the registry believes: expiry, nameservers, lock, status.
     *
     * The capability reconciliation is built on. A provider without it can
     * still sell domains; the platform simply cannot check its own records
     * against anything, and the reconciliation sweep says so rather than
     * reporting everything as agreeing.
     */
    case Inspection = 'inspection';
}
