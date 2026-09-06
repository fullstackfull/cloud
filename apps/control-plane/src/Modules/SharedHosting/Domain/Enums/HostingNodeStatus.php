<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * Whether a shared hosting node may take new accounts.
 *
 * Only Active may. The other three are not degrees of undesirability that
 * scoring could weigh against a good disk figure — draining means an operator
 * is migrating accounts off this node, maintenance means the panel is being
 * upgraded and will restart the web and mail stacks under everybody on it,
 * offline means it is gone. An account placed on any of them has to be
 * migrated or rebuilt within the hour, and migrating a shared account means
 * moving mail, databases and DNS, so the scheduler excludes them before
 * scoring rather than penalising them.
 */
enum HostingNodeStatus: string
{
    case Active = 'active';
    case Draining = 'draining';
    case Maintenance = 'maintenance';
    case Offline = 'offline';

    public function acceptsNewAccounts(): bool
    {
        return $this === self::Active;
    }

    /** Whether accounts already here are expected to still be serving. */
    public function holdsAccounts(): bool
    {
        return $this !== self::Offline;
    }
}
