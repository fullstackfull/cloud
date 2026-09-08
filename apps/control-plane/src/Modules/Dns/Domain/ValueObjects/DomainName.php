<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\ValueObjects;

use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;

/**
 * A domain name the platform is prepared to write into a zone.
 *
 * Stricter than the RFCs, deliberately. The rules below refuse things a
 * resolver would technically accept, because every one of them is either a way
 * of writing one name that looks like another or a value a provider will
 * silently mangle:
 *
 *  - **ASCII only.** An internationalised name has to arrive already in
 *    punycode. Converting one here would mean this platform deciding what
 *    `münchen.de` means, and IDN homograph attacks are exactly a disagreement
 *    about that. `idn_to_ascii` is also an optional PHP extension, so a name
 *    would convert on one deployment and be refused on another.
 *  - **No trailing dot, no empty labels.** `example..com` and `example.com.`
 *    are spellings, not names, and storing spellings is how two rows end up
 *    meaning the same thing.
 *  - **No leading or trailing hyphen in a label**, and no all-numeric final
 *    label — the second because `1.2.3.4` is an address and a zone called
 *    `1.2.3.4` is somebody's mistake.
 */
final readonly class DomainName
{
    private const int MAX_LENGTH = 253;

    private const int MAX_LABEL = 63;

    private function __construct(
        private string $value,
    ) {}

    /**
     * @throws InvalidDomainNameException
     */
    public static function fromString(string $value, bool $allowWildcard = false): self
    {
        $name = strtolower(trim($value));
        $name = rtrim($name, '.');

        if ($name === '') {
            throw InvalidDomainNameException::malformed($value, 'it is empty');
        }

        if (strlen($name) > self::MAX_LENGTH) {
            throw InvalidDomainNameException::malformed($value, 'it is longer than 253 characters');
        }

        $labels = explode('.', $name);

        if (count($labels) < 2) {
            throw InvalidDomainNameException::malformed($value, 'it has only one label');
        }

        foreach ($labels as $index => $label) {
            /*
             * A wildcard is one whole label, only the first, and only where the
             * caller says a wildcard makes sense. `*.example.com` is a record
             * name; `*.example.com` as a *zone* is not a thing that can be
             * delegated, and `a.*.example.com` is not a wildcard at all — it is
             * a literal asterisk label that no resolver will match.
             */
            if ($label === '*') {
                if (! $allowWildcard || $index !== 0) {
                    throw InvalidDomainNameException::malformed($value, 'a wildcard may only be the first label');
                }

                continue;
            }

            self::assertLabel($value, $label);
        }

        $last = $labels[count($labels) - 1];

        if (ctype_digit($last)) {
            throw InvalidDomainNameException::malformed($value, 'its last label is a number');
        }

        return new self($name);
    }

    private static function assertLabel(string $original, string $label): void
    {
        if ($label === '') {
            throw InvalidDomainNameException::malformed($original, 'it has an empty label');
        }

        if (strlen($label) > self::MAX_LABEL) {
            throw InvalidDomainNameException::malformed($original, 'one of its labels is longer than 63 characters');
        }

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
            throw InvalidDomainNameException::malformed(
                $original,
                'labels may hold only letters, digits and hyphens, and may not begin or end with one',
            );
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The name without its first label.
     *
     * Used to find the zone a record belongs in, and to answer "is this the
     * apex" without string arithmetic at the call site.
     */
    public function parent(): ?self
    {
        $labels = explode('.', $this->value);

        if (count($labels) < 3) {
            return null;
        }

        return new self(implode('.', array_slice($labels, 1)));
    }

    /**
     * Whether this name is inside a zone, or is the zone itself.
     *
     * Suffix matching on a label boundary. `notexample.com` ends with
     * `example.com` and belongs to somebody else.
     */
    public function isWithin(self $zone): bool
    {
        return $this->value === $zone->value || str_ends_with($this->value, '.'.$zone->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
