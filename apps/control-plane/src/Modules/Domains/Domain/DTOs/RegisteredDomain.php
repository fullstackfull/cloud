<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\DTOs;

use Carbon\CarbonImmutable;

/**
 * What a registrar says about a name it holds.
 *
 * Returned by a registration, a renewal and an inspection alike, because all
 * three answer the same question: what does the registry believe right now.
 *
 * `expiresAt` is the registry's date and never one this platform computed.
 * A registry that renews from the expiry date and one that renews from today
 * give different answers, and the difference is a year of somebody's domain.
 *
 * @immutable
 */
final readonly class RegisteredDomain
{
    /**
     * @param  list<string>  $nameservers
     */
    public function __construct(
        public string $name,
        public CarbonImmutable $expiresAt,
        public ?string $providerReference = null,
        public array $nameservers = [],
        /**
         * Null where the provider does not expose a lock, which is a third
         * answer to "is this locked" and not the same as "no".
         */
        public ?bool $transferLocked = null,
        public ?CarbonImmutable $registeredAt = null,
    ) {}
}
