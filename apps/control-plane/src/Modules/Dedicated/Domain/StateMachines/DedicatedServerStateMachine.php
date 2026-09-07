<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\StateMachines;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Shared\Domain\Contracts\AbstractStateMachine;

/**
 * The life of one physical machine.
 *
 * The table is the whole specification, and three of its properties are load
 * bearing:
 *
 *  - **`retired` is terminal.** Nothing leaves it, and nothing overwrites the
 *    row. "What happened to my old server" and "where did this serial number
 *    go" are questions only an unoverwritten row can answer, so decommissioning
 *    is a state and never a delete. It is also why the otherwise universal
 *    "anything may fail" rule stops here: a retired machine that could be
 *    marked failed would be a retired machine that could then be cleared back
 *    to available and sold again.
 *
 *  - **A hold is releasable.** `reserved → available` exists because an order
 *    cancelled before installation must return its machine to stock. Without
 *    it every cancellation would permanently shrink inventory, and the shrink
 *    would be invisible: the machine would look sold with nobody to bill.
 *
 *  - **A used machine does not go straight back to stock.** `active` cannot
 *    reach `available` directly; it goes through `maintenance`, because the
 *    disks still hold a customer's data. The step that erases them is the step
 *    this transition forces somebody to take.
 *
 * @extends AbstractStateMachine<DedicatedServerStatus>
 */
final class DedicatedServerStateMachine extends AbstractStateMachine
{
    public function subject(): string
    {
        return 'Dedicated server';
    }

    /**
     * @return array<string, list<DedicatedServerStatus>>
     */
    public function transitions(): array
    {
        return [
            DedicatedServerStatus::Available->value => [
                DedicatedServerStatus::Reserved,
                DedicatedServerStatus::Failed,
                DedicatedServerStatus::Retired,
            ],

            DedicatedServerStatus::Reserved->value => [
                DedicatedServerStatus::Provisioning,
                // The hold is released: a cancelled or expired order puts the
                // machine back on the shelf rather than stranding it.
                DedicatedServerStatus::Available,
                DedicatedServerStatus::Failed,
                DedicatedServerStatus::Retired,
            ],

            DedicatedServerStatus::Provisioning->value => [
                DedicatedServerStatus::Active,
                // An install that did not finish leaves a machine in an unknown
                // condition — half-partitioned, possibly still running an
                // installer. It goes to failed and waits for a person; it does
                // NOT go back to available, because the next customer would be
                // handed a machine carrying the previous install's remains.
                DedicatedServerStatus::Failed,
                DedicatedServerStatus::Retired,
            ],

            DedicatedServerStatus::Active->value => [
                // The customer asked for their own machine to be rebuilt. It
                // stays theirs throughout, which is why this is not a return
                // to `provisioning`.
                DedicatedServerStatus::Reinstalling,
                DedicatedServerStatus::Maintenance,
                DedicatedServerStatus::Failed,
                DedicatedServerStatus::Retired,
            ],

            DedicatedServerStatus::Reinstalling->value => [
                DedicatedServerStatus::Active,
                /*
                 * Not to `available`, ever, and for the same reason
                 * `provisioning` has no such edge: a machine whose rebuild
                 * went wrong may be carrying a half-written filesystem, and
                 * the one irreversible mistake available here is handing it to
                 * the next customer. An operator clears it through `failed` or
                 * `maintenance`, which is the step that makes somebody look at
                 * the disks.
                 */
                DedicatedServerStatus::Maintenance,
                DedicatedServerStatus::Failed,
                DedicatedServerStatus::Retired,
            ],

            DedicatedServerStatus::Maintenance->value => [
                // Back to the same customer's service, unchanged.
                DedicatedServerStatus::Active,
                // Or back to stock, which is the path a decommissioned service
                // takes once the disks have been erased.
                DedicatedServerStatus::Available,
                DedicatedServerStatus::Failed,
                DedicatedServerStatus::Retired,
            ],

            DedicatedServerStatus::Failed->value => [
                // Only an operator clears a failure. Nothing automatic reaches
                // this transition: a machine that failed because of a dead disk
                // is not fixed by a job noticing it answers pings again.
                DedicatedServerStatus::Available,
                DedicatedServerStatus::Retired,
            ],

            // Terminal. A replacement is a new machine with its own row and its
            // own provisioning cycle, not an edit to this one — the old
            // machine's history stays attached to the old machine.
            DedicatedServerStatus::Retired->value => [],
        ];
    }
}
