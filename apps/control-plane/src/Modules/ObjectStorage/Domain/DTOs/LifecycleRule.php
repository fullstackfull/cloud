<?php

declare(strict_types=1);

namespace Lynomia\Modules\ObjectStorage\Domain\DTOs;

/**
 * Expire objects under a prefix after a number of days. The one lifecycle
 * shape every S3-compatible store agrees on.
 */
final readonly class LifecycleRule
{
    public function __construct(
        public string $prefix,
        public int $expireAfterDays,
    ) {}
}
