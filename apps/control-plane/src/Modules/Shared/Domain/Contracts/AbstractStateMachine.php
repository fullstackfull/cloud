<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Contracts;

use BackedEnum;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;

/**
 * @template TState of BackedEnum
 *
 * @implements StateMachine<TState>
 */
abstract class AbstractStateMachine implements StateMachine
{
    public function canTransition(BackedEnum $from, BackedEnum $to): bool
    {
        if ($from === $to) {
            // A no-op transition is not an error, but it is also not a change.
            // Callers use this to make retries safe.
            return true;
        }

        foreach ($this->transitions()[(string) $from->value] ?? [] as $allowed) {
            if ($allowed === $to) {
                return true;
            }
        }

        return false;
    }

    public function assertCanTransition(BackedEnum $from, BackedEnum $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw IllegalStateTransitionException::between($this->subject(), $from, $to);
        }
    }

    /**
     * States reachable from the given state, for UI affordances and tests.
     *
     * @param  TState  $from
     * @return list<TState>
     */
    public function reachableFrom(BackedEnum $from): array
    {
        return $this->transitions()[(string) $from->value] ?? [];
    }
}
