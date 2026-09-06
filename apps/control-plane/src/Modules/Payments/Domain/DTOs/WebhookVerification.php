<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\DTOs;

/**
 * The outcome of checking an inbound webhook's signature.
 *
 * A result object rather than a bool so the reason for a rejection can be
 * logged without the caller having to catch a provider-specific exception, and
 * so that the scheme that accepted it is recorded on the stored event —
 * when a signing secret is rotated, that column is what tells an operator
 * which key each event was accepted under.
 *
 * There is no way to construct a verified instance carrying a rejection
 * reason: the two named constructors are the only entry points.
 *
 * @immutable
 */
final readonly class WebhookVerification
{
    private function __construct(
        public bool $verified,
        public ?string $verifiedBy = null,
        public ?string $reason = null,
    ) {}

    /**
     * @param  string  $scheme  Identifies the signature scheme and key that accepted the payload,
     *                          e.g. "stripe.v1".
     */
    public static function verified(string $scheme): self
    {
        return new self(true, verifiedBy: $scheme);
    }

    public static function failed(string $reason): self
    {
        return new self(false, reason: $reason);
    }
}
