<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\DTOs;

use Lynomia\Modules\SharedHosting\Application\Queries\HostingPackageForPlan;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * The package a plan is sold under, or why there is not exactly one.
 *
 * Exactly one of the two is set. `refusal` is one of
 * {@see HostingPackageForPlan}'s constants, for code that has to tell the
 * refusals apart; `reason` is the same answer as a sentence for an operator,
 * which is what goes to a log line or onto a service that could not be placed.
 *
 * @immutable
 */
final readonly class HostingPackageChoice
{
    private function __construct(
        public ?HostingPackage $package,
        public ?string $refusal,
        public string $reason,
    ) {}

    public static function of(HostingPackage $package): self
    {
        return new self($package, null, '');
    }

    public static function refused(string $refusal, string $reason): self
    {
        return new self(null, $refusal, $reason);
    }
}
