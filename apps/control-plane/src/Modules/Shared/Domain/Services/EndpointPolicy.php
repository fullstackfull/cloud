<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Services;

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
 *   - Loopback, link-local, unspecified, multicast and the cloud metadata
 *     addresses are refused everywhere, by literal and by what a hostname
 *     resolves to.
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
 * Checked at registration and again at use, so a row that arrived by a road
 * this did not guard is still refused before a socket opens.
 */
final readonly class EndpointPolicy
{
    private const array FORBIDDEN_NAMES = ['localhost', 'metadata', 'instance-data', 'metadata.google.internal'];

    private const array FORBIDDEN_SUFFIXES = ['.localhost', '.local', '.internal', '.localdomain'];

    private const array METADATA_LITERALS = ['169.254.169.254', '100.100.100.200', 'fd00:ec2::254'];

    public function __construct(
        private ReferenceValues $reference = new ReferenceValues,
    ) {}

    public function assertProviderEndpoint(string $endpoint, bool $controlledDriver, bool $onOurHardware, bool $production): void
    {
        if ($controlledDriver) {
            if ($production || preg_match('/^fake:\/\/[a-z0-9-]{1,60}$/', $endpoint) !== 1) {
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

        $this->assertHost($endpoint, $parts['host'], allowPrivate: $onOurHardware, production: $production);
    }

    public function assertMachineAddress(string $address, bool $production): void
    {
        if (str_starts_with($address, 'fake://')) {
            if ($production || preg_match('/^fake:\/\/[a-z0-9-]{1,60}$/', $address) !== 1) {
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
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d{1,5}))?$/', $address, $match) === 1) {
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

        if (preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw EndpointRefused::because($original, 'the port is not a port number.');
        }
    }

    private function assertHost(string $original, string $host, bool $allowPrivate, bool $production): void
    {
        $host = strtolower(trim($host, '[]'));

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

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        $addresses = $literal ? [$host] : $this->resolve($host);

        foreach ($addresses as $ip) {
            $this->assertAddress($original, $ip, $allowPrivate, $literal, $production);
        }
    }

    private function assertAddress(string $original, string $ip, bool $allowPrivate, bool $literal, bool $production): void
    {
        if (in_array($ip, self::METADATA_LITERALS, strict: true)) {
            throw EndpointRefused::because($original, 'that is a cloud metadata service.');
        }

        /*
         * Applied to a resolved address as well as to a literal, so that a name
         * pointed at 203.0.113.10 is refused for the same reason the literal
         * is. This check is here rather than in the flags below because PHP's
         * reserved set does not contain the documentation ranges: measured on
         * 8.4, FILTER_FLAG_NO_RES_RANGE accepts 192.0.2.10, 198.51.100.10,
         * 203.0.113.10 and 2001:db8::1, with and without NO_PRIV_RANGE. The
         * comment below this one used to claim otherwise and was wrong, which
         * meant every documentation address in this repository's own examples
         * was an acceptable production provider endpoint.
         */
        if ($production && $this->reference->isDocumentationAddress($ip)) {
            throw EndpointRefused::because($original, sprintf(
                '%s in a range reserved for documentation, so it belongs to nobody and routes nowhere. A production endpoint needs a real address.',
                $literal ? 'the address is' : 'it resolves to an address',
            ));
        }

        // Reserved ranges — loopback, link-local, unspecified and multicast —
        // are refused for everything. FILTER_FLAG_NO_RES_RANGE covers them;
        // NO_PRIV_RANGE is added only when the caller is not on our own
        // hardware. The documentation ranges are NOT in that set and are
        // handled above.
        $flags = FILTER_FLAG_NO_RES_RANGE | ($allowPrivate ? 0 : FILTER_FLAG_NO_PRIV_RANGE);

        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            throw EndpointRefused::because($original, $allowPrivate
                ? sprintf('%s is loopback, link-local or otherwise reserved.', $literal ? 'the address' : 'it resolves to an address that')
                : sprintf('%s is private or reserved, and a provider that is not on our hardware is not on our network.', $literal ? 'the address' : 'it resolves to an address that'));
        }

        // Multicast (224/4) is not in PHP's reserved set and is never a host.
        if (preg_match('/^2(2[4-9]|3\d)\./', $ip) === 1 || str_starts_with(strtolower($ip), 'ff')) {
            throw EndpointRefused::because($original, 'the address is multicast, which is loopback, link-local or otherwise reserved for this purpose.');
        }

        // The shared-address range (100.64/10) and 0/8 are not in PHP's
        // reserved set and are never a provider.
        if (preg_match('/^(0\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.)/', $ip) === 1 && ! $allowPrivate) {
            throw EndpointRefused::because($original, 'that address range is never a provider.');
        }
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        // A name that does not resolve is not a way in; the connection test
        // will say so when it fails to connect. A name that resolves to a
        // forbidden address is refused now, before anything dials it.
        $v4 = @gethostbynamel($host);

        return $v4 === false ? [] : $v4;
    }
}
