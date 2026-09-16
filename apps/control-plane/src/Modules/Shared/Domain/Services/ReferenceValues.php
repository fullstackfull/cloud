<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Services;

/**
 * Which strings are documentation rather than infrastructure.
 *
 * ---------------------------------------------------------------------------
 * Why this exists as its own class
 * ---------------------------------------------------------------------------
 *
 * The reference topology has to carry addresses: a subnet with no CIDR
 * allocates nothing, and a BMC with no address cannot be modelled at all. The
 * addresses it carries are the documentation ranges, which is the right answer
 * for a committed file — they belong to nobody and route nowhere. But an
 * address that is safe to commit is exactly the address that must never end up
 * in production configuration, and nothing in the platform was checking.
 *
 * {@see EndpointPolicy} carried a comment saying that "documentation" ranges
 * were among the reserved ranges PHP's FILTER_FLAG_NO_RES_RANGE refuses. That
 * comment was wrong, and measurably: on PHP 8.4, 192.0.2.10, 198.51.100.10,
 * 203.0.113.10 and 2001:db8::1 are all accepted by that flag, with and without
 * NO_PRIV_RANGE. Every documentation address in this repository's own examples
 * was an acceptable production provider endpoint. The comment is now true
 * because this class makes it true.
 *
 * ---------------------------------------------------------------------------
 * How the classification is done
 * ---------------------------------------------------------------------------
 *
 * Addresses are compared as packed binary against a network and a prefix
 * length, which is the only way that gets 203.0.113.0/24 right without also
 * catching 203.0.1130 or 1203.0.113.9. There is no substring matching anywhere
 * in this file.
 *
 * Hostnames are reduced to their labels and the last label is compared against
 * the TLDs reserved by RFC 2606 and RFC 6761. `example.com` and its siblings
 * are special-cased because RFC 2606 reserves them at the second level, and
 * because `example.com` appears a hundred times in this repository's fixtures.
 * `myexample.com` is not one of them, and the label comparison is what keeps
 * that distinction.
 *
 * ---------------------------------------------------------------------------
 * What this class is NOT
 * ---------------------------------------------------------------------------
 *
 * It is not a global ban. Documentation values are correct in the reference
 * topology, in tests, in fixtures, in the Ansible example inventories and in
 * every document in docs/. This class only answers the question; the callers
 * decide where the answer is disqualifying, and they all ask it about the
 * production activation path.
 */
final readonly class ReferenceValues
{
    /**
     * Ranges an RFC set aside so that documents could contain an address.
     *
     * Derived from what this repository's own examples already use, which is
     * what §37 of the brief asks for, plus RFC 9637's block, which exists for
     * the same purpose and is the one a future example is most likely to reach
     * for. Benchmarking ranges (RFC 2544, RFC 5180) are deliberately absent:
     * they are reserved, but they are not documentation, and one of them
     * appears in this repository as a real network-test address rather than as
     * an example.
     *
     * @var list<array{string, int}>
     */
    private const array DOCUMENTATION_RANGES = [
        ['192.0.2.0', 24],    // RFC 5737 TEST-NET-1
        ['198.51.100.0', 24], // RFC 5737 TEST-NET-2
        ['203.0.113.0', 24],  // RFC 5737 TEST-NET-3
        ['2001:db8::', 32],   // RFC 3849
        ['3fff::', 20],       // RFC 9637
    ];

    /**
     * Top-level domains that are never delegated.
     *
     * RFC 2606 reserves example, invalid, test and localhost precisely so that
     * a document can name a host without naming somebody's host. This
     * repository uses `.example` in the Ansible inventories, `.test` in the
     * development seeder and `.invalid` in Gap 2's negative matrix.
     *
     * @var list<string>
     */
    private const array RESERVED_TLDS = ['example', 'invalid', 'test', 'localhost'];

    /**
     * Second-level names RFC 2606 reserves for the same reason.
     *
     * @var list<string>
     */
    private const array RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org', 'example.edu'];

    /** The reference topology's logical-id scheme and its file marker. */
    private const string REFERENCE_ID = '/^ref-[a-z0-9]+(-[a-z0-9]+)*$/';

    public const string MARKER_KIND = 'lynomia-reference-topology';

    /**
     * Is this literal inside a range an RFC set aside for documents?
     *
     * Returns false for anything that is not an IP literal at all, including a
     * hostname that happens to resolve to one — resolution is
     * {@see EndpointPolicy}'s job and it applies this test to each answer.
     */
    public function isDocumentationAddress(string $candidate): bool
    {
        $packed = @inet_pton(trim($candidate, '[]'));

        if ($packed === false) {
            return false;
        }

        foreach (self::DOCUMENTATION_RANGES as [$network, $bits]) {
            $base = inet_pton($network);

            if ($base === false || strlen($base) !== strlen($packed)) {
                continue;
            }

            if ($this->sharesPrefix($packed, $base, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this name under a TLD or a second-level domain reserved for documents?
     *
     * The comparison is on whole labels. `example.com` is reserved;
     * `notexample.com` and `example.community` are not, and a substring check
     * would get both wrong.
     */
    public function isDocumentationHostname(string $candidate): bool
    {
        $host = strtolower(trim(rtrim(trim($candidate), '.'), '[]'));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);
        $last = $labels[count($labels) - 1];

        if (in_array($last, self::RESERVED_TLDS, strict: true)) {
            return true;
        }

        if (count($labels) < 2) {
            return false;
        }

        $registrable = $labels[count($labels) - 2].'.'.$last;

        return in_array($registrable, self::RESERVED_DOMAINS, strict: true);
    }

    /**
     * Does this string LOOK like a logical id out of the reference topology?
     *
     * Case-insensitive, and that is the right strictness for a guard: the
     * question a production check asks is "might this be a reference value",
     * and `REF-NODE-ALPHA-1-A` is one whatever the shift key was doing. Erring
     * towards refusal costs an operator one rename; erring the other way points
     * production at a model of an estate.
     */
    public function isReferenceIdentifier(string $candidate): bool
    {
        $value = strtolower(trim($candidate));

        return $value === self::MARKER_KIND || preg_match(self::REFERENCE_ID, $value) === 1;
    }

    /**
     * Is this string EXACTLY a well-formed reference logical id?
     *
     * Case-sensitive, and that is the right strictness for the validator: the
     * question the topology file asks is "is this id written the way the scheme
     * says", and `Ref-Node` is not, even though the guard above would rightly
     * treat it with suspicion. The two questions look the same and are not, and
     * one method answering both would have to pick a strictness that is wrong
     * for one of its callers.
     */
    public function isCanonicalReferenceIdentifier(string $candidate): bool
    {
        return preg_match(self::REFERENCE_ID, $candidate) === 1;
    }

    /**
     * Why this endpoint or address may not be production, or null if it may.
     *
     * One entry point so that every caller refuses the same set for the same
     * stated reasons, and so that adding a category of reference value is one
     * edit rather than four. The sentence is written to be shown to an operator:
     * it says what was found and why that is disqualifying, not merely "invalid".
     */
    public function refuseForProduction(string $candidate): ?string
    {
        $host = $this->hostOf($candidate);

        if ($host === null) {
            return null;
        }

        if ($this->isDocumentationAddress($host)) {
            return 'that address is in a range reserved for documentation, so it belongs to nobody and routes nowhere. A production endpoint needs a real address.';
        }

        if ($this->isDocumentationHostname($host)) {
            return 'that name is under a domain reserved for examples and is never delegated, so nothing will ever answer it in production.';
        }

        if ($this->isReferenceIdentifier($host) || $this->isReferenceIdentifier($candidate)) {
            return 'that is an identifier out of the reference topology, which is a model of an estate rather than an estate.';
        }

        return null;
    }

    /**
     * The host inside a provider endpoint or a bare machine address.
     *
     * A URL is parsed as one; anything else is treated as a host with an
     * optional port, splitting on the last colon only when there is exactly one
     * — more than one means a bare IPv6 literal, where every colon belongs to
     * the address. Returns null when there is no host to judge, which leaves the
     * decision to the caller that knows what shape it expected.
     */
    private function hostOf(string $candidate): ?string
    {
        $value = trim($candidate);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? $host : null;
        }

        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::\d{1,5})?$/', $value, $match) === 1) {
            return $match[1];
        }

        if (substr_count($value, ':') === 1) {
            return explode(':', $value, 2)[0];
        }

        return $value;
    }

    /**
     * Do two packed addresses agree on their first $bits bits?
     *
     * Whole bytes are compared with a substring, and the partial byte with a
     * mask built from the remaining bits. A /20 is two and a half bytes, and
     * comparing two bytes would accept 3f00:: as being inside 3fff::/20.
     */
    private function sharesPrefix(string $packed, string $base, int $bits): bool
    {
        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && substr($packed, 0, $wholeBytes) !== substr($base, 0, $wholeBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($base[$wholeBytes]) & $mask);
    }
}
