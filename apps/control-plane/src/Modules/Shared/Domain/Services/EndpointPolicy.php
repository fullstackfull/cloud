<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Services;

use Lynomia\Modules\Shared\Domain\Contracts\HostResolver;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;

/**
 * Where this control plane may open a connection, decided before any
 * connection is opened.
 *
 * The control plane sits on a management network with a route to every
 * machine in the estate, a cloud metadata service on some hosts, and its own
 * loopback services. A provider endpoint or a machine address is therefore
 * the most valuable string an operator can type: pointed at the wrong place,
 * a "connection test" becomes a request to the metadata service with a
 * credential attached. This is the one place that says no.
 *
 *   - Loopback, link-local, unspecified, multicast, the cloud metadata
 *     addresses and the IANA special-purpose blocks no machine of ours lives
 *     in are refused everywhere, by literal and by what a hostname resolves
 *     to.
 *   - A provider that is somebody else's service (DNS, registrar, payment,
 *     email) is refused a private-range address: it is not on the
 *     management network and a private address for it is a mistake or an
 *     attack. A provider on our own hardware (Proxmox, PBS, a panel, a BMC)
 *     may be private, because that is where it is.
 *   - Real drivers speak HTTPS. A controlled driver speaks fake://, which no
 *     socket is ever opened for.
 *   - No userinfo in a URL: a credential lives in the credential centre, not
 *     in an endpoint string that is logged and shown.
 *   - In production, and only in production, a value out of the reference
 *     estate: an address from a range an RFC set aside for documents, a name
 *     under a domain that is never delegated, or a reference logical id. Those
 *     values are correct in the reference topology, in the example inventories
 *     and in every fixture; a production installation dialling one is a
 *     production installation pointed at a model of an estate.
 *
 * ---------------------------------------------------------------------------
 * An address is judged, not a spelling of one (F-29)
 * ---------------------------------------------------------------------------
 *
 * This class used to compare strings. It refused `127.0.0.1` as written and
 * handed anything it did not recognise as a literal to the system resolver,
 * accepting whatever came back — and accepting outright when nothing came
 * back. Every one of these reached a socket that way:
 *
 *   - IPv4 numbers other than four decimal parts. `inet_aton` reads
 *     `0x7f000001`, `2130706433`, `0177.0.0.1` and `127.1` as `127.0.0.1`,
 *     and whether a given C library did was the only thing between those
 *     strings and loopback. A host whose last label is a number — decimal,
 *     octal or `0x` hex, the rule a URL parser uses to decide that a host is
 *     an IPv4 address — is now accepted only as four decimal parts with no
 *     leading zeros, and refused otherwise, before anything resolves it.
 *   - IPv4 inside IPv6, and the transition prefixes that carry IPv4 by
 *     design: IPv4-compatible, IPv4-mapped, SIIT, NAT64, 6to4, Teredo. The
 *     covering blocks are refused whole — `::/8`, `2001::/23`, `2002::/16` —
 *     rather than enumerated inside, because an enumeration inside a block
 *     is a list waiting for the member it lacks.
 *   - IANA special-purpose blocks PHP's filter flags do not know about.
 *   - Zone identifiers (`fe80::1%eth0`), which name an interface on this
 *     host.
 *   - The root label (`169.254.169.254.`) and the separators UTS-46 maps to
 *     a dot (`。`, `．`, `｡`), which spell a refused name so that every
 *     comparison by name misses it. A trailing dot is refused; anything that
 *     is not ASCII is refused, because an internationalised name arrives as
 *     punycode.
 *   - IPv6 literals written in any form other than the compressed lower-case
 *     one they used to be compared in. Addresses are now compared as packed
 *     bytes.
 *
 * And three roads remediation found to the same place:
 *
 *   - `parse_url` rewrites control characters in a host into `_`, so the
 *     provider road judged `169.254.169.254_` — on no list, not a literal, not
 *     a number — while the stored endpoint kept the bytes that reach the
 *     socket. The host must now be written exactly where the URL says it is,
 *     and a host must be written with letters, digits, dots, hyphens,
 *     underscores and IPv6 colons; the second rule is needed as well as the
 *     first because space and backslash are carried into the host verbatim.
 *   - A name with only an AAAA record resolved to nothing, because the
 *     resolver asked for A records only.
 *   - A name that resolved to nothing ran the address loop zero times and was
 *     accepted, which made every refusal about a name conditional on the
 *     resolver having answered.
 *
 * ---------------------------------------------------------------------------
 * Resolution, and what it costs
 * ---------------------------------------------------------------------------
 *
 * Names are resolved through {@see HostResolver}, both address families, and
 * every answer is judged: one bad answer among good ones is a refusal. A name
 * the resolver has no address for is refused, not accepted. That has an
 * operational cost, and docs/security.md ("Where the platform may open a
 * connection") is where it is written for whoever runs the platform: a name
 * must resolve before it can be registered, and where a hostname is checked
 * at use a resolver that is briefly down turns a use into a refusal. Register
 * the address itself where that matters.
 *
 * ---------------------------------------------------------------------------
 * Where it is asked
 * ---------------------------------------------------------------------------
 *
 * At registration, on the Control Center's roads that write a value the
 * platform will dial: providers, compute clusters, hosting nodes (the API
 * endpoint, or the hostname when the node has no API endpoint and the
 * hostname is what gets dialled), managed servers and BMC endpoints — the
 * create road, and the edit road for the clusters and hosting nodes that have
 * one. Rows also arrive by roads this class never sees: the reference
 * topology loader, seeders, imports and SQL.
 *
 * Again at use on these roads: a provider's connection test and a managed
 * server's probe (the probe service builds its target through this class), and
 * a dedicated server's BMC, which the dedicated provider factory asks about
 * before it builds a connection — so a BMC row that arrived by a road this
 * did not guard is refused before a socket opens.
 *
 * NOT again at use on these, which build a connection from the stored row
 * without asking: `WhmConnection::forNode`, `DirectAdminConnection::forNode`,
 * `ComputeProviderFactory::proxmox`, `BackupProviderFactory`, and the console
 * gateway's `ConsoleUpstream::socketAddress`. A row placed on those tables by a
 * seeder, an import or direct SQL is dialled as it stands. They are named here
 * individually rather than counted, so that adding a check to one of them
 * means deleting its name, and so that a road added later is visibly absent
 * from both lists until somebody decides which it belongs in.
 *
 * ---------------------------------------------------------------------------
 * The anchors
 * ---------------------------------------------------------------------------
 *
 * Every pattern here that must match a whole string ends in `\z`, not `$`:
 * PCRE's `$` also matches before a final newline, so `/^\d{1,5}$/` accepts
 * `8006\n`. The patterns that carry no end anchor at all are the
 * character-class refusals in `canonicalHost`, and they carry none because
 * each asks whether a character is present anywhere in the host, which has no
 * end to anchor to.
 *
 * Which anchors a test can tell apart from `$` is derived, not written: the
 * partition row in EverySpellingOfARefusedAddressIsStillThatAddressTest
 * rebuilds this class with each anchor turned into `$` and records which row
 * notices. How many there are of each kind is deliberately not written down
 * here. What that row reads, and what it cannot see, is in its docblock, and
 * the guards it runs are why this file concatenates nothing, passes every
 * pattern to PCRE as one literal or one constant, imports no function, and
 * has no parent, interface or trait.
 */
final readonly class EndpointPolicy
{
    private const array FORBIDDEN_NAMES = ['localhost', 'metadata', 'instance-data', 'metadata.google.internal'];

    private const array FORBIDDEN_SUFFIXES = ['.localhost', '.local', '.internal', '.localdomain'];

    /**
     * The cloud metadata services, compared as packed addresses so that no
     * spelling of one is a different string from the one written here.
     *
     * @var list<string>
     */
    private const array METADATA_ADDRESSES = ['169.254.169.254', '100.100.100.200', 'fd00:ec2::254'];

    /**
     * Blocks no caller may be pointed at, each with the words an operator is
     * told.
     *
     * Covering blocks rather than their members: `::/8` holds the unspecified
     * address, loopback, IPv4-compatible and IPv4-mapped addresses, SIIT and
     * both NAT64 prefixes, and refusing it whole is what makes a new embedding
     * inside it a refusal on the day it is invented. The same reasoning takes
     * `2001::/23` whole, Teredo included. It is also why an IPv6-only
     * management network reaching IPv4-only BMCs through NAT64 cannot register
     * them by their translated addresses — see docs/security.md.
     *
     * @var list<array{string, int, string}>
     */
    private const array RESERVED_RANGES = [
        ['0.0.0.0', 8, '"this network", which a socket reads as this host'],
        ['127.0.0.0', 8, 'loopback'],
        ['169.254.0.0', 16, 'link-local'],
        ['192.0.0.0', 24, 'the IETF protocol assignments block'],
        ['192.88.99.0', 24, 'the retired 6to4 relay anycast block'],
        ['198.18.0.0', 15, 'the benchmarking block'],
        ['224.0.0.0', 4, 'multicast'],
        ['240.0.0.0', 4, 'the block set aside for future use, with the limited broadcast address'],
        ['::', 8, 'the block holding the unspecified address, loopback and every IPv4-in-IPv6 embedding: IPv4-compatible, IPv4-mapped, SIIT and both NAT64 prefixes'],
        ['100::', 64, 'the discard-only block'],
        ['2001::', 23, 'the IETF protocol assignments block, among them Teredo, whose addresses carry an IPv4 address inside them'],
        ['2002::', 16, '6to4, whose addresses carry an IPv4 address inside them'],
        ['fe80::', 10, 'link-local'],
        ['fec0::', 10, 'the retired site-local block'],
        ['ff00::', 8, 'multicast'],
    ];

    /**
     * Refused only for a provider that is not on our own hardware.
     *
     * @var list<array{string, int}>
     */
    private const array PRIVATE_RANGES = [
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['fc00::', 7],
    ];

    /** @var array{string, int} */
    private const array SHARED_ADDRESS_SPACE = ['100.64.0.0', 10];

    /**
     * What a host may be written with, once lower-cased and unbracketed.
     *
     * Its `\z` is pinned by nothing worth writing. Every host that reaches it
     * has already passed the control-character refusal above it, so no input
     * through either entry point tells `\z` from `$` here. A test comparing it
     * with one of the rules elsewhere in the platform that judge a host and
     * are anchored with `$` would tell them apart — but it would assert a
     * relationship between rules nobody keeps in step, which is not a pin. It
     * is `\z` for the same reason as every other anchor in this class: so that
     * nobody has to work out whether it matters.
     */
    private const string HOST_CHARACTERS = '/^[a-z0-9._:-]+\z/';

    /**
     * A decimal or octal number. Hex is judged beside it, in `isANumber`.
     *
     * Deliberately the same judgement as `ctype_digit`, which is what the Dns
     * module applies to a name's last label; a test holds the two together.
     */
    private const string NUMERIC_LABEL = '/^[0-9]+\z/';

    /**
     * One label of a host name.
     *
     * Not the Dns module's label rule, on purpose and in one respect: a host
     * label may carry an underscore, which a record name this platform writes
     * may not. That module is also not depended on from here — this class is
     * in Shared and is depended on by it — and its rule is anchored with `$`,
     * which admits a final newline this one refuses. A test sweeps both rules
     * over the same labels and says which way each difference goes.
     */
    private const string HOST_LABEL = '/^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?\z/';

    private const int MAX_HOST_LENGTH = 253;

    private const int MAX_LABEL_LENGTH = 63;

    public function __construct(
        private HostResolver $resolver,
        private ReferenceValues $reference = new ReferenceValues,
    ) {}

    public function assertProviderEndpoint(string $endpoint, bool $controlledDriver, bool $onOurHardware, bool $production): void
    {
        if ($controlledDriver) {
            if ($production || preg_match('/^fake:\/\/[a-z0-9-]{1,60}\z/', $endpoint) !== 1) {
                throw EndpointRefused::because($endpoint, 'a controlled driver takes a fake:// marker and nothing else, and never in production.');
            }

            return;
        }

        $parts = parse_url($endpoint);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw EndpointRefused::because($endpoint, 'not a URL with a scheme and a host.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw EndpointRefused::because($endpoint, 'a real provider speaks HTTPS; nothing else is dialled.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw EndpointRefused::because($endpoint, 'a credential in an endpoint is a credential in a log line. Record it in the credential centre and attach it.');
        }

        /*
         * The host `parse_url` reports must be the characters written straight
         * after the scheme. The parser rewrites every control character in a
         * host into `_`, so for `https://169.254.169.254\n/` it reports
         * `169.254.169.254_` — a host nobody wrote, which this class would
         * otherwise have judged in place of the one that reaches the socket.
         */
        if (! str_starts_with($endpoint, sprintf('%s://%s', $parts['scheme'], $parts['host']))) {
            throw EndpointRefused::because($endpoint, 'the URL parser read a host that is not the one written after the scheme — it rewrites control characters into underscores — so the host this policy would judge is not the host that would be dialled.');
        }

        $this->assertHost($endpoint, $parts['host'], allowPrivate: $onOurHardware, production: $production);
    }

    public function assertMachineAddress(string $address, bool $production): void
    {
        if (str_starts_with($address, 'fake://')) {
            if ($production || preg_match('/^fake:\/\/[a-z0-9-]{1,60}\z/', $address) !== 1) {
                throw EndpointRefused::because($address, 'a fake address is for rehearsal, and never in production.');
            }

            return;
        }

        if (str_contains($address, '://') || str_contains($address, '/') || str_contains($address, '@') || str_contains($address, ' ')) {
            throw EndpointRefused::because($address, 'a machine address is a hostname or an IP address, not a URL.');
        }

        // Machines are on the management network: private is expected.
        $this->assertHost($address, $this->hostWithoutPort($address), allowPrivate: true, production: $production);
    }

    /**
     * The host half of a machine address, with a port removed and checked.
     *
     * Separated out because leaving the port attached defeated every check
     * below it, and silently. `assertHost` asks whether the string is an IP
     * literal; `169.254.169.254:80` is not one, so it fell through to name
     * resolution, which cannot resolve a string with a port in it either, and
     * returned no addresses at all — so the loop that refuses loopback,
     * link-local and the cloud metadata services ran zero times and the
     * address was accepted.
     *
     * A BMC on a non-standard port is an ordinary thing to have, so the answer
     * is to parse the port rather than to forbid one. The port is then
     * validated in its own right: a machine address is dialled, and a port
     * outside 1-65535 is not a thing that can be dialled.
     */
    private function hostWithoutPort(string $address): string
    {
        // A bracketed IPv6 literal, with or without a port: [::1] or [::1]:443.
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d{1,5}))?\z/', $address, $match) === 1) {
            $this->assertPort($address, $match[2] ?? null);

            return $match[1];
        }

        /*
         * An unbracketed address with more than one colon is a bare IPv6
         * literal — `fe80::1` — and the last colon is part of the address, not
         * a port separator. Splitting on it would turn a loopback literal into
         * an unrecognised name, which is the bug this method exists for.
         */
        if (substr_count($address, ':') === 1) {
            [$host, $port] = explode(':', $address, 2);

            $this->assertPort($address, $port);

            return $host;
        }

        return $address;
    }

    private function assertPort(string $original, ?string $port): void
    {
        if ($port === null) {
            return;
        }

        if (preg_match('/^\d{1,5}\z/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw EndpointRefused::because($original, 'the port is not a port number.');
        }
    }

    private function assertHost(string $original, string $host, bool $allowPrivate, bool $production): void
    {
        $host = $this->canonicalHost($original, $host);

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! $literal) {
            $this->assertName($original, $host, $production);
        }

        $addresses = $literal ? [$host] : $this->resolver->addressesFor($host);

        /*
         * A name the resolver can see no address for is refused. The loop
         * below used to run zero times for one, and the name was accepted
         * with nothing checked, which made every refusal about where a name
         * points conditional on the resolver having answered.
         */
        if ($addresses === []) {
            throw EndpointRefused::because($original, 'the name resolves to no address, so where it points cannot be checked. Register the address itself, or create the name\'s DNS record first.');
        }

        foreach ($addresses as $address) {
            $this->assertAddress($original, $address, $allowPrivate, $literal, $production);
        }
    }

    /**
     * The one spelling of a host this class judges: lower-case, unbracketed,
     * ASCII, and either an IP literal in canonical form or a name made of
     * labels.
     *
     * Every refusal here is about the spelling rather than the destination, and
     * every one happens before anything is resolved.
     */
    private function canonicalHost(string $original, string $host): string
    {
        /*
         * The character-class refusals. Each asks whether a character is
         * present anywhere in the host, so neither has an end to anchor to.
         */
        if (preg_match('/[\x00-\x1f\x7f]/', $host) === 1) {
            throw EndpointRefused::because($original, 'the host contains a control character.');
        }

        if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
            throw EndpointRefused::because($original, 'the host is not ASCII. Some resolvers read characters such as 。 and ． as dots, so a name written with them is not the name that would be checked; an internationalised name is written in its punycode form.');
        }

        $host = strtolower($host);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (str_contains($host, '%')) {
            throw EndpointRefused::because($original, 'a zone identifier names a network interface on this host, which is not an address anything else can be reached at.');
        }

        if (str_ends_with($host, '.')) {
            throw EndpointRefused::because($original, 'a trailing dot is the root label: it names the same host as the name without it, and would slip past every comparison made by name.');
        }

        if (preg_match(self::HOST_CHARACTERS, $host) !== 1) {
            throw EndpointRefused::because($original, 'a host is written with letters, digits, dots, hyphens and underscores, or as an IP address.');
        }

        if (strlen($host) > self::MAX_HOST_LENGTH) {
            throw EndpointRefused::because($original, sprintf('the host is longer than %d characters, which no name is.', self::MAX_HOST_LENGTH));
        }

        if (str_contains($host, ':')) {
            $packed = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false ? false : inet_pton($host);

            if ($packed === false) {
                throw EndpointRefused::because($original, 'the host has a colon in it and is not an IPv6 address.');
            }

            return (string) inet_ntop($packed);
        }

        $labels = explode('.', $host);

        if ($this->isANumber($labels[array_key_last($labels)])) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw EndpointRefused::because($original, 'its last label is a number, which makes the whole host an IPv4 address to a resolver — inet_aton reads 0x7f000001, 2130706433, 0177.0.0.1 and 127.1 all as 127.0.0.1 — and the only spelling of an address accepted here is four decimal parts with no leading zeros.');
            }

            return $host;
        }

        foreach ($labels as $label) {
            if (strlen($label) > self::MAX_LABEL_LENGTH || preg_match(self::HOST_LABEL, $label) !== 1) {
                throw EndpointRefused::because($original, sprintf('a label of a host is one to %d letters, digits, hyphens and underscores, and neither begins nor ends with a hyphen.', self::MAX_LABEL_LENGTH));
            }
        }

        return $host;
    }

    /**
     * Whether a label is a number the way a URL parser decides a host is an
     * IPv4 address: all decimal digits (octal is digits too), or `0x`
     * followed by hex digits or by nothing.
     */
    private function isANumber(string $label): bool
    {
        if (preg_match(self::NUMERIC_LABEL, $label) === 1) {
            return true;
        }

        if (! str_starts_with($label, '0x')) {
            return false;
        }

        $digits = substr($label, 2);

        return $digits === '' || ctype_xdigit($digits);
    }

    private function assertName(string $original, string $host, bool $production): void
    {
        if (in_array($host, self::FORBIDDEN_NAMES, strict: true)) {
            throw EndpointRefused::because($original, 'that name is this host or a metadata service.');
        }

        foreach (self::FORBIDDEN_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw EndpointRefused::because($original, sprintf('names under %s are this host, this network or a metadata service.', $suffix));
            }
        }

        /*
         * Production only, and the restriction is the point: `.example` and
         * `.test` are how the Ansible inventories and the development seeder
         * name hosts that do not exist, and `.invalid` is how Gap 2's negative
         * matrix names one. All three are correct there and disqualifying here.
         *
         * Below the suffix loop rather than above it, so that `x.localhost`
         * keeps the more specific refusal. Both would reject it; only one tells
         * the operator that the name is this machine.
         */
        if ($production && $this->reference->isDocumentationHostname($host)) {
            throw EndpointRefused::because($original, 'that name is under a domain reserved for examples and is never delegated, so nothing will ever answer it in production.');
        }
    }

    private function assertAddress(string $original, string $address, bool $allowPrivate, bool $literal, bool $production): void
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            throw EndpointRefused::because($original, 'the resolver answered with something that is not an address, and nothing that cannot be judged is dialled.');
        }

        $subject = $literal ? 'the address is' : 'it resolves to an address';

        foreach (self::METADATA_ADDRESSES as $metadata) {
            if ($packed === inet_pton($metadata)) {
                throw EndpointRefused::because($original, $literal ? 'that is a cloud metadata service.' : 'it resolves to a cloud metadata service.');
            }
        }

        /*
         * Applied to a resolved address as well as to a literal, so that a name
         * pointed at 203.0.113.10 is refused for the same reason the literal
         * is. PHP's reserved set does not contain the documentation ranges:
         * measured on 8.4, FILTER_FLAG_NO_RES_RANGE accepts 192.0.2.10,
         * 198.51.100.10, 203.0.113.10 and 2001:db8::1. A comment in this class
         * once claimed otherwise, which meant every documentation address in
         * this repository's own examples was an acceptable production provider
         * endpoint.
         */
        if ($production && $this->reference->isDocumentationAddress($address)) {
            throw EndpointRefused::because($original, sprintf(
                '%s in a range reserved for documentation, so it belongs to nobody and routes nowhere. A production endpoint needs a real address.',
                $subject,
            ));
        }

        foreach (self::RESERVED_RANGES as [$network, $bits, $what]) {
            if ($this->within($packed, $network, $bits)) {
                throw EndpointRefused::because($original, sprintf(
                    '%s in %s/%d, %s, which is reserved and is never a machine this platform manages.',
                    $subject,
                    $network,
                    $bits,
                    $what,
                ));
            }
        }

        if ($allowPrivate) {
            return;
        }

        [$network, $bits] = self::SHARED_ADDRESS_SPACE;

        if ($this->within($packed, $network, $bits)) {
            throw EndpointRefused::because($original, 'that address range is never a provider.');
        }

        foreach (self::PRIVATE_RANGES as [$network, $bits]) {
            if ($this->within($packed, $network, $bits)) {
                throw EndpointRefused::because($original, sprintf(
                    '%s private, and a provider that is not on our hardware is not on our network.',
                    $literal ? 'the address is' : 'it resolves to an address that is',
                ));
            }
        }
    }

    /**
     * Whether a packed address is inside a network, compared bit by bit.
     *
     * Whole bytes are compared as strings and the partial byte through a mask:
     * a /23 is two bytes and seven bits, and comparing two bytes would put
     * 2001:200:: inside 2001::/23.
     */
    private function within(string $packed, string $network, int $bits): bool
    {
        $base = inet_pton($network);

        if ($base === false || strlen($base) !== strlen($packed)) {
            return false;
        }

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
