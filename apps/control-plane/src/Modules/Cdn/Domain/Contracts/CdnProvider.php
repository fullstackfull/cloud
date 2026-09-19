<?php

declare(strict_types=1);

namespace Lynomia\Modules\Cdn\Domain\Contracts;

use Lynomia\Modules\Cdn\Domain\DTOs\CacheRule;
use Lynomia\Modules\Cdn\Domain\DTOs\CdnZoneStatus;

/**
 * Whatever fronts a customer's origin with an edge cache.
 *
 * ===========================================================================
 * A TYPED SEAT, NOT A PRODUCT
 * ===========================================================================
 *
 * There is no implementation of this interface in this build, no `cdn`
 * driver in the catalogue, and no CDN product for sale: `Product::Cdn` is
 * a prepared product, capped below production by its software state, and
 * a declaration on it is refused by name. What exists is the shape — one
 * method per capability the readiness engine asks a CDN provider about,
 * so that when an adapter is written the questions and the answers line
 * up, and so that nothing on the platform can be built against a CDN
 * feature that no method here promises.
 *
 * Cloudflare is a DNS provider on this platform and nothing more: the
 * platform has not called its cache, proxy or TLS endpoints, and this
 * interface does not pretend it has.
 *
 * Every method is per zone, where a zone is a customer's domain, and every
 * mutation returns the status the edge reports afterwards rather than
 * `void` — a CDN is somebody else's servers, and what the platform
 * records is what they said, never what it asked for.
 */
interface CdnProvider
{
    /** Start fronting the zone's origin. */
    public function enable(string $zone): CdnZoneStatus;

    /** Stop fronting it; traffic goes to the origin directly. */
    public function disable(string $zone): CdnZoneStatus;

    /** Drop everything the edge holds for the zone. */
    public function purgeAll(string $zone): CdnZoneStatus;

    /**
     * Drop specific URLs. Bounded by the caller; an edge purges a list, not
     * a pattern.
     *
     * @param  list<string>  $urls
     */
    public function purgeUrls(string $zone, array $urls): CdnZoneStatus;

    /**
     * Replace the zone's cache rules with these.
     *
     * @param  list<CacheRule>  $rules
     */
    public function cacheRules(string $zone, array $rules): CdnZoneStatus;

    /** Bypass the cache for a while, for a customer who is deploying. */
    public function developmentMode(string $zone, bool $enabled): CdnZoneStatus;

    /** What the edge believes about the certificate it serves for the zone. */
    public function tlsStatus(string $zone): CdnZoneStatus;
}
