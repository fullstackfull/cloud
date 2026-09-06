<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * Where one hosting account is in its life.
 *
 * Persisted in hosting_accounts.status. The distinction that carries the most
 * commercial weight is Suspended versus Terminated: a suspended account still
 * exists in full — files, mail, databases, DNS — and can be restored by a
 * single API call, while a terminated one has had all of that released. Most
 * suspensions are billing disputes that end with the customer paying, so
 * conflating the two turns a late invoice into a lost customer and a
 * liability.
 */
enum HostingAccountStatus: string
{
    /** The row exists and holds the node's capacity; the panel has not confirmed the account yet. */
    case Pending = 'pending';

    case Active = 'active';

    /** Service stopped, everything preserved, reversible. */
    case Suspended = 'suspended';

    /** Data released at the panel. There is nothing left to restore. */
    case Terminated = 'terminated';

    /** The create failed outright and nothing was left behind at the panel. */
    case Failed = 'failed';

    /**
     * Whether the account still occupies a slot and disk on its node.
     *
     * A suspended account counts: its files, mail and databases are all still
     * on the disk, which is exactly why suspension is reversible. Releasing
     * its capacity would let the scheduler oversubscribe the node by the
     * number of accounts waiting out a billing dispute.
     */
    public function occupiesNodeCapacity(): bool
    {
        return match ($this) {
            self::Pending, self::Active, self::Suspended => true,
            self::Terminated, self::Failed => false,
        };
    }

    /** Whether the panel is expected to still hold this account. */
    public function existsAtPanel(): bool
    {
        return $this !== self::Terminated && $this !== self::Failed;
    }

    public function isTerminal(): bool
    {
        return $this === self::Terminated || $this === self::Failed;
    }
}
