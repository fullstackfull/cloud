<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Enums;

/**
 * How much a plan could cost if it is wrong.
 *
 * Shown, not computed from a score: each change a plan contains declares its
 * own risk, and the plan takes the worst. A plan that repartitions a disk is
 * Destructive even if the other nine steps install a monitoring agent.
 */
enum PlanRisk: string
{
    /** Nothing changes. A plan with no changes is still worth producing. */
    case None = 'none';

    /** Configuration only, no restart of anything customers touch. */
    case Low = 'low';

    /** A service restarts, or something customers use is briefly unavailable. */
    case Moderate = 'moderate';

    /** A reboot, or a change to networking that could cut the management path. */
    case High = 'high';

    /** Data is destroyed: partitions, RAID, an operating system. */
    case Destructive = 'destructive';

    /** Which safety class a plan of this risk needs. */
    public function requires(): SafetyClass
    {
        return match ($this) {
            self::None => SafetyClass::DiscoveryOnly,
            self::Low, self::Moderate, self::High => SafetyClass::ConfigurationAllowed,
            self::Destructive => SafetyClass::ReimageAllowed,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public static function worst(self ...$risks): self
    {
        $worst = self::None;

        foreach ($risks as $risk) {
            if ($risk->atLeast($worst)) {
                $worst = $risk;
            }
        }

        return $worst;
    }

    private function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1,
            self::Moderate => 2,
            self::High => 3,
            self::Destructive => 4,
        };
    }
}
