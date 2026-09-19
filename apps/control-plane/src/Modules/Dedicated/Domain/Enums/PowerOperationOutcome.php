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
    /** Claimed, and the controller has not answered yet. */
    case Claimed = 'claimed';

    /** The controller took the instruction. */
    case Accepted = 'accepted';

    /** The controller answered and declined. Nothing happened. */
    case Refused = 'refused';

    /**
     * The controller stopped answering. It may have accepted the reset, and
     * the platform does not know — which is the one outcome a replay must
     * never resolve by trying again.
     */
    case Indeterminate = 'indeterminate';

    /**
     * Whether a replay of this key may be answered from the row alone.
     *
     * Every settled outcome may: the caller asked one question and gets one
     * answer, however unwelcome. A row still `claimed` may not be resolved
     * from itself either — but for the opposite reason, and the caller is
     * told the first request is still in flight rather than handed a guess.
     */
    public function isSettled(): bool
    {
        return $this !== self::Claimed;
    }
}
