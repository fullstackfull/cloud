<?php

declare(strict_types=1);

namespace Lynomia\Support\Provisioning;

use Lynomia\Modules\Provisioning\Domain\Contracts\DestructiveOperationLedger;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * Asks every ledger the platform has, and reports the first destruction any of
 * them knows about.
 *
 * Lives outside the modules for the same reason EveryReservationReleaser does:
 * this is wiring. The engine must not know that virtual machines and physical
 * servers are both rebuildable, and neither module may learn about the other.
 *
 * Unlike compensation, a ledger that throws is not swallowed. Compensation
 * runs after something has already gone wrong and doing most of it is better
 * than doing none; this answers the question "is it safe to destroy this
 * again", and an unanswerable question is a refusal, never a yes.
 */
final readonly class EveryDestructiveOperationLedger implements DestructiveOperationLedger
{
    /**
     * @param  list<DestructiveOperationLedger>  $ledgers
     */
    public function __construct(private array $ledgers) {}

    public function destructionState(ProvisioningJob $job): ?string
    {
        foreach ($this->ledgers as $ledger) {
            $state = $ledger->destructionState($job);

            if ($state !== null) {
                return $state;
            }
        }

        return null;
    }
}
