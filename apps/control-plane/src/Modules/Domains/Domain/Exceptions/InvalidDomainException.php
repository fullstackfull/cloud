<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A string that is not a name anybody could register.
 *
 * Separate from a refusal about a name that is perfectly valid and simply not
 * for sale here: this one is about the shape of what was typed.
 */
final class InvalidDomainException extends DomainException
{
    /** Never `$code`: Exception already declares an untyped one, and typing it is fatal at class load. */
    private string $errorCode = 'domain.invalid_name';

    public static function becauseItIsEmpty(): self
    {
        return (new self('Type a domain name.'))->as('domain.name_empty');
    }

    public static function becauseItIsTooLong(string $name): self
    {
        return (new self('That name is longer than a registry will accept.'))
            ->as('domain.name_too_long')
            ->withContext(['length' => strlen($name)]);
    }

    public static function becauseItIsNotAscii(string $name): self
    {
        return (new self('Domains with non-English characters are not sold here yet.'))
            ->as('domain.name_not_ascii')
            ->withContext(['length' => strlen($name)]);
    }

    public static function becauseItHasNoTld(string $name): self
    {
        return (new self('Include the ending, like example.com.'))
            ->as('domain.name_has_no_tld')
            ->withContext(['length' => strlen($name)]);
    }

    public static function becauseTheTldIsUnknown(string $name): self
    {
        return (new self('That ending is not one this platform sells.'))
            ->as('domain.tld_unknown')
            ->withContext(['length' => strlen($name)]);
    }

    public static function becauseItIsNotASecondLevelName(string $name): self
    {
        return (new self('Buy the domain itself, like example.com, not a name inside it.'))
            ->as('domain.name_not_second_level')
            ->withContext(['length' => strlen($name)]);
    }

    public static function becauseTheLabelIsMalformed(string $name): self
    {
        return (new self('A domain may use letters, numbers and hyphens, and may not begin or end with one.'))
            ->as('domain.name_malformed')
            ->withContext(['length' => strlen($name)]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }
}
