<?php

declare(strict_types=1);

namespace Lynomia\Modules\Cdn\Domain\DTOs;

/**
 * One cache rule as the platform expresses it: a path pattern and how long
 * the edge may hold a response for it. Zero means never cache.
 */
final readonly class CacheRule
{
    public function __construct(
        public string $pathPattern,
        public int $ttlSeconds,
    ) {}
}
