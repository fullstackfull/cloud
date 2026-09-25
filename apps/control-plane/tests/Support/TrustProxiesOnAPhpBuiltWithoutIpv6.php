<?php

declare(strict_types=1);

namespace Tests\Support;

use Lynomia\Http\Middleware\TrustProxies;
use RuntimeException;

/**
 * The trusted-proxy middleware on a PHP built with `--disable-ipv6`.
 *
 * On such a build `IpUtils::checkIp6()` throws a `RuntimeException` before it
 * compares anything. This suite cannot rebuild PHP, and the obvious shortcut —
 * editing `IpUtils.php` in place and restoring it — is not safe here:
 * `vendor/` is shared between checkouts, so the edit is machine-wide for as
 * long as it lasts, and every other suite running in that window sees a PHP
 * without IPv6. So the simulation is a subclass instead, replacing the one
 * seam through which the middleware asks the question.
 *
 * It reproduces the real method's shape, not just its failure:
 * `IpUtils::checkIp()` decides which comparison to use from the address, and
 * calls it once per entry inside its loop (`symfony/http-foundation` v8.1.6,
 * `IpUtils.php:68-76`). So an IPv6 address asked about an EMPTY list returns
 * false without ever reaching the throw, and only a non-empty list throws.
 * A double that threw on every IPv6 address would claim a failure the real
 * build does not have.
 *
 * Only the middleware's own question is simulated. The framework's later
 * checks — `Request::isFromTrustedProxy()` and the forwarded-chain filter —
 * call the real `IpUtils`, which on this test machine can evaluate IPv6.
 */
final class TrustProxiesOnAPhpBuiltWithoutIpv6 extends TrustProxies
{
    /**
     * @param  list<string>  $entries
     */
    protected function covers(string $address, array $entries): bool
    {
        if (substr_count($address, ':') > 1) {
            foreach ($entries as $entry) {
                throw new RuntimeException(sprintf(
                    'Unable to check Ipv6. Check that PHP was not compiled with option "disable-ipv6". (asked about %s against %s)',
                    $address,
                    $entry,
                ));
            }

            return false;
        }

        return parent::covers($address, $entries);
    }
}
