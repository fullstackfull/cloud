<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\StateMachines;

use Lynomia\Modules\Shared\Domain\Contracts\AbstractStateMachine;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;

/**
 * The reinstall lifecycle.
 *
 * Read the table as a promise about what an operator will find. There is no
 * edge from any state back to `preparing` or `reinstalling`: a reinstall is
 * attempted once and its record says where it stopped. Retrying is a new
 * operation with its own row, because "this reinstall was tried three times"
 * and "this machine was reinstalled three times" are different facts and only
 * the second one is true.
 *
 * The three unhappy endings are reachable from different places on purpose.
 * Everything can fail. Only the states at or past the destructive call can
 * reach `needs_review` or `indeterminate`, because before it there is nothing
 * ambiguous to review: nothing was touched.
 *
 * @extends AbstractStateMachine<ReinstallState>
 */
final class ReinstallStateMachine extends AbstractStateMachine
{
    public function subject(): string
    {
        return 'Reinstall';
    }

    /**
     * @return array<string, list<ReinstallState>>
     */
    public function transitions(): array
    {
        return [
            ReinstallState::Requested->value => [
                ReinstallState::Queued,
                // Refused between the confirmation and the dispatch: a guard
                // that fired, or a machine that went away.
                ReinstallState::Failed,
            ],

            ReinstallState::Queued->value => [
                ReinstallState::Preparing,
                ReinstallState::Failed,
            ],

            ReinstallState::Preparing->value => [
                ReinstallState::Reinstalling,
                // Nothing has been destroyed here, so a failure is simply a
                // failure: the customer still has the machine they had.
                ReinstallState::Failed,
            ],

            ReinstallState::Reinstalling->value => [
                ReinstallState::Configuring,
                // The disk is gone and the platform knows the provider
                // refused: a person decides what happens to the machine.
                ReinstallState::NeedsReview,
                // The platform stopped waiting. It does not know whether the
                // disk was replaced, so nothing may act on this automatically.
                ReinstallState::Indeterminate,
                ReinstallState::Failed,
            ],

            ReinstallState::Configuring->value => [
                ReinstallState::Verifying,
                ReinstallState::NeedsReview,
                ReinstallState::Indeterminate,
            ],

            ReinstallState::Verifying->value => [
                ReinstallState::Completed,
                // The machine came back as something other than what was
                // asked for. Not a failure — the customer has a working
                // server — but not a success either.
                ReinstallState::NeedsReview,
                ReinstallState::Indeterminate,
            ],

            // Terminal. A retry is a new operation.
            ReinstallState::Completed->value => [],
            ReinstallState::Failed->value => [],

            ReinstallState::NeedsReview->value => [
                // An operator's verdict, and the only way out of review.
                ReinstallState::Completed,
                ReinstallState::Failed,
            ],

            ReinstallState::Indeterminate->value => [
                // Reconciliation found out what the hypervisor did, or an
                // operator did. Either way the answer arrives from outside.
                ReinstallState::NeedsReview,
                ReinstallState::Completed,
                ReinstallState::Failed,
            ],
        ];
    }
}
