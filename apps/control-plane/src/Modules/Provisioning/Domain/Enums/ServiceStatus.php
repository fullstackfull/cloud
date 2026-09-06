<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * The lifecycle of the generic, provider-agnostic service a customer bought.
 *
 * Billing and support read this column; nothing here knows what a hypervisor
 * is, which is what lets a service move between providers without touching an
 * invoice.
 */
enum ServiceStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case Failed = 'failed';

    /** Whether the service will never change state again on its own. */
    public function isTerminal(): bool
    {
        return $this === self::Terminated;
    }

    /** Whether the customer can currently use what they bought. */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    /** Whether the service still holds infrastructure that costs money. */
    public function holdsResources(): bool
    {
        return match ($this) {
            self::Provisioning, self::Active, self::Suspended => true,
            self::Pending, self::Terminated, self::Failed => false,
        };
    }

    /** Whether an operator needs to look at this service. */
    public function needsAttention(): bool
    {
        return $this === self::Failed;
    }
}
