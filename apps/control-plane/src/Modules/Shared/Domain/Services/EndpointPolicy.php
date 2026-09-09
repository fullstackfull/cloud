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
 *
 * Checked at registration and again at use, so a row that arrived by a road
 * this did not guard is still refused before a socket opens.
 */
final readonly class EndpointPolicy
{
    private const array FORBIDDEN_NAMES = ['localhost', 'metadata', 'instance-data', 'metadata.google.internal'];

    private const array FORBIDDEN_SUFFIXES = ['.localhost', '.local', '.internal', '.localdomain'];

    private const array METADATA_LITERALS = ['169.254.169.254', '100.100.100.200', 'fd00:ec2::254'];

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

        $this->assertHost($endpoint, $parts['host'], allowPrivate: $onOurHardware);
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
        $this->assertHost($address, trim($address, '[]'), allowPrivate: true);
    }

    private function assertHost(string $original, string $host, bool $allowPrivate): void
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

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        $addresses = $literal ? [$host] : $this->resolve($host);

        foreach ($addresses as $ip) {
            $this->assertAddress($original, $ip, $allowPrivate, $literal);
        }
    }

    private function assertAddress(string $original, string $ip, bool $allowPrivate, bool $literal): void
    {
        if (in_array($ip, self::METADATA_LITERALS, strict: true)) {
            throw EndpointRefused::because($original, 'that is a cloud metadata service.');
        }

        // Reserved ranges — loopback, link-local, unspecified, multicast,
        // documentation — are refused for everything. FILTER_FLAG_NO_RES_RANGE
        // covers them; NO_PRIV_RANGE is added only when the caller is not on
        // our own hardware.
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
