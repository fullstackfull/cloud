<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Probes;

use Lynomia\Modules\SharedHosting\Domain\Contracts\SiteProbe;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SiteProbeResult;
use Lynomia\Modules\SharedHosting\Domain\Services\FakeHostingProviderGuard;

/**
 * A probe that answers from markers in the name rather than from the network.
 *
 * The three answers worth rehearsing are all failures the real probe can give
 * and a naive one cannot: a site that does not respond, a site that responds
 * and is somebody else's, and a site that works without a certificate.
 */
final class FakeSiteProbe implements SiteProbe
{
    /** A name carrying this does not answer at all. */
    public const string DOWN_MARKER = 'site-down';

    /** A name carrying this answers, and it is not WordPress. */
    public const string FOREIGN_MARKER = 'site-foreign';

    /** A name carrying this works, with no certificate. */
    public const string INSECURE_MARKER = 'site-insecure';

    public function __construct()
    {
        FakeHostingProviderGuard::assertNotProduction('site-probe');
    }

    public function probe(string $url): SiteProbeResult
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (str_contains($host, self::DOWN_MARKER)) {
            return SiteProbeResult::unreachable('the fake probe was told this site is down');
        }

        if (str_contains($host, self::FOREIGN_MARKER)) {
            /*
             * Answering and not WordPress. Usually a name still pointed at
             * somebody else — which is the case a platform must not report as
             * "your site is ready", because it would be reporting a stranger's
             * page as the customer's.
             */
            return new SiteProbeResult(reachable: true, isWordPress: false, secure: true, statusCode: 200);
        }

        return new SiteProbeResult(
            reachable: true,
            isWordPress: true,
            secure: ! str_contains($host, self::INSECURE_MARKER),
            statusCode: 200,
        );
    }
}
