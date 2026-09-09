<?php

declare(strict_types=1);

namespace Lynomia\Modules\ObjectStorage\Domain\DTOs;

final readonly class Bucket
{
    public function __construct(
        public string $name,
        public ?int $quotaBytes,
        public bool $versioning,
        public string $endpoint,
    ) {}
}
