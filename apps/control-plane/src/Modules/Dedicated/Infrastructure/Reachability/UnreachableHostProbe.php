<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Reachability;

use Lynomia\Modules\Dedicated\Domain\Contracts\HostReachability;

/**
 * A probe that never answers, for tests and for environments with no route to
 * customer machines.
 *
 * It exists so a test can assert what the platform does when a rebuilt machine
 * does not come back — which is the branch that decides whether a customer is
 * told their server is ready when it is not.
 */
final class UnreachableHostProbe implements HostReachability
{
    public function answers(string $host, int $port, float $timeoutSeconds = 5.0): bool
    {
        return false;
    }
}
