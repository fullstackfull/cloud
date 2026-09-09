<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Exceptions;

use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use RuntimeException;

/**
 * A change to what we may do to a machine was refused.
 *
 * Distinct from SafetyRefusal, which is "this classification does not permit
 * that action". This one is "you may not set that classification" — the
 * refusals that guard the act of raising permission rather than the act of
 * using it.
 */
final class ClassificationRefused extends RuntimeException
{
    public static function notOneRung(string $server, SafetyClass $from, SafetyClass $to): self
    {
        return new self(sprintf(
            '%s is %s and cannot become %s in one step. '
            .'A classification rises one rung at a time so that nothing skips the read-only look, '
            .'which is where somebody finds out what is on the disks.',
            $server,
            $from->value,
            $to->value,
        ));
    }

    public static function withoutTypingTheName(string $server): self
    {
        return new self(sprintf(
            'Type %s to confirm. A flag that is merely true is one the wrong browser tab can supply, '
            .'and this is the change that makes a wipe possible.',
            $server,
        ));
    }

    public static function withoutAReason(string $server): self
    {
        return new self(sprintf(
            'Changing what may be done to %s needs a reason. '
            .'"Why is this machine reimageable" is a question somebody asks three weeks later, '
            .'and an empty answer is how a temporary clearance becomes permanent.',
            $server,
        ));
    }

    public static function clearanceNeedsTheClass(string $server, SafetyClass $classification): self
    {
        return new self(sprintf(
            '%s is classified %s and cannot be cleared for a reimage. '
            .'Raise the classification first, deliberately; the clearance is for one scheduled piece of work, not for granting permission.',
            $server,
            $classification->value,
        ));
    }
}
