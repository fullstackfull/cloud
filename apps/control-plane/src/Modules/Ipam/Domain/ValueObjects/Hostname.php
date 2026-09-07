<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\ValueObjects;

use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidHostnameException;
use Stringable;

/**
 * A hostname that is safe to publish as the target of a PTR record.
 *
 * This type exists because the string it wraps is the one piece of the reverse
 * DNS surface that a customer writes and the platform then sends to a third
 * party. Between those two facts sit every mistake this class refuses:
 *
 *  - **Control characters.** A DNS API is spoken over HTTP, and a zone file is
 *    line-oriented. A CR or an LF inside a value is how a single field becomes
 *    two — a second header on the way out, or a second record on the way in.
 *    Nothing outside the character class below survives, so there is no
 *    escaping to get right at the adapter.
 *  - **Anything that is not a hostname.** `*.example.com`, `example.com/../`,
 *    `192.0.2.10`, `-example.com`, `exa mple.com`, an empty label from a
 *    double dot, a label over 63 octets, a name over 253. Each of those is
 *    rejected by a different provider in a different way, at a different time,
 *    and the platform learns about it as a failed record hours later.
 *  - **Non-ASCII.** An internationalised name has exactly one representation
 *    in DNS, its A-label (`xn--`), and accepting the U-label here would mean
 *    two spellings of one name — one of which no resolver ever returns.
 *
 * The canonical form is lower case with any trailing root dot removed, so
 * "Mail.Example.COM." and "mail.example.com" are one hostname rather than two
 * records that disagree.
 *
 * What this class deliberately does NOT decide is whether the customer is
 * entitled to the name. Forward confirmation — that `mail.example.com` resolves
 * back to the address the PTR is being set on — needs a resolver, cannot be
 * done inside a request without hanging it, and is the DNS provider's or an
 * operator's job. It is stated here so the omission is a decision on the record
 * rather than an oversight.
 *
 * @immutable
 */
final readonly class Hostname implements Stringable
{
    /** The RFC 1035 ceiling on a name, in octets, without the root dot. */
    public const int MAX_LENGTH = 253;

    /** The RFC 1035 ceiling on one label. */
    public const int MAX_LABEL_LENGTH = 63;

    /**
     * One label: alphanumeric ends, hyphens allowed inside, nothing else.
     * Underscores are excluded on purpose — they are legal in a DNS name but
     * not in a *host* name, and a PTR whose target is not a hostname is
     * rejected by the mail servers that are the main reason to set one.
     */
    private const string LABEL = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

    /**
     * The final label. Letters, or an IDN A-label, and never all digits: a
     * numeric last label is how "192.0.2.10" would otherwise pass as a name.
     */
    private const string TLD = '/\A(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})\z/';

    private function __construct(
        private string $hostname,
    ) {}

    /**
     * @throws InvalidHostnameException
     */
    public static function fromString(string $hostname): self
    {
        $candidate = strtolower(trim($hostname));

        // One trailing root dot is a legitimate way to write a fully qualified
        // name and is dropped rather than refused; two is not.
        if (str_ends_with($candidate, '.')) {
            $candidate = substr($candidate, 0, -1);
        }

        if ($candidate === '') {
            throw InvalidHostnameException::because($hostname, 'it is empty');
        }

        if (strlen($candidate) > self::MAX_LENGTH) {
            throw InvalidHostnameException::because(
                $hostname,
                sprintf('it is longer than %d characters', self::MAX_LENGTH),
            );
        }

        // Checked before the labels are examined: an address is a well-formed
        // set of labels by the letter of the rules below, and "set the PTR to
        // an IP address" is a common enough mistake to deserve its own answer.
        if (IpAddressValue::isValid($candidate)) {
            throw InvalidHostnameException::because($hostname, 'it is an IP address rather than a name');
        }

        $labels = explode('.', $candidate);

        if (count($labels) < 2) {
            throw InvalidHostnameException::because(
                $hostname,
                'it is not fully qualified — a PTR target needs at least a name and a domain',
            );
        }

        foreach ($labels as $label) {
            if ($label === '') {
                throw InvalidHostnameException::because($hostname, 'it contains an empty label');
            }

            if (strlen($label) > self::MAX_LABEL_LENGTH) {
                throw InvalidHostnameException::because(
                    $hostname,
                    sprintf('the label "%s" is longer than %d characters', $label, self::MAX_LABEL_LENGTH),
                );
            }

            if (preg_match(self::LABEL, $label) !== 1) {
                throw InvalidHostnameException::because(
                    $hostname,
                    sprintf('the label "%s" is not a valid hostname label', $label),
                );
            }
        }

        if (preg_match(self::TLD, $labels[count($labels) - 1]) !== 1) {
            throw InvalidHostnameException::because($hostname, 'its last label is not a valid domain suffix');
        }

        return new self($candidate);
    }

    /**
     * Whether the string would be accepted, without raising.
     *
     * The form-request rule uses this so a customer gets a 422 naming the
     * field; the action calls fromString() so a caller that is not an HTTP
     * request gets the same refusal. Both go through the same rules.
     */
    public static function isValid(string $hostname): bool
    {
        try {
            self::fromString($hostname);

            return true;
        } catch (InvalidHostnameException) {
            return false;
        }
    }

    public function value(): string
    {
        return $this->hostname;
    }

    public function __toString(): string
    {
        return $this->hostname;
    }
}
