<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\DTOs;

/**
 * A person or organisation, as a registry wants them.
 *
 * Carries personal data. Nothing that handles one of these may log it, put it
 * in a metric label, or write it into an audit context — see the contacts
 * table for what the same rule means at rest.
 *
 * @immutable
 */
final readonly class ContactDetails
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $addressLineOne,
        public string $city,
        public string $country,
        public ?string $organisation = null,
        public ?string $addressLineTwo = null,
        public ?string $region = null,
        public ?string $postalCode = null,
    ) {}
}
