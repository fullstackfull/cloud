<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Services;

use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\SafetyRefusal;

/**
 * The single place that decides whether a machine may be touched.
 *
 * ---------------------------------------------------------------------------
 * Why one function and not a rule in each action
 * ---------------------------------------------------------------------------
 *
 * Because a rule written in fourteen actions is a rule missing from the
 * fifteenth. The Ansible side of the estate learned this in Phase 30B: the
 * safety_gate role is the first role in all eleven playbooks, and everything
 * else in the tree can stop thinking about it.
 *
 * This is the same gate on the application side, and it is deliberately pure —
 * it takes the two facts and the action, and returns or throws. No models, no
 * container, no database. That makes it trivially testable against every
 * class-and-action pair, which is exactly how the Ansible one is tested, and
 * it means an architecture test can insist that anything reaching a machine
 * has called it.
 */
final readonly class SafetyGate
{
    /**
     * Refuse unless the machine's classification permits this action.
     *
     * @throws SafetyRefusal
     */
    public function assert(
        string $server,
        SafetyClass $classification,
        bool $allowReimage,
        InfrastructureAction $action,
    ): void {
        if (! $classification->permits($action)) {
            throw SafetyRefusal::classDoesNotPermit($server, $action, $classification);
        }

        // The second half, and the one a caller reasoning about the enum alone
        // would miss: a class that permits reimaging is necessary and not
        // sufficient.
        if ($action === InfrastructureAction::Reimage && ! $allowReimage) {
            throw SafetyRefusal::notClearedForReimage($server);
        }
    }

    /**
     * The same question without the exception, for a screen deciding whether
     * to offer a button.
     *
     * A refusal an operator meets as a disabled control with a reason beside it
     * is better than one they meet after pressing it — but this is a courtesy,
     * and `assert` is the enforcement. Nothing may rely on the button being
     * hidden.
     */
    public function permits(
        SafetyClass $classification,
        bool $allowReimage,
        InfrastructureAction $action,
    ): bool {
        if (! $classification->permits($action)) {
            return false;
        }

        return $action !== InfrastructureAction::Reimage || $allowReimage;
    }
}
