<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\StateMachines;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Shared\Domain\Contracts\AbstractStateMachine;

/**
 * The physical reinstall lifecycle.
 *
 * Read the table as a statement about what an operator will find. There is no
 * edge back to any earlier state: a reinstall is attempted once and its record
 * says where it stopped. Asking again is a new operation with its own row,
 * because "this reinstall was retried three times" and "this machine was
 * erased three times" are different facts and only the second is true.
 *
 * The endings are reachable from different places on purpose.
 * `hardware_unavailable` is reachable only from the states before the power
 * cycle, because after it the controller having gone quiet is not "we could
 * not reach the machine" — it is "we started erasing a machine and then lost
 * sight of it", which is `indeterminate`. Those two demand opposite handling
 * and the table refuses to let one stand in for the other.
 *
 * @extends AbstractStateMachine<DedicatedReinstallState>
 */
final class DedicatedReinstallStateMachine extends AbstractStateMachine
{
    public function subject(): string
    {
        return 'DedicatedReinstall';
    }

    /**
     * @return array<string, list<DedicatedReinstallState>>
     */
    public function transitions(): array
    {
        return [
            DedicatedReinstallState::Requested->value => [
                DedicatedReinstallState::Queued,
                DedicatedReinstallState::Failed,
            ],

            DedicatedReinstallState::Queued->value => [
                DedicatedReinstallState::Validating,
                DedicatedReinstallState::Failed,
            ],

            DedicatedReinstallState::Validating->value => [
                DedicatedReinstallState::BmcConfiguring,
                // Nothing has been touched: a missing profile, a service that
                // is no longer active, a machine with no controller recorded.
                DedicatedReinstallState::Failed,
                DedicatedReinstallState::HardwareUnavailable,
            ],

            DedicatedReinstallState::BmcConfiguring->value => [
                DedicatedReinstallState::PxeBooting,
                // The override was refused or the controller did not answer.
                // The machine has not been asked to boot, so its disks are
                // intact and the customer can simply ask again.
                DedicatedReinstallState::Failed,
                DedicatedReinstallState::HardwareUnavailable,
                /*
                 * The controller stopped answering while the override was
                 * being written. The platform does not know whether it took —
                 * and an override that took and is never consumed will erase
                 * this machine at its next reboot, whenever that is. A person
                 * has to clear it.
                 */
                DedicatedReinstallState::Indeterminate,
            ],

            DedicatedReinstallState::PxeBooting->value => [
                DedicatedReinstallState::Installing,
                DedicatedReinstallState::NeedsReview,
                DedicatedReinstallState::Indeterminate,
            ],

            DedicatedReinstallState::Installing->value => [
                DedicatedReinstallState::Configuring,
                // The installer said it failed. The machine has been erased.
                DedicatedReinstallState::NeedsReview,
                // It never said anything.
                DedicatedReinstallState::ProvisioningTimeout,
                DedicatedReinstallState::Indeterminate,
            ],

            DedicatedReinstallState::Configuring->value => [
                DedicatedReinstallState::Verifying,
                DedicatedReinstallState::NeedsReview,
                DedicatedReinstallState::Indeterminate,
            ],

            DedicatedReinstallState::Verifying->value => [
                DedicatedReinstallState::Completed,
                // Installed, and the platform cannot confirm it came back.
                // Not a failure — the machine may be perfectly fine and slow —
                // and not a success either.
                DedicatedReinstallState::NeedsReview,
                DedicatedReinstallState::Indeterminate,
            ],

            // Terminal. Asking again is a new operation.
            DedicatedReinstallState::Completed->value => [],
            DedicatedReinstallState::Failed->value => [],
            DedicatedReinstallState::HardwareUnavailable->value => [],

            DedicatedReinstallState::ProvisioningTimeout->value => [
                // An operator found out what the machine did.
                DedicatedReinstallState::Completed,
                DedicatedReinstallState::NeedsReview,
                DedicatedReinstallState::Failed,
            ],

            DedicatedReinstallState::Indeterminate->value => [
                DedicatedReinstallState::Completed,
                DedicatedReinstallState::NeedsReview,
                DedicatedReinstallState::Failed,
            ],

            DedicatedReinstallState::NeedsReview->value => [
                DedicatedReinstallState::Completed,
                DedicatedReinstallState::Failed,
            ],
        ];
    }
}
