<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\ValueObjects;

use Lynomia\Modules\Domains\Domain\Exceptions\InvalidDomainException;

/**
 * A name that could be registered, split into the part being bought and the
 * namespace it is bought from.
 *
 * ---------------------------------------------------------------------------
 * Why this is not the DNS module's DomainName
 * ---------------------------------------------------------------------------
 *
 * They answer different questions and accept different strings. `DomainName`
 * asks whether something can be served — so `*.example.com` is fine, and
 * `a.b.example.com` is fine, because both are things a zone can hold. This
 * asks whether something can be *bought*, and neither of those can: a
 * registrar sells `example.com`, not a wildcard and not a subdomain.
 *
 * Sharing one class would mean one of the two questions being answered wrong,
 * and the wrong answer here is a customer paying for a name no registry will
 * sell them.
 *
 * ---------------------------------------------------------------------------
 * How the split is decided
 * ---------------------------------------------------------------------------
 *
 * By asking the catalogue, not by counting dots. `example.co.uk` is a
 * second-level registration under `co.uk` and `example.com` is one under
 * `com`; a rule that took everything after the last dot would try to sell
 * `uk`, and one that took the last two labels would refuse `.com` outright.
 * The caller passes the TLDs this platform knows and the longest match wins,
 * which is the same rule the public suffix list encodes and the only one that
 * survives a new namespace being added.
 *
 * @immutable
 */
final readonly class RegistrableDomain
{
    private function __construct(
        /** The whole name, lowercased: `example.com`. */
        public string $name,
        /** The namespace, without a leading dot: `com`, `co.uk`. */
        public string $tld,
        /** What is being bought inside it: `example`. */
        public string $label,
    ) {}

    /**
     * @param  list<string>  $knownTlds  Namespaces this platform sells, without dots.
     *
     * @throws InvalidDomainException
     */
    public static function parse(string $input, array $knownTlds): self
    {
        $name = strtolower(trim($input));

        // A trailing dot is a valid fully-qualified name and not a valid thing
        // to buy; strip it rather than refusing, because it is a typo people
        // who know DNS make.
        $name = rtrim($name, '.');

        if ($name === '') {
            throw InvalidDomainException::becauseItIsEmpty();
        }

        if (strlen($name) > 253) {
            throw InvalidDomainException::becauseItIsTooLong($name);
        }

        /*
         * ASCII only, and deliberately. An internationalised name has to be
         * punycoded before a registry sees it, and a platform that accepted
         * the Unicode form here would be storing one string, sending another,
         * and comparing them for equality somewhere. Punycode conversion is a
         * product decision with its own homograph questions attached; until it
         * is taken, the honest answer is that this platform does not sell
         * those names.
         */
        if (preg_match('/^[a-z0-9.-]+$/', $name) !== 1) {
            throw InvalidDomainException::becauseItIsNotAscii($name);
        }

        if (! str_contains($name, '.')) {
            throw InvalidDomainException::becauseItHasNoTld($name);
        }

        $tld = self::longestMatchingTld($name, $knownTlds);

        if ($tld === null) {
            throw InvalidDomainException::becauseTheTldIsUnknown($name);
        }

        $label = substr($name, 0, -(strlen($tld) + 1));

        if ($label === '' || str_contains($label, '.')) {
            /*
             * Either nothing in front of the namespace, or several labels.
             * `com` alone is not for sale, and neither is `www.example.com` —
             * that is a record inside a registration somebody else already
             * made.
             */
            throw InvalidDomainException::becauseItIsNotASecondLevelName($name);
        }

        self::assertLabelIsRegistrable($label, $name);

        return new self($name, $tld, $label);
    }

    /**
     * The longest known namespace this name ends in.
     *
     * `example.co.uk` matches both `uk` and `co.uk` when both are sold, and
     * the longer one is the right answer: the registration is under `co.uk`.
     *
     * @param  list<string>  $knownTlds
     */
    private static function longestMatchingTld(string $name, array $knownTlds): ?string
    {
        $best = null;

        foreach ($knownTlds as $candidate) {
            $candidate = strtolower(ltrim($candidate, '.'));

            if ($candidate === '' || ! str_ends_with($name, '.'.$candidate)) {
                continue;
            }

            if ($best === null || strlen($candidate) > strlen($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The rules every registry applies to the label itself.
     *
     * Checked here rather than left to the registrar, because a refusal that
     * arrives after the customer's money has moved is a refund and a support
     * ticket, and these four rules are the ones every registry shares.
     */
    private static function assertLabelIsRegistrable(string $label, string $name): void
    {
        if (strlen($label) > 63) {
            throw InvalidDomainException::becauseItIsTooLong($name);
        }

        if (str_starts_with($label, '-') || str_ends_with($label, '-')) {
            throw InvalidDomainException::becauseTheLabelIsMalformed($name);
        }

        /*
         * `xn--` is the punycode prefix, and two hyphens in the third and
         * fourth position is how a registry recognises one. Refused for the
         * same reason the Unicode form is: this platform does not sell
         * internationalised names yet, and accepting the encoded form would
         * be selling them by the back door.
         */
        if (str_starts_with($label, 'xn--')) {
            throw InvalidDomainException::becauseItIsNotAscii($name);
        }

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
            throw InvalidDomainException::becauseTheLabelIsMalformed($name);
        }
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
