<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

/**
 * What came back when the platform looked at a site.
 *
 * `$reachable` and `$isWordPress` are separate answers, because a site that
 * responds and is not WordPress is a real and different situation: usually a
 * name pointed somewhere else, occasionally an install that went to the wrong
 * document root. Telling that customer "your site is not responding" sends
 * them to check the wrong thing.
 */
final readonly class SiteProbeResult
{
    public function __construct(
        public bool $reachable,
        public bool $isWordPress = false,
        public bool $secure = false,
        public ?int $statusCode = null,

        /** Why the platform did not or could not look. Shown to operators. */
        public ?string $refusal = null,
    ) {}

    public static function unreachable(?string $refusal = null): self
    {
        return new self(reachable: false, refusal: $refusal);
    }
}
