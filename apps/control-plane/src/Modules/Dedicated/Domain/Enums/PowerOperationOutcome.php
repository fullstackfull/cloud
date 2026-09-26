<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

/**
 * What became of one power request.
 *
 * Four values rather than three, because "claimed" is a real state and not a
 * missing one: the row exists from before the controller is called, so a
 * process that dies mid-call leaves evidence that the request was sent. A
 * replay of that key must not assume nothing happened.
 */
enum PowerOperationOutcome: string
{
    /**
     * Claimed, and the controller has not answered yet.
     *
     * Not a state to stay in. A claim whose process died before the answer
     * is settled as `indeterminate` once its lease lapses — by the scheduled
     * sweep, or by the next repeat of its key — and is never released for a
     * second attempt.
     */
    case Claimed = 'claimed';

    /** The controller took the instruction. */
    case Accepted = 'accepted';

    /** The controller answered and declined. Nothing happened. */
    case Refused = 'refused';

    /**
     * The controller stopped answering. It may have accepted the reset, and
     * the platform does not know — which is the one outcome a replay must
     * never resolve by trying again.
     *
     * Also what a claim becomes when the process that made it died before the
     * controller answered, with `dedicated.power_claim_abandoned` as its
     * failure code: the instruction may or may not have been sent, which is
     * the same fact reached a different way.
     */
    case Indeterminate = 'indeterminate';

    /**
     * Whether a replay of this key may be answered from the row alone.
     *
     * Every settled outcome may: the caller asked one question and gets one
     * answer, however unwelcome. A row still `claimed` may not be resolved
     * from itself either — but for the opposite reason, and the caller is
     * told the first request is still in flight rather than handed a guess,
     * until the claim's lease lapses and it is settled as indeterminate.
     */
    public function isSettled(): bool
    {
        return $this !== self::Claimed;
    }
}
