<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\ValueObjects;

use Lynomia\Modules\Dns\Domain\Enums\NoDerivedName;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;

/**
 * The names no account may hold on this platform, and what holding one covers.
 *
 * ---------------------------------------------------------------------------
 * Where the names come from
 * ---------------------------------------------------------------------------
 *
 * Two places, and the second exists because the first ships empty.
 *
 *   - **What an operator listed** — `DNS_RESERVED_ZONES`, as written. Every
 *     entry is kept, including one that is not a name: see "A list that does
 *     not read" below.
 *   - **What the platform already knows it answers on** — the host of each of
 *     its own addresses, keyed by the variable that holds the address. An
 *     operator who has put the control plane on `panel.example.net` has
 *     already said so once, in `APP_URL`, and should not have to say it again
 *     for the reservation to cover it.
 *
 * A host contributes itself and nothing more. Its registrable domain would be
 * the better name to hold, and it cannot be computed without a public-suffix
 * list — `panel.example.co.uk` would otherwise reserve `co.uk`. Holding the
 * host still covers every parent of it, so the registrable domain is refused
 * anyway; what it does not cover is a sibling, which is what
 * `DNS_RESERVED_ZONES` is for.
 *
 * ---------------------------------------------------------------------------
 * The form a host is held in
 * ---------------------------------------------------------------------------
 *
 * The one DNS carries. An operator writes `https://münchen.example.net` the
 * way people read it; a resolver is asked for `xn--mnchen-3ya.example.net`,
 * and that A-label is what an account would type to claim the name. Held in
 * Unicode, the host would be refused by {@see DomainName} and nothing would be
 * held for it at all, while the name it stands for stayed claimable — so a
 * host is percent-decoded and, where it is not ASCII, converted by UTS #46
 * with nontransitional processing, which is what the URL standard specifies.
 * The older transitional processing maps four characters (ß, ς and the two
 * zero-width joiners) elsewhere — `straße` to `strasse` — and the name it
 * produces is a different name, held only if it is listed.
 *
 * This is not the conversion {@see DomainName} declines to make for a name an
 * account claims. That rule exists because a disagreement about what a
 * Unicode name means is how a homograph gets through, and converting a claim
 * decides what is admitted. Converting a reservation only adds to what is
 * refused: at worst it holds a name the platform does not answer on, and it
 * admits nothing that holding nothing would not have admitted. And
 * `idn_to_ascii` is defined on every deployment of this application — by
 * ext-intl where it is loaded, and otherwise by symfony/polyfill-intl-idn,
 * which the framework requires through symfony/mime.
 *
 * ---------------------------------------------------------------------------
 * An address that gives no name
 * ---------------------------------------------------------------------------
 *
 * Unset, not a URL with a host, an IP address, a single label, or a host the
 * name rules refuse even in the form above. What that leaves unheld is
 * different for each — nothing at all, for an IP address; the names beneath
 * it, for a single label; possibly the very name the operator meant, for a
 * host with no scheme in front of it — so the reason is kept, as a
 * {@see NoDerivedName}, and the preflight says it. A single label is not
 * reserved, and neither is anything beneath it: the label is not a name a
 * zone could be claimed for, and holding it would mean relaxing the name rules
 * for the one caller that needs them strictest.
 *
 * ---------------------------------------------------------------------------
 * What a reserved name covers
 * ---------------------------------------------------------------------------
 *
 * Itself, every parent of it, and everything beneath it — all three, compared
 * on label boundaries.
 *
 *   - **Itself**, obviously.
 *   - **A parent**: an account holding `example.net` can serve
 *     `panel.example.net` whatever this platform thinks it owns.
 *   - **A child**: a claim creates a zone in the platform's own provider
 *     account, and every record in it is published from there with the
 *     platform's credentials. A customer zone beneath the platform's name is
 *     a piece of the platform's name space held by somebody else, in the
 *     account the platform publishes from. The platform's names are the ones
 *     every customer follows, which is why this is refused beneath them and
 *     is not a rule between one customer's zone and another's.
 *
 * ---------------------------------------------------------------------------
 * A list that does not read
 * ---------------------------------------------------------------------------
 *
 * {@see self::all()} parses every configured entry before anything is compared
 * with it, and throws on the first that is not a name. Skipping a bad entry
 * would protect less than the operator asked for and say nothing; refusing
 * every claim until it is corrected protects exactly what was asked for and is
 * impossible to miss. The estate preflight reports this state as its one
 * failure, and counts the entries without quoting them.
 */
final readonly class ReservedZones
{
    /** @var list<string> */
    private array $configured;

    /**
     * @param  list<string>  $configured  `DNS_RESERVED_ZONES`, one entry per name as written.
     * @param  array<string, string|null>  $platformUrls  The platform's own addresses, keyed by the
     *                                                    variable that holds each one.
     */
    public function __construct(
        array $configured,
        private array $platformUrls,
    ) {
        $this->configured = array_values(array_filter(
            array_map(static fn (string $entry): string => trim($entry), $configured),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * What an operator listed, trimmed, with empty entries dropped.
     *
     * An empty entry is a spelling — a trailing comma, or two in a row — and
     * not a malformed name.
     *
     * @return list<string>
     */
    public function configured(): array
    {
        return $this->configured;
    }

    /**
     * How many listed entries are not names. Never which.
     */
    public function malformed(): int
    {
        $count = 0;

        foreach ($this->configured as $entry) {
            try {
                DomainName::fromString($entry);
            } catch (InvalidDomainNameException) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Each of the platform's own addresses, and the name it contributes.
     *
     * Null for an address that contributed nothing; {@see self::underived()}
     * says why. Kept rather than dropped, because "this variable was consulted
     * and gave nothing" is itself worth reporting.
     *
     * @return array<string, string|null>
     */
    public function derivedByVariable(): array
    {
        $derived = [];

        foreach ($this->platformUrls as $variable => $url) {
            $name = self::derive($url);
            $derived[$variable] = is_string($name) ? $name : null;
        }

        return $derived;
    }

    /**
     * Each of the platform's own addresses that contributed nothing, and why.
     *
     * @return array<string, NoDerivedName>
     */
    public function underived(): array
    {
        $underived = [];

        foreach ($this->platformUrls as $variable => $url) {
            $name = self::derive($url);

            if ($name instanceof NoDerivedName) {
                $underived[$variable] = $name;
            }
        }

        return $underived;
    }

    /**
     * The names the platform's own addresses contribute, keyed by variable.
     *
     * @return array<string, string>
     */
    public function derived(): array
    {
        return array_filter($this->derivedByVariable());
    }

    /**
     * Every reserved name: what was listed, then what was derived.
     *
     * @return list<DomainName>
     *
     * @throws InvalidDomainNameException when a listed entry is not a name — see the class docblock
     */
    public function all(): array
    {
        $names = [];

        foreach ($this->configured as $entry) {
            $names[] = DomainName::fromString($entry);
        }

        foreach ($this->derived() as $name) {
            $names[] = DomainName::fromString($name);
        }

        return $names;
    }

    /**
     * Is this name, a parent of it, or anything beneath it reserved?
     *
     * @throws InvalidDomainNameException when a listed entry is not a name — see the class docblock
     */
    public function protects(DomainName $name): bool
    {
        foreach ($this->all() as $held) {
            /*
             * `isWithin` is inclusive, so the two calls between them answer
             * all three questions: the name itself, a parent of the reserved
             * name (the reserved name is within the claim), and a child of it
             * (the claim is within the reserved name).
             */
            if ($held->isWithin($name) || $name->isWithin($held)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name an address contributes, or why it contributes none.
     */
    private static function derive(?string $url): string|NoDerivedName
    {
        if ($url === null || trim($url) === '') {
            return NoDerivedName::Unset;
        }

        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || trim($host, '.') === '') {
            return NoDerivedName::NotAUrl;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')
            && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return NoDerivedName::IpAddress;
        }

        $host = self::asDnsCarriesIt($host);

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return NoDerivedName::IpAddress;
        }

        try {
            return DomainName::fromString($host)->value();
        } catch (InvalidDomainNameException) {
            return str_contains(rtrim($host, '.'), '.') ? NoDerivedName::NotADomainName : NoDerivedName::SingleLabel;
        }
    }

    /**
     * A URL's host in the form a resolver is asked for — see "The form a host
     * is held in" above. An ASCII host is left as it is, and a host that does
     * not convert is left in Unicode, where the name rules refuse it.
     */
    private static function asDnsCarriesIt(string $host): string
    {
        $host = rawurldecode($host);

        if (preg_match('/[^\x00-\x7F]/', $host) !== 1) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) ? $ascii : $host;
    }
}
