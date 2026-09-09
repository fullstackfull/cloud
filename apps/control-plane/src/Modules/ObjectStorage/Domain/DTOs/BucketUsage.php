<?php

declare(strict_types=1);

namespace Lynomia\Modules\ObjectStorage\Domain\DTOs;

final readonly class BucketUsage
{
    public function __construct(
        public string $bucket,
        public int $bytesUsed,
        public int $objectCount,
    ) {}
}
