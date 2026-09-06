<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * A short-lived, server-created session that lets a customer into their panel.
 *
 * The session is brokered rather than the password replayed, and the
 * difference is the point of the whole mechanism: the platform never holds the
 * customer's panel password, so it cannot send it to a browser, cannot log it
 * and cannot lose it. The URL that comes back carries a one-time token which
 * the panel invalidates on use or expiry.
 *
 * The URL is marked sensitive because it IS the credential for its lifetime —
 * anybody holding it is inside the customer's control panel. It must never be
 * logged, never persisted and never put into an exception or an audit record;
 * the audit record says who asked for a session and when, not what the session
 * was.
 *
 * @immutable
 */
final readonly class SsoSession
{
    public function __construct(
        #[SensitiveParameter]
        public string $url,
        public string $username,
        public string $service = 'panel',
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public function hasExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isBefore($now ?? CarbonImmutable::now());
    }

    /**
     * A description safe to put in a log line or an audit trail.
     *
     * Deliberately not __toString(): a value object whose string form is the
     * secret itself is one interpolation away from a leak.
     */
    public function describe(): string
    {
        return sprintf(
            'panel session for %s (%s), expires %s',
            $this->username,
            $this->service,
            $this->expiresAt?->toIso8601String() ?? 'unknown',
        );
    }
}
