<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Probes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\SharedHosting\Domain\Contracts\SiteProbe;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SiteProbeResult;

/**
 * Fetch the site and see whether WordPress answers.
 *
 * ===========================================================================
 * THIS IS THE ONE OUTBOUND REQUEST TO AN ADDRESS A CUSTOMER CHOSE
 * ===========================================================================
 *
 * Everything else this platform calls is a provider it configured. This
 * fetches a name a customer typed, which resolves wherever they point it — so
 * it is a request-forgery primitive unless it is written as one that is not.
 *
 * Three things make it safe, and all three are necessary:
 *
 *  1. **The name is resolved first and the addresses are checked.** A domain
 *     pointed at 127.0.0.1, 169.254.169.254 or a 10.0.0.0/8 address is refused
 *     without a request being made. Without this, a customer could aim the
 *     platform's own network at its own metadata service and read the answer
 *     back off their site's status page.
 *
 *  2. **Redirects are not followed.** A public address that answers with a
 *     redirect to an internal one would walk straight past the first check.
 *
 *  3. **Nothing from the response reaches the customer.** The probe answers
 *     in booleans and a status code. A body relayed onto a status screen
 *     would turn the platform into an open proxy with a nice interface.
 *
 * The timeouts are short on purpose: this runs on a sweep across every site,
 * and a customer whose server hangs must not hold up everybody else's checks.
 */
final readonly class HttpSiteProbe implements SiteProbe
{
    private const int TIMEOUT_SECONDS = 5;

    /**
     * Address ranges the platform will not fetch from.
     *
     * Loopback, link-local (which is where cloud metadata lives), the three
     * private IPv4 blocks, carrier-grade NAT, and the IPv6 equivalents. A name
     * resolving to any of these is a name pointed somewhere it has no business
     * pointing, and the platform declines to look rather than looking on the
     * customer's behalf.
     *
     * @var list<string>
     */
    private const array REFUSED_RANGES = [
        '127.0.0.0/8', '0.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
        '169.254.0.0/16', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    public function probe(string $url): SiteProbeResult
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return SiteProbeResult::unreachable('the site URL has no host');
        }

        if (! $this->resolvesSomewherePublic($host)) {
            return SiteProbeResult::unreachable('the name does not resolve to a public address');
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                // See point 2 above: a redirect is a second destination that
                // nothing has checked.
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'Lynomia-SiteCheck/1.0'])
                ->get($url);
        } catch (ConnectionException $e) {
            return SiteProbeResult::unreachable($e->getMessage());
        }

        $body = $response->body();

        /*
         * What WordPress puts on a page it rendered. The generator meta tag is
         * the reliable one; wp-content appears in essentially every theme's
         * asset URLs and catches installations that strip the tag.
         *
         * A false negative here costs a customer a site stuck at "installed
         * but not verified", which a person can clear. A false positive would
         * report somebody else's parked page as their site.
         */
        $isWordPress = str_contains($body, 'wp-content')
            || str_contains($body, '/wp-includes/')
            || preg_match('/<meta name="generator" content="WordPress/i', $body) === 1;

        return new SiteProbeResult(
            reachable: $response->status() < 500,
            isWordPress: $isWordPress,
            secure: str_starts_with($url, 'https://'),
            statusCode: $response->status(),
        );
    }

    /**
     * Whether every address this name resolves to is one the platform may
     * fetch.
     *
     * Every address, not any: a name with one public A record and one pointing
     * at 10.0.0.1 is a name that will sometimes resolve to the second one, and
     * "sometimes safe" is not a property worth having here.
     */
    private function resolvesSomewherePublic(string $host): bool
    {
        /** @var list<array<string, mixed>>|false $records */
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            return false;
        }

        $found = false;

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (! is_string($address)) {
                continue;
            }

            if ($this->isRefused($address)) {
                return false;
            }

            $found = true;
        }

        return $found;
    }

    private function isRefused(string $address): bool
    {
        foreach (self::REFUSED_RANGES as $range) {
            if ($this->inRange($address, $range)) {
                return true;
            }
        }

        // Anything the platform cannot classify is refused rather than
        // fetched. A probe is worth skipping; an unclassified address is not
        // worth a request.
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function inRange(string $address, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $addressBinary = @inet_pton($address);
        $subnetBinary = @inet_pton($subnet);

        if ($addressBinary === false || $subnetBinary === false) {
            return false;
        }

        if (strlen($addressBinary) !== strlen($subnetBinary)) {
            // Comparing an IPv4 address against an IPv6 range, or the reverse.
            return false;
        }

        $prefix = (int) $bits;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($wholeBytes > 0 && strncmp($addressBinary, $subnetBinary, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($addressBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }
}
