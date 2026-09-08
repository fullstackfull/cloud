<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\DTOs;

use Lynomia\Modules\Domains\Domain\Enums\DomainAvailability;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What a registrar said about one name.
 *
 * The price is here and optional, because a premium answer carries its own
 * price and an ordinary one does not — an ordinary name is priced from the
 * TLD's list, and a provider echoing that back would be a second source of
 * truth for a number the platform already holds.
 *
 * @immutable
 */
final readonly class AvailabilityAnswer
{
    public function __construct(
        public string $name,
        public DomainAvailability $availability,
        /**
         * A per-name price, for premium answers only. The registry's number,
         * before this platform's margin.
         */
        public ?Money $premiumCost = null,
        /**
         * What the provider called this answer, where it gives a handle that
         * has to be presented back with the order. Inventing one is how a
         * premium registration is refused at the last step.
         */
        public ?string $providerReference = null,
    ) {}

    public static function available(string $name): self
    {
        return new self($name, DomainAvailability::Available);
    }

    public static function unavailable(string $name): self
    {
        return new self($name, DomainAvailability::Unavailable);
    }

    /**
     * The registrar did not answer.
     *
     * A separate constructor rather than a flag, so that an adapter swallowing
     * a timeout has to write the word.
     */
    public static function unknown(string $name): self
    {
        return new self($name, DomainAvailability::Unknown);
    }

    public static function premium(string $name, Money $cost, ?string $reference = null): self
    {
        return new self($name, DomainAvailability::Premium, $cost, $reference);
    }
}
