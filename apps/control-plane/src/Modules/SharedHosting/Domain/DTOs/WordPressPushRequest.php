<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressPushScope;

final readonly class WordPressPushRequest
{
    public function __construct(
        public string $username,
        public string $stagingDomain,
        public string $productionDomain,
        public WordPressPushScope $scope,
    ) {}
}
