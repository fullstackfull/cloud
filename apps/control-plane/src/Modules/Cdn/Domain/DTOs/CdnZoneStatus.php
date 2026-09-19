<?php

declare(strict_types=1);

namespace Lynomia\Modules\Cdn\Domain\DTOs;

/**
 * What a CDN says about one zone: whether it is fronting the origin,
 * whether it is in development mode (bypassing the cache), and what it
 * believes about the certificate on the edge.
 */
final readonly class CdnZoneStatus
{
    public function __construct(
        public string $zone,
        public bool $enabled,
        public bool $developmentMode,
        /** One of `active`, `pending`, `expired`, `none` — the edge's word, not the origin's. */
        public string $tlsStatus,
    ) {}
}
