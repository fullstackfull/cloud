<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Services;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Dedicated\Application\Actions\ChangeDedicatedServerPower;
use Lynomia\Modules\Dedicated\Application\Actions\ExpireAbandonedPowerClaims;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerOperationOutcome;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedPowerOperation;

/**
 * How long a power claim may wait for the process that made it, and what the
 * platform says about it afterwards.
 *
 * ===========================================================================
 * THE FAILURE THIS EXISTS TO END
 * ===========================================================================
 *
 * {@see ChangeDedicatedServerPower} commits its claim before it calls the
 * management controller — in its own transaction, deliberately, so that a
 * request which dies mid-call leaves evidence that the instruction may have
 * been sent. The only ways out of `claimed` were the settles that run after the
 * controller answers. A worker killed in between left the row `claimed` with
 * no timeout, no sweeper and no operator path out, and every later request
 * carrying that key was refused as "still in flight" for the life of the row:
 * the caller who repeated the key they were told to repeat was refused for
 * ever.
 *
 * ===========================================================================
 * A LAPSED CLAIM IS SETTLED, NEVER RELEASED
 * ===========================================================================
 *
 * The obvious repair — expire the claim and let the next caller through — is
 * the one thing this may not do. The unique index on the key is what makes one
 * intent reach the chassis once, and a released claim is a second reset sent
 * to a machine the first one may still be acting on. So nothing here deletes a
 * row and nothing lets a second claim onto a key that already has one; the
 * unique index stays the only gate, and mis-tuning the lease cannot breach it.
 *
 * A claim past its lease becomes `indeterminate`, with the failure code
 * {@see self::ABANDONED}. That is not a consolation prize: it is the literal
 * truth about a crashed request — the instruction may or may not have reached
 * the controller, and nobody is coming back to say which — and it is a state
 * whose meaning the platform already writes down and enforces. Every replay of
 * the key is answered from the row, exactly as a controller timeout is. The key
 * stops being poisoned because it now has an answer, not because it was freed.
 * The precedent is `DetectStaleDeployments`, which marks a deployment that
 * outlived its worker indeterminate and restarts nothing.
 *
 * ===========================================================================
 * THREE WRITERS OF `outcome`, AND WHICH ONE IS NOT CONDITIONAL
 * ===========================================================================
 *
 *  1. {@see ExpireAbandonedPowerClaims}, the scheduled sweep, for claims nobody
 *     repeats.
 *  2. A repeat of the same key, in `ChangeDedicatedServerPower::claim()`, which
 *     finds the lapsed claim before the sweep does.
 *
 *  Both go through {@see self::settleAsAbandoned()}, one write that only lands
 *  on a row that is STILL `claimed` and says whether this call is the one that
 *  landed. So the two cannot disagree, and a process that settles the row
 *  between either reader's read and its write keeps its own verdict: a
 *  completed reboot is never turned into "we do not know" by a guess made a
 *  moment too late.
 *
 *  3. The owning process itself, in `ChangeDedicatedServerPower::settle()`,
 *     when the controller finally answers. That write is NOT conditional, on
 *     purpose. A controller that answers after the lease has lapsed has still
 *     answered, and its answer is a fact where the lease's was a guess;
 *     freezing the guess over the fact is the harm the conditional write
 *     exists to prevent, inverted. The cost is bounded: a repeat that arrived
 *     in between was told "indeterminate" about an operation that was in fact
 *     accepted — a wrong answer for a while, never a second instruction.
 *
 * The lease is configuration, `dedicated.power.claim_lease_minutes`, and its
 * size is argued against the controller timeouts in config/dedicated.php.
 */
final readonly class PowerClaimLease
{
    /** The failure code a claim settled by this lease carries. */
    public const string ABANDONED = 'dedicated.power_claim_abandoned';

    /**
     * A claim requested at or before this moment has outlived its lease.
     *
     * One definition, read by the sweep, by a repeat of the key and by the
     * metrics collector, so the three can never disagree about which claims
     * are abandoned.
     */
    public function lapsedAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMinutes((int) config('dedicated.power.claim_lease_minutes', 15));
    }

    public function hasLapsed(DedicatedPowerOperation $operation): bool
    {
        return $operation->outcome === PowerOperationOutcome::Claimed
            && $operation->requested_at->lessThanOrEqualTo($this->lapsedAt());
    }

    /**
     * Settle a lapsed claim as indeterminate, if it is still a claim.
     *
     * `UPDATE … WHERE id = ? AND outcome = 'claimed'`: the condition is the
     * whole point, and the row the caller holds is refreshed afterwards so it
     * says what the database now says — this call's answer, or the answer of
     * whichever writer got there first.
     *
     * @return bool whether this call is the one that settled it
     */
    public function settleAsAbandoned(DedicatedPowerOperation $operation): bool
    {
        $settled = DedicatedPowerOperation::query()
            ->whereKey($operation->getKey())
            ->where('outcome', PowerOperationOutcome::Claimed->value)
            ->update([
                'outcome' => PowerOperationOutcome::Indeterminate->value,
                'failure_code' => self::ABANDONED,
                'settled_at' => CarbonImmutable::now(),
            ]) === 1;

        $operation->refresh();

        return $settled;
    }
}
