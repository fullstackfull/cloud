<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

use Carbon\CarbonImmutable;

/**
 * What the panel says about its own licence.
 *
 * cPanel/WHM, DirectAdmin, CloudLinux and LiteSpeed are commercial products.
 * This DTO records the vendor's answer and nothing else: there is no code path
 * anywhere in this module that bypasses, patches, emulates or extends a
 * licence, and no configuration flag that enables such a thing. An unlicensed
 * node simply takes no accounts.
 *
 * $valid defaults to false, and the default matters. A panel that could not be
 * reached, or that answered with something the adapter could not read, must
 * come back unlicensed rather than licensed — the failure mode of an
 * optimistic default is a fleet that quietly provisions onto nodes whose
 * licence lapsed, and each of those accounts is a customer who paid for
 * hosting that stops serving when the panel's grace period ends.
 *
 * @immutable
 */
final readonly class LicenceStatus
{
    /**
     * @param  string  $product  What is licensed: the panel itself, or an add-on such as
     *                           CloudLinux or LiteSpeed.
     * @param  string|null  $state  The vendor's own word for it, kept verbatim for support.
     * @param  array<string, mixed>  $raw  The panel's own answer, redacted.
     */
    public function __construct(
        public bool $valid,
        public string $product,
        public ?string $state = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?string $detail = null,
        public array $raw = [],
    ) {}

    /**
     * A licence the platform could not confirm.
     *
     * Used when the node cannot be reached or answers unreadably. Named so
     * that the call site reads as the refusal it is, rather than as a
     * constructor call with a false in it that somebody later "fixes".
     */
    public static function unconfirmed(string $product, string $detail): self
    {
        return new self(valid: false, product: $product, state: 'unconfirmed', detail: $detail);
    }

    /**
     * Whether the licence is valid now and stays valid past $days.
     *
     * A licence expiring inside the window is treated as still valid — the
     * node keeps serving — but is what an operator's report is built from.
     * Discovering a lapse at provisioning time means a customer has already
     * paid.
     */
    public function validThrough(int $days): bool
    {
        if (! $this->valid) {
            return false;
        }

        return $this->expiresAt === null || $this->expiresAt->isAfter(CarbonImmutable::now()->addDays($days));
    }
}
