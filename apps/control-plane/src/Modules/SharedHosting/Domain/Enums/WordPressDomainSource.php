<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * Where the name for a WordPress site comes from.
 *
 * Four answers, and they are not cosmetic: each one changes what the platform
 * has to wait for before the site can work, and therefore what the customer
 * should be told while they wait.
 */
enum WordPressDomainSource: string
{
    /** Registered as part of this order. Delegated by this platform, in seconds. */
    case Register = 'register';

    /** A name this account already holds here. Same delegation, already ours. */
    case Existing = 'existing';

    /**
     * Being transferred in.
     *
     * The slow one, and the one most likely to be misread as broken: the
     * losing registrar has up to five days, and nothing this platform does
     * shortens that. The site is built and waits.
     */
    case Transfer = 'transfer';

    /**
     * Registered somewhere else and pointed here by the customer.
     *
     * The platform controls nothing about it: it publishes the records to
     * point at, and waits for somebody to make a change at another company.
     * Nothing here should imply a deadline the platform can meet.
     */
    case External = 'external';

    /**
     * Whether this platform can make the delegation happen by itself.
     *
     * The one question the provisioning flow asks of this enum. Where the
     * answer is false, the platform publishes what to set and waits — and the
     * screen has to say so rather than showing a spinner for a week.
     */
    public function isDelegatedByThisPlatform(): bool
    {
        return $this === self::Register || $this === self::Existing;
    }
}
