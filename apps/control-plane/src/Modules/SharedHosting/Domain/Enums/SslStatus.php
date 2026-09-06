<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * The certificate state of an account's primary domain, as the panel reports it.
 *
 * Kept separate from the account status because the two fail independently: an
 * account can be perfectly active with an expired certificate, which is the
 * state customers report as "my site is down" even though nothing on the node
 * has stopped.
 *
 * Unknown is a real case and not a default to be avoided. A panel that is
 * mid-restart, or one whose AutoSSL run has not completed, answers with
 * nothing — and recording that as None would tell a renewal job to issue a
 * certificate for a domain that already has one.
 */
enum SslStatus: string
{
    case Unknown = 'unknown';
    case None = 'none';
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Failed = 'failed';

    /** Whether traffic to the domain is served over a valid certificate today. */
    public function isSecure(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the platform should try to obtain or renew a certificate.
     *
     * Unknown is excluded deliberately: acting on an absence of information is
     * how a working certificate gets replaced by a failed issuance attempt.
     */
    public function needsIssuance(): bool
    {
        return $this === self::None || $this === self::Expired || $this === self::Failed;
    }
}
