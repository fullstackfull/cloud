<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

final readonly class WordPressCopyRequest
{
    public function __construct(
        public string $username,
        public string $sourceDomain,
        public string $targetDomain,
    ) {}
}
