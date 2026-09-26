<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Lynomia\Modules\Dedicated\Application\Services\PowerClaimLease;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerOperationOutcome;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedPowerOperation;

/**
 * Settle every power claim whose worker is gone.
 *
 * A repeat of the same key settles a lapsed claim on its own — but only if the
 * customer comes back. This is the path for the claims nobody repeats: without
 * it they would stay `claimed` for ever, which is exactly the state the lease
 * exists to end. Each lapsed claim becomes `indeterminate`, through
 * {@see PowerClaimLease::settleAsAbandoned()}, and nothing is retried, released
 * or sent to a controller. {@see PowerClaimLease} says why.
 *
 * `lynomia_dedicated_power_claims_abandoned` counts the lapsed claims this has
 * not settled yet. This runs every five minutes, so a healthy sweep empties it
 * within minutes of each crash — which means a number that stays above zero is
 * one of two different things, and the number alone cannot say which. The
 * same old claims still there run after run mean this sweep is not running.
 * Different, recent claims each time mean workers keep dying mid-call while
 * this sweep keeps up. The runbook, docs/runbooks/dedicated-power.md, tells
 * the two apart.
 */
final readonly class ExpireAbandonedPowerClaims
{
    public function __construct(
        private PowerClaimLease $lease,
    ) {}

    /**
     * @return array{examined: int, settled: int}
     */
    public function execute(): array
    {
        $lapsed = DedicatedPowerOperation::query()
            ->where('outcome', PowerOperationOutcome::Claimed->value)
            ->where('requested_at', '<=', $this->lease->lapsedAt())
            ->orderBy('requested_at')
            ->get();

        $settled = 0;

        foreach ($lapsed as $operation) {
            /*
             * Conditional, and counted only when this call is the writer. A
             * row that was selected as a claim may have been settled since —
             * by its own process coming back with a real answer, or by a
             * repeat of its key — and that answer stands.
             */
            if ($this->lease->settleAsAbandoned($operation)) {
                $settled++;
            }
        }

        return ['examined' => $lapsed->count(), 'settled' => $settled];
    }
}
