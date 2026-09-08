<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Enums;

/**
 * The four contact roles a registry recognises.
 *
 * Registrant is the one that matters legally — it is who owns the name, and
 * changing it is a transfer of property that some registries charge for and
 * lock the domain after. The other three are operational.
 *
 * All four hold personal data. Nothing here reaches a log, a metric label or
 * an audit context; see the contacts table for what that means at rest.
 */
enum DomainContactRole: string
{
    case Registrant = 'registrant';
    case Administrative = 'administrative';
    case Technical = 'technical';
    case Billing = 'billing';

    /**
     * Whether changing this role is a change of ownership rather than a
     * change of paperwork.
     *
     * Only the registrant. Registries treat a registrant change as a transfer
     * of the name, which is why it is not offered beside the other three as
     * though it were the same kind of edit.
     */
    public function isOwnership(): bool
    {
        return $this === self::Registrant;
    }
}
