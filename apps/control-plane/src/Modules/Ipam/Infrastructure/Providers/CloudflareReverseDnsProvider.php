<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Providers;

use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Infrastructure\CloudflareApi;
use Lynomia\Modules\Dns\Infrastructure\CloudflareConnection;
use Lynomia\Modules\Ipam\Domain\Contracts\ReverseDnsProvider;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsProviderException;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsUnavailableException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * PTR records through Cloudflare, for the address space Cloudflare actually
 * serves — and an honest refusal for the rest.
 *
 * ---------------------------------------------------------------------------
 * Why this cannot just write the record
 * ---------------------------------------------------------------------------
 *
 * Reverse DNS is not a property of a provider; it is a property of an address
 * block. The `in-addr.arpa` and `ip6.arpa` zones are delegated by whoever
 * assigned the block — a RIR, an upstream transit provider, a datacentre — and
 * an account can hold every forward zone this platform owns while having no
 * authority whatsoever over one PTR. Cloudflare will serve a reverse zone
 * perfectly well once it has been delegated to it, and not before.
 *
 * So this adapter asks first. It derives the PTR name, then looks for a zone
 * the account holds that covers it, widening from the most specific delegation
 * outward. If it finds none, it says so — {@see ReverseDnsUnavailableException::providerCannotServeZone()},
 * error code `ipam.reverse_dns_provider_unavailable` — and publishes nothing.
 *
 * The alternative, and the reason this class is written the way it is: an
 * adapter that reports success without a zone to write into produces a platform
 * whose dashboard says a customer's PTR is live while every receiver that
 * checks one rejects their mail. That failure is invisible from here and
 * expensive for them, which is the worst combination a defect can have.
 */
final class CloudflareReverseDnsProvider implements ReverseDnsProvider
{
    public const string NAME = 'cloudflare';

    /**
     * How the platform names a PTR whose zone is delegated on a byte boundary.
     *
     * RFC 2317 classless delegation — zones like `0-25.2.0.192.in-addr.arpa`
     * with a CNAME per address — is deliberately not attempted. It cannot be
     * derived from an address: the naming is a convention agreed between the
     * delegator and the delegate, and guessing at it would write records into
     * whichever zone happened to match. A deployment that needs it configures
     * the reverse zone in Cloudflare under a name this can find, or gets the
     * honest refusal.
     */
    private const string IPV4_SUFFIX = 'in-addr.arpa';

    private const string IPV6_SUFFIX = 'ip6.arpa';

    private ?CloudflareApi $api = null;

    /** @var array<string, string|null> ptr name => zone id */
    private array $zoneCache = [];

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    public function publish(IpAddressValue $address, Hostname $hostname): void
    {
        $ptrName = self::ptrNameFor($address);

        $zoneId = $this->zoneServing($ptrName, $address);

        if ($zoneId === null) {
            throw ReverseDnsUnavailableException::providerCannotServeZone(
                $address->value(),
                self::NAME,
                $ptrName,
            );
        }

        $this->upsert($zoneId, $ptrName, $hostname, $address);
    }

    /**
     * The PTR name for an address: `10.2.0.192.in-addr.arpa`, or the nibble
     * form under `ip6.arpa`.
     */
    public static function ptrNameFor(IpAddressValue $address): string
    {
        if ($address->version() === IpVersion::V4) {
            $octets = explode('.', $address->value());

            return implode('.', array_reverse($octets)).'.'.self::IPV4_SUFFIX;
        }

        $packed = inet_pton($address->value());

        // Unreachable for a constructed IpAddressValue, whose own rules already
        // rejected anything inet_pton would refuse. Kept because the alternative
        // is str_split(bin2hex(false)) and a name made of nothing.
        if ($packed === false) {
            return self::IPV6_SUFFIX;
        }

        $nibbles = str_split(bin2hex($packed));

        return implode('.', array_reverse($nibbles)).'.'.self::IPV6_SUFFIX;
    }

    /**
     * The id of the account's zone that covers this PTR name, or null.
     *
     * Widens outward one label at a time — `2.0.192.in-addr.arpa`, then
     * `0.192.in-addr.arpa`, then `192.in-addr.arpa` — and stops at the arpa
     * suffix itself, which nobody delegates to a customer account. The most
     * specific match wins, because that is the zone the delegation actually
     * points at.
     *
     * @throws ReverseDnsProviderException
     */
    private function zoneServing(string $ptrName, IpAddressValue $address): ?string
    {
        if (array_key_exists($ptrName, $this->zoneCache)) {
            return $this->zoneCache[$ptrName];
        }

        $suffix = $address->version() === IpVersion::V4 ? self::IPV4_SUFFIX : self::IPV6_SUFFIX;
        $suffixLabels = count(explode('.', $suffix));

        $labels = explode('.', $ptrName);

        // Start one label in from the address itself: the PTR name in full is
        // the record, never the zone.
        for ($i = 1; $i <= count($labels) - $suffixLabels; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            $body = $this->call('GET', '/zones', ['name' => $candidate, 'per_page' => 1], 'look up reverse zone '.$candidate, $address);

            /** @var array<string, mixed>|null $row */
            $row = is_array($body['result'] ?? null) ? (array_values($body['result'])[0] ?? null) : null;

            if (is_array($row) && ($id = (string) ($row['id'] ?? '')) !== '') {
                return $this->zoneCache[$ptrName] = $id;
            }
        }

        return $this->zoneCache[$ptrName] = null;
    }

    /**
     * @throws ReverseDnsProviderException
     */
    private function upsert(string $zoneId, string $ptrName, Hostname $hostname, IpAddressValue $address): void
    {
        $existing = $this->call(
            'GET',
            '/zones/'.$zoneId.'/dns_records',
            ['type' => 'PTR', 'name' => $ptrName, 'per_page' => 1],
            'read the existing PTR for '.$address->value(),
            $address,
        );

        /** @var array<string, mixed>|null $current */
        $current = is_array($existing['result'] ?? null) ? (array_values($existing['result'])[0] ?? null) : null;

        $wanted = rtrim($hostname->value(), '.');

        if (is_array($current) && rtrim((string) ($current['content'] ?? ''), '.') === $wanted) {
            // The zone already says this. Writing it again would bump the
            // serial and re-propagate a record that has not changed — and the
            // interface promises idempotence, not a write per call.
            return;
        }

        $payload = ['type' => 'PTR', 'name' => $ptrName, 'content' => $wanted, 'ttl' => 1];

        if (is_array($current) && ($id = (string) ($current['id'] ?? '')) !== '') {
            $this->call('PUT', '/zones/'.$zoneId.'/dns_records/'.$id, $payload, 'replace the PTR for '.$address->value(), $address);

            return;
        }

        $this->call('POST', '/zones/'.$zoneId.'/dns_records', $payload, 'publish the PTR for '.$address->value(), $address);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ReverseDnsProviderException
     */
    private function call(string $method, string $path, array $payload, string $operation, IpAddressValue $address): array
    {
        try {
            return $this->api()->call($method, $path, $payload, $operation);
        } catch (DnsProviderException $e) {
            /*
             * Translated rather than propagated, and the indeterminate flag is
             * carried across rather than re-derived. IPAM's callers branch on
             * that flag to decide between "tell the customer it failed" and
             * "leave it pending for an operator", and a translation that lost
             * it would turn every timeout into a reported failure — the exact
             * mistake that makes a platform retry a write it should not.
             */
            throw $e->isIndeterminate()
                ? ReverseDnsProviderException::timedOut($address->value())
                : ReverseDnsProviderException::refused($address->value(), $e->getMessage());
        } catch (DnsNotConfiguredException $e) {
            // A missing token is the platform's problem, not the provider's,
            // and it is determinate: nothing was sent.
            throw ReverseDnsProviderException::refused($address->value(), $e->getMessage());
        }
    }

    /**
     * @throws DnsNotConfiguredException
     */
    private function api(): CloudflareApi
    {
        return $this->api ??= new CloudflareApi(
            CloudflareConnection::fromConfig(self::NAME),
            $this->redactor,
            self::NAME,
        );
    }
}
