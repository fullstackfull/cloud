<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Enums;

/**
 * How far along something is towards being usable.
 *
 * Deliberately NOT the same scale as REAL_INFRA_VERIFIED, and the gap between
 * them is the whole point of Phase 30B-P. Readiness is what the control plane
 * can determine about itself: are the credentials present, does the connection
 * work, has the capability been discovered. Verification is what a real
 * provider did when asked to do a real thing, and no amount of readiness adds
 * up to it.
 *
 * ReadyForProduction therefore means "nothing here is stopping you", not
 * "this has been proven".
 */
enum ReadinessState: string
{
    /** Something structural is missing. The blocker says what. */
    case NotReady = 'not_ready';

    /** Enough to look: an endpoint and a credential that can read. */
    case ReadyForDiscovery = 'ready_for_discovery';

    /** Enough to change configuration: a safety class that permits it and a credential that can write. */
    case ReadyForConfiguration = 'ready_for_configuration';

    /** Enough to exercise against a sandbox or test account. */
    case ReadyForTest = 'ready_for_test';

    /** Nothing in the control plane is blocking live use. Still not proof that anything works. */
    case ReadyForProduction = 'ready_for_production';

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::NotReady => 0,
            self::ReadyForDiscovery => 1,
            self::ReadyForConfiguration => 2,
            self::ReadyForTest => 3,
            self::ReadyForProduction => 4,
        };
    }
}
