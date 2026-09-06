<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Contracts;

use BackedEnum;

/**
 * A finite state machine over a backed enum.
 *
 * Implementations declare the allowed transitions once. Nothing in the
 * application is permitted to write a status column directly; every change goes
 * through a transition so that it is validated and recorded.
 *
 * @template TState of BackedEnum
 */
interface StateMachine
{
    /**
     * Human-readable name of the thing being transitioned, used in errors.
     */
    public function subject(): string;

    /**
     * The complete transition table.
     *
     * @return array<string, list<TState>>  keyed by the source state's value
     */
    public function transitions(): array;

    /**
     * @param  TState  $from
     * @param  TState  $to
     */
    public function canTransition(BackedEnum $from, BackedEnum $to): bool;

    /**
     * @param  TState  $from
     * @param  TState  $to
     *
     * @throws \Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException
     */
    public function assertCanTransition(BackedEnum $from, BackedEnum $to): void;
}
