<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\DTOs;

/**
 * Everything a registrar needs to take a name, in domain terms.
 *
 * No HTTP, no vendor field names, no provider-specific options bag. An adapter
 * translates this into whatever its API wants; nothing above the adapter knows
 * what that looks like.
 *
 * @immutable
 */
final readonly class RegistrationRequest
{
    /**
     * @param  list<string>  $nameservers  The delegation to file. May be empty,
     *                                     which means the registrar's own
     *                                     parking nameservers — a real choice
     *                                     for somebody buying a name to hold.
     * @param  array<string, ContactDetails>  $contacts  Keyed by role value.
     */
    public function __construct(
        public string $name,
        public int $termYears,
        public array $contacts,
        public array $nameservers = [],
        /**
         * The provider's own handle for a premium quote, where one was given.
         * A registry that quoted a price expects to see the quote back.
         */
        public ?string $priceReference = null,
        /**
         * The platform's key for this attempt, passed to registrars that
         * honour one. It is what makes a redelivered job reach the same
         * registration rather than buying a second year.
         */
        public ?string $idempotencyKey = null,
    ) {}
}
