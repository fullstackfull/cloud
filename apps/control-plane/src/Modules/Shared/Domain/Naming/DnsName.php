<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Naming;

use Lynomia\Modules\Infrastructure\Domain\Naming\InfrastructureNamingPolicy;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;
use Stringable;

/**
 * A DNS host name, parsed once, for everything in this platform that holds one.
 *
 * ===========================================================================
 * WHY THIS IS ONE CLASS AND NOT A REGEX PER CALLER
 * ===========================================================================
 *
 * A hostname is not a URL, not an authority, and not an identifier. It is a
 * sequence of labels with rules that RFC 1035 fixed and nothing since has
 * relaxed: at most 63 octets a label, at most 253 for the name, alphanumeric
 * ends, hyphens inside, nothing else. Every place in a codebase that checks
 * "is this a hostname" with `str_contains('.')` or `endsWith('.internal')`
 * accepts a different set of strings, and the platform then learns which one
 * was wrong from a provider's rejection hours later.
 *
 * So the syntax lives here and the policy lives in the callers, because those
 * are genuinely different questions:
 *
 *  - Is this a well-formed DNS name?               — this class.
 *  - May a customer publish it as a PTR target?    — {@see Hostname}
 *  - Is it a name this infrastructure may carry?   — {@see InfrastructureNamingPolicy}
 *  - Is it safe to send a request to?              — {@see EndpointPolicy}
 *
 * ===========================================================================
 * WHAT IT REFUSES THAT LOOKS LIKE A HOSTNAME
 * ===========================================================================
 *
 * `host:8443`, `https://host`, `user@host`, `host/path`, `[2001:db8::1]`,
 * `192.0.2.10`. Each of those is a thing this platform also stores, in its own
 * field, with its own meaning. A hostname field that accepts an authority is
 * how a port ends up inside a name and then inside a certificate request, and
 * Gap 2 found that bug in the reachability testers. The refusal says which of
 * those it looks like, so the fix is obvious from the message.
 *
 * ===========================================================================
 * CANONICAL FORM
 * ===========================================================================
 *
 * Lower case, with one trailing root dot removed. DNS is case-insensitive, so
 * `PVE-01.Example` and `pve-01.example.` are one name; storing both would be
 * two rows the platform believes are different machines. Non-ASCII is refused
 * rather than transliterated: an internationalised name has exactly one
 * representation in DNS — its `xn--` A-label — and accepting the U-label as
 * well would mean two spellings of one name, one of which no resolver returns.
 *
 * @immutable
 */
final readonly class DnsName implements Stringable
{
    /** RFC 1035's ceiling on a name, in octets, without the root dot. */
    public const int MAX_LENGTH = 253;

    /** RFC 1035's ceiling on one label. */
    public const int MAX_LABEL_LENGTH = 63;

    /**
     * One label: alphanumeric ends, hyphens allowed inside, nothing else.
     *
     * Underscores are excluded deliberately. They are legal in a DNS name —
     * `_acme-challenge` is one — and not in a *host* name, and every caller of
     * this class is naming a host.
     */
    private const string LABEL = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

    /**
     * @param  list<string>  $labels
     */
    private function __construct(
        private string $name,
        private array $labels,
    ) {}

    /**
     * Is this one well-formed host label — no dots, no dashes at the ends?
     *
     * Exposed because a label is a thing the platform handles on its own: the
     * left-hand side an operator types before a suffix is composed onto it.
     */
    public static function isLabel(string $label): bool
    {
        return preg_match(self::LABEL, $label) === 1 && strlen($label) <= self::MAX_LABEL_LENGTH;
    }

    /**
     * The name as it should be stored, or the untouched input if it is not one.
     *
     * Canonicalising an invalid string would hide the thing that is wrong with
     * it, so this only lower-cases and drops one trailing root dot; whether
     * what is left is a name at all is {@see self::problemWith()}'s answer.
     */
    public static function canonical(string $candidate): string
    {
        $value = strtolower(trim($candidate));

        // One trailing dot is a legitimate way to write a fully qualified name
        // and is dropped rather than refused. Two leave an empty label, which
        // the parse below refuses, and that is the correct answer for `host..`.
        if (str_ends_with($value, '.') && ! str_ends_with($value, '..')) {
            $value = substr($value, 0, -1);
        }

        return $value;
    }

    /**
     * The name a person typed, folded the way a submitted domain is stored.
     *
     * Wider than {@see self::canonical()} at the edges, because a domain
     * pasted into a form arrives with whatever surrounded it: every leading
     * and trailing space, tab, newline, NUL and dot is dropped, in any
     * interleaving, where `canonical()` drops whitespace and one root dot.
     * Composed from `canonical()` rather than written beside it, so the
     * platform keeps one lower-casing rule.
     *
     * It is byte-for-byte `strtolower(trim($name, " \t\n\r\0\x0B."))`, the
     * expression it replaced, and that equivalence is pinned rather than
     * argued: this value feeds `orders.request_fingerprint`, so a fold that
     * moved would move the fingerprint of every basket a client is mid-way
     * through retrying and answer each of them 409.
     *
     * It is the fold behind `hosting_accounts.primary_domain`'s CHECK
     * constraint, which computes `lower(btrim(…))` in the database. The two
     * agree for ASCII and only for ASCII — PHP's `strtolower` is byte-wise
     * and PostgreSQL's `lower()` follows the locale — so a caller folds a
     * name only after {@see self::problemWith()} has accepted it, and that
     * refuses anything outside ASCII.
     */
    public static function canonicalAsSubmitted(string $candidate): string
    {
        return self::canonical(trim($candidate, " \t\n\r\0\x0B."));
    }

    /**
     * Why this string is not a DNS host name, or null if it is one.
     *
     * A sentence rather than a boolean, and a sentence naming the specific
     * label where that helps: "it is longer than 253 characters" and "the label
     * \"a-very-long…\" is longer than 63 characters" send somebody to different
     * parts of the same string.
     */
    public static function problemWith(string $candidate): ?string
    {
        $raw = trim($candidate);

        if ($raw === '') {
            return 'it is empty';
        }

        // Checked before anything else, because each of these is a different
        // field in this platform and the message should say so. A hostname
        // field holding an authority is the bug, not a formatting slip.
        if (str_contains($raw, '://')) {
            return 'it is a URL rather than a hostname — store the scheme and path on the endpoint, not on the name';
        }

        if (str_contains($raw, '@')) {
            return 'it carries user information — a hostname is the host alone';
        }

        if (str_contains($raw, '/')) {
            return 'it carries a path — a hostname is the host alone';
        }

        if (str_starts_with($raw, '[') || str_contains($raw, ']')) {
            return 'it is a bracketed IP literal rather than a name';
        }

        if (preg_match('/\s/', $raw) === 1) {
            return 'it contains whitespace';
        }

        if (filter_var($raw, FILTER_VALIDATE_IP) !== false) {
            return 'it is an IP address rather than a name';
        }

        if (str_contains($raw, ':')) {
            return 'it carries a port — a hostname and the port it is reached on are separate fields';
        }

        $value = self::canonical($raw);

        if (strlen($value) > self::MAX_LENGTH) {
            return sprintf('it is longer than %d characters', self::MAX_LENGTH);
        }

        // After canonicalisation, because `Café.example` lower-cases to
        // something still outside ASCII and the message should be about that
        // rather than about a label the reader cannot see the problem with.
        if (preg_match('/\A[\x20-\x7E]*\z/', $value) !== 1) {
            return 'it is not ASCII — an internationalised name is stored as its xn-- form';
        }

        foreach (explode('.', $value) as $label) {
            if ($label === '') {
                return 'it contains an empty label';
            }

            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                return sprintf('the label "%s" is longer than %d characters', $label, self::MAX_LABEL_LENGTH);
            }

            if (preg_match(self::LABEL, $label) !== 1) {
                return sprintf('the label "%s" is not a valid hostname label', $label);
            }
        }

        return null;
    }

    /** The parsed name, or null when it is not one. */
    public static function tryFrom(string $candidate): ?self
    {
        if (self::problemWith($candidate) !== null) {
            return null;
        }

        $value = self::canonical($candidate);

        /** @var list<string> $labels */
        $labels = explode('.', $value);

        return new self($value, $labels);
    }

    public function value(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return $this->labels;
    }

    /**
     * Does this name carry a domain, or is it a bare host label?
     *
     * Both are legitimate and mean different things: `pve-01` is a label that
     * needs a suffix before a resolver can answer for it, and
     * `pve-01.dc1.example` is a name that stands on its own. Which one a field
     * requires is the field's policy, not this class's.
     */
    public function isFullyQualified(): bool
    {
        return count($this->labels) > 1;
    }

    /** Case-insensitive and trailing-dot-insensitive, as DNS requires. */
    public function equals(self|string $other): bool
    {
        $value = $other instanceof self ? $other->name : self::canonical($other);

        return $this->name === $value;
    }

    /**
     * Is this name inside that zone?
     *
     * Label-wise. `notexample.com` is not under `example.com`, and a
     * `str_ends_with` would say it is — the same mistake
     * {@see ReferenceValues} refuses to
     * make about reserved domains.
     */
    public function isUnder(self|string $suffix): bool
    {
        $zone = $suffix instanceof self ? $suffix->name : self::canonical($suffix);

        if ($zone === '' || $this->name === $zone) {
            return false;
        }

        return str_ends_with($this->name, '.'.$zone);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
