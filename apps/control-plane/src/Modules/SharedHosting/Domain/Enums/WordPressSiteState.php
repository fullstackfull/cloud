<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * How far along a WordPress site is, in the customer's terms.
 *
 * ---------------------------------------------------------------------------
 * Why there are this many
 * ---------------------------------------------------------------------------
 *
 * Because each one is a different answer to "why can I not see my site yet",
 * and a customer who gets the wrong answer does the wrong thing. Someone
 * waiting on DNS should be told to wait; someone whose install failed should
 * be told to talk to support; someone whose certificate is pending should be
 * told their site works and the padlock is coming.
 *
 * Collapsing these into "provisioning" would be tidier and would put every one
 * of those customers into the same support queue.
 *
 * ---------------------------------------------------------------------------
 * `Ready` means verified, not requested
 * ---------------------------------------------------------------------------
 *
 * A site reaches `Ready` when the platform has fetched it and WordPress
 * answered. Not when the installer returned success — installers return
 * success for sites that then serve a blank page, a database error, or the
 * panel's default holding page.
 */
enum WordPressSiteState: string
{
    /** Paid for, nothing built yet. */
    case Requested = 'requested';

    /** The hosting account exists. The name does not point at it yet. */
    case AwaitingDns = 'awaiting_dns';

    /** The name resolves here. WordPress is being installed. */
    case Installing = 'installing';

    /**
     * WordPress answered and the certificate has not arrived.
     *
     * A real state rather than a rounding error: the site works over HTTP and
     * a customer visiting it sees a browser warning. Telling them it is
     * "ready" invites a support ticket about a broken site that is not broken.
     */
    case AwaitingCertificate = 'awaiting_certificate';

    /** Verified: the platform fetched the site and WordPress answered. */
    case Ready = 'ready';

    /** Something answered and said no. Recoverable, usually by a person. */
    case Failed = 'failed';

    /**
     * Nobody knows.
     *
     * The same vocabulary as every other module here, and for the same reason:
     * an installer that stopped answering may have installed WordPress, and a
     * second attempt over the top of a half-built site is how a customer loses
     * content they had already added.
     */
    case Indeterminate = 'indeterminate';

    /** A person has to decide, and the reason is on the row. */
    case NeedsReview = 'needs_review';

    /** Taken down. Terminal. */
    case Removed = 'removed';

    /** Whether the platform is still working on it without being asked. */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::Requested, self::AwaitingDns, self::Installing, self::AwaitingCertificate => true,
            default => false,
        };
    }

    public function needsAttention(): bool
    {
        return $this === self::Indeterminate
            || $this === self::NeedsReview
            || $this === self::Failed;
    }

    /**
     * Whether the customer can use the site now.
     *
     * `AwaitingCertificate` answers true, and that is the point of having the
     * state: the site is up and serving, and what is missing is the padlock.
     */
    public function isUsable(): bool
    {
        return $this === self::Ready || $this === self::AwaitingCertificate;
    }

    /**
     * Whether an installation may be attempted.
     *
     * `Indeterminate` is excluded deliberately. An installer that went quiet
     * may have written a database and a wp-config; installing again over the
     * top of it is how somebody's first afternoon of work disappears.
     */
    public function permitsInstallation(): bool
    {
        return $this === self::Requested
            || $this === self::AwaitingDns
            || $this === self::Failed;
    }
}
