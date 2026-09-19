<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

/**
 * What one preflight check found.
 *
 * Six, and each is a different thing for a person to do next. "OK" and "BAD"
 * are absent on purpose: the whole value of a preflight is telling apart the
 * four different ways a check can not-pass, because they send an operator to
 * four different places.
 */
enum CheckStatus: string
{
    /** The check ran and what it asked about is in order. */
    case Pass = 'pass';

    /**
     * The check ran and found the thing it was looking for missing or wrong,
     * and it is ours to fix — a mapping, a configuration, a reference.
     */
    case Fail = 'fail';

    /**
     * The check ran and cannot proceed until something outside this platform
     * changes: a credential nobody has issued, hardware nobody has racked, a
     * network nobody has opened, a licence nobody has bought.
     *
     * Reported with one of the five blocker reasons and nothing else.
     */
    case Blocked = 'blocked';

    /** Worth knowing, not worth stopping for. */
    case Warning = 'warning';

    /** The question does not apply here, which is a real answer. */
    case NotApplicable = 'not_applicable';

    /**
     * Nothing was established.
     *
     * Either a prerequisite did not pass — in which case running this check
     * would have produced a second, derivative failure and buried the real one
     * — or the mode does not permit it, or the provider's contract for this
     * question is not guaranteed read-only and preflight will not risk it.
     *
     * NOT_TESTED is never a pass. It is the honest shape of "we do not know",
     * and the whole reason it exists as a distinct case is that an absent
     * answer must not read like a good one.
     */
    case NotTested = 'not_tested';

    /** Does this stop the thing being preflighted from being used? */
    public function blocking(): bool
    {
        return $this === self::Fail || $this === self::Blocked;
    }

    /** Did this check actually establish anything? */
    public function established(): bool
    {
        return $this === self::Pass;
    }
}
