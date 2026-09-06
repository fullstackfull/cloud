<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * The outcome of asking a node for a slot, and who is allowed to give it back.
 *
 * The account row alone is not enough for a caller to reason about
 * compensation, and the missing fact is the one that decides whether releasing
 * capacity is safe:
 *
 *  - $slotTakenNow is true when THIS attempt incremented the node's count —
 *    either because it created the pending row, or because it re-committed a
 *    slot for a row an earlier attempt had already given back. Such an attempt
 *    is the only one that knows nothing has yet been created under that name,
 *    so it is the only one that may hand the slot back when the panel refuses;
 *
 *  - it is false when the reservation was already standing: a pending row left
 *    by an attempt that timed out, or an active account from a create that
 *    succeeded. Both mean an earlier attempt reached the panel, so a refusal
 *    now ("an account with this name already exists") says nothing about
 *    whether the node is holding one. Releasing on the strength of it would be
 *    releasing after a timeout by another route.
 *
 * @immutable
 */
final readonly class HostingCapacityReservation
{
    public function __construct(
        public HostingAccount $account,
        public bool $slotTakenNow,
    ) {}
}
