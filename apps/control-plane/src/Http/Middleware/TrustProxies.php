<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which callers may tell this application who the customer is.
 *
 * Behind a load balancer every request arrives from the balancer, and the
 * customer's address is only in the `X-Forwarded-For` the balancer adds. That
 * header is believed from the addresses named in `security.trusted_proxies`
 * (`TRUSTED_PROXIES`), and from nobody else, and every IP-keyed limiter on the
 * platform — the per-address sign-in ceiling, registration, anonymous API
 * reads, webhooks — keys on the answer.
 *
 * ---------------------------------------------------------------------------
 * Read when the request arrives, from configuration (F-31)
 * ---------------------------------------------------------------------------
 *
 * `bootstrap/app.php` used to pass `env('TRUSTED_PROXIES')` to
 * `$middleware->trustProxies()`. That closure runs when the HTTP kernel is
 * resolved, and `Application::handleRequest()` resolves the kernel BEFORE its
 * `handle()` runs the bootstrappers that load `.env`. So the read saw only
 * the process environment — which php-fpm does not fill from `.env`, and
 * which `config:cache` stops `.env` from ever reaching — and came back empty.
 * Nothing was trusted, every customer was keyed on the balancer's address,
 * and every IP-keyed limiter collapsed into one bucket shared by the whole
 * internet. No in-process test could see it, because the suite's console
 * kernel loads the environment before any request is made.
 *
 * The list is now configuration, parsed by `config/security.php` with every
 * other environment read, and asked for by {@see proxies()} on every request.
 * The framework's process-wide `TrustProxies::at()` / `withHeaders()` state is
 * not consulted by {@see proxies()} or {@see headers()}: the configuration is
 * the only source, so nothing set at boot can widen it.
 *
 * ---------------------------------------------------------------------------
 * What is refused
 * ---------------------------------------------------------------------------
 *
 * Trusting a caller means believing the address it says the customer has, so
 * a list that trusts every caller lets every caller pick a fresh limiter
 * bucket per request — worse than the collapse above. Refused entries are
 * dropped and the rest are kept:
 *
 * - Anything that is not an IPv4 or IPv6 address, optionally with a prefix
 *   length valid for its family. That takes out `*`, `**`, `REMOTE_ADDR`
 *   (trust whoever connects) and `PRIVATE_SUBNETS` along with hostnames and
 *   typos.
 * - An entry that on its own trusts every caller in one of three ranges, and
 *   then — because entries that cover a range only together cannot be told
 *   apart — every remaining entry of that family if together they still do
 *   ({@see matchesEveryCaller()}). The ranges are all of IPv4, all of IPv6,
 *   and every IPv4 caller as a dual-stack socket reports it
 *   (`::ffff:0:0/96`). The test asks whether the list covers both the
 *   first and last address of the range, measured by {@see covers()} with the
 *   same `IpUtils` the framework will later match callers with, so an entry
 *   spelled oddly (`0.0.0.0/00`) is judged exactly as it will be used. Every
 *   list that covers a whole range covers its two ends. The converse is not
 *   exact — a list could reach both ends without the middle — but no list of
 *   balancers reaches either end: they are `0.0.0.0`, the broadcast address,
 *   the unspecified address `::`, a multicast address, and the IPv4-mapped
 *   forms of the first two, none of which a host is ever given.
 * - An IPv6 entry, on a PHP built without IPv6. `IpUtils` throws rather than
 *   answer there, so whether the entry trusts every caller cannot be known,
 *   and the unknown answer is treated as the dangerous one. The IPv4 entries
 *   are still trusted. Each family's question is asked only of that family's
 *   entries, and never of an empty list, so an IPv4-only deployment on such a
 *   build asks no IPv6 question at all. (The empty-list half holds twice
 *   over: `IpUtils::checkIp()` only reaches the throwing comparison inside its
 *   loop over the entries — `symfony/http-foundation` v8.1.6,
 *   `IpUtils.php:68-76` — so an empty list never throws either.)
 *
 * Refusal is silent. A per-request warning would log once per request, and
 * it is not this class's to add: a mistyped entry is best caught by a
 * deployment-time check against configuration, which does not exist yet. A
 * refused entry leaves its callers keyed on the balancer's address — visible
 * in sign-in activity, which then records the balancer instead of the
 * customer — and never lets a client choose its key.
 *
 * ---------------------------------------------------------------------------
 * The framework's fallback, and the method nothing reaches
 * ---------------------------------------------------------------------------
 *
 * The inherited `setTrustedProxyIpAddresses()` falls back to
 * `config('trustedproxy.proxies')` when {@see proxies()} returns an empty
 * list, and when THAT is null and the request's Host ends in `.on-forge.com`
 * or `.on-vapor.com` (or `laravel_cloud()` says so) it trusts every caller —
 * the Host header being the caller's to write. `config/trustedproxy.php` pins
 * that key to an empty list, which is never null, so the fallback trusts
 * nobody and `setTrustedProxyIpAddressesToTheCallingIp()` — whose body is
 * `setTrustedProxies(['0.0.0.0/0', '::/0'])` — is reached by nothing.
 *
 * ---------------------------------------------------------------------------
 * Still open
 * ---------------------------------------------------------------------------
 *
 * On a PHP built without IPv6, a caller already behind a trusted balancer can
 * put an IPv6 address in `X-Forwarded-For` and get a rendered 500 on any route
 * that reads the client address: `Request::normalizeAndFilterClientIps()`
 * runs `IpUtils::checkIp()` over every forwarded entry, so the same throw
 * arrives by the framework's own door. While any balancer is trusted, a
 * client connecting over IPv6 itself reaches it too, through
 * `Request::isFromTrustedProxy()`, which asks the same question of the
 * connecting address whenever a forwarded header is read. Every per-request
 * repair is worse than that fault — trusting nobody for the request hands the
 * client the shared bucket on demand; dropping the entry lets the client
 * choose its own key; parsing the chain here duplicates the framework. The
 * right instrument is a deployment-time check that the build can evaluate
 * IPv6, not this class.
 *
 * ---------------------------------------------------------------------------
 * Why this class is not final
 * ---------------------------------------------------------------------------
 *
 * The test suite simulates a PHP without IPv6 by replacing {@see covers()} in
 * a subclass, because the alternative — editing `IpUtils.php` in `vendor/` —
 * changes the PHP every process sharing that vendor tree sees, for as long
 * as the edit lasts. Nothing outside the test suite extends this class.
 * Which methods a subclass could replace, and that no application class
 * does, is pinned by `TheTrustedProxyMiddlewareIsExtendedOnlyByTestsTest`
 * rather than listed here.
 */
class TrustProxies extends FrameworkTrustProxies
{
    /**
     * Each range no list may cover whole: the family of entry measured
     * against it, and its first and last address.
     *
     * @var list<array{0: 4|6, 1: string, 2: string}>
     */
    private const array EVERY_CALLER = [
        [4, '0.0.0.0', '255.255.255.255'],
        [6, '::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        [6, '::ffff:0.0.0.0', '::ffff:255.255.255.255'],
    ];

    /**
     * The configured balancers, less every entry refused above.
     *
     * @return list<string>
     */
    protected function proxies(): array
    {
        $entries = [4 => [], 6 => []];

        foreach (self::configured() as $entry) {
            $family = self::familyOf($entry);

            if ($family !== null) {
                $entries[$family][] = $entry;
            }
        }

        $trusted = $this->withoutWhatTrustsEveryCaller($entries[4], 4);

        try {
            return [...$trusted, ...$this->withoutWhatTrustsEveryCaller($entries[6], 6)];
        } catch (RuntimeException) {
            // `IpUtils` cannot evaluate IPv6 on this build, so the IPv6
            // entries cannot be shown not to trust every caller.
            return $trusted;
        }
    }

    /**
     * The framework's default header set, and never a set chosen at boot
     * through the static `withHeaders()`.
     */
    protected function headers(): int
    {
        return $this->headers;
    }

    /**
     * Whether the entries, together, reach both the first and last address of
     * a range. Never asked of an empty list.
     *
     * @param  list<string>  $entries
     */
    protected function matchesEveryCaller(array $entries, string $first, string $last): bool
    {
        return $entries !== [] && $this->covers($first, $entries) && $this->covers($last, $entries);
    }

    /**
     * Whether any entry matches the address, asked of the same `IpUtils` the
     * framework matches callers with.
     *
     * @param  list<string>  $entries
     *
     * @throws RuntimeException on a PHP built without IPv6, for an IPv6
     *                          address and a non-empty list
     */
    protected function covers(string $address, array $entries): bool
    {
        return IpUtils::checkIp($address, $entries);
    }

    /**
     * @param  list<string>  $entries  entries of one family
     * @param  4|6  $family
     * @return list<string>
     */
    private function withoutWhatTrustsEveryCaller(array $entries, int $family): array
    {
        foreach (self::EVERY_CALLER as [$of, $first, $last]) {
            if ($of !== $family) {
                continue;
            }

            $entries = array_values(array_filter(
                $entries,
                fn (string $entry): bool => ! $this->matchesEveryCaller([$entry], $first, $last),
            ));

            if ($this->matchesEveryCaller($entries, $first, $last)) {
                return [];
            }
        }

        return $entries;
    }

    /**
     * The configured entries, trimmed, with blanks and non-strings dropped. A
     * comma-separated string is accepted as well as a list, since a value set
     * straight into configuration may be either.
     *
     * @return list<string>
     */
    private static function configured(): array
    {
        $configured = config('security.trusted_proxies', []);

        $entries = match (true) {
            is_string($configured) => explode(',', $configured),
            is_array($configured) => $configured,
            default => [],
        };

        return array_values(array_filter(
            array_map(static fn (mixed $entry): string => is_string($entry) ? trim($entry) : '', $entries),
            static fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * 4 or 6 for an address, or an address with a prefix length valid for
     * its family; null for anything else.
     *
     * @return 4|6|null
     */
    private static function familyOf(string $entry): ?int
    {
        [$address, $prefix] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];

        $family = match (true) {
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 4,
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 6,
            default => null,
        };

        if ($family === null || $prefix === null) {
            return $family;
        }

        return ctype_digit($prefix) && (int) $prefix <= ($family === 4 ? 32 : 128) ? $family : null;
    }
}
