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

    /**
     * Paid for again, and not yet usable.
     *
     * The state that exists because "the payment succeeded" and "the machine
     * is back" are two different facts separated by a hypervisor call that can
     * fail. Marking such a service active would tell a customer their server
     * is running when it is still locked; leaving it suspended would tell them
     * they still owe money. Neither is true, so there is a third state, and it
     * is the one an operator screen filters on.
     */
    case Reactivating = 'reactivating';
    case Terminated = 'terminated';
    case Failed = 'failed';

    /** Whether the service will never change state again on its own. */
    public function isTerminal(): bool
    {
        return $this === self::Terminated;
    }

    /**
     * Whether the customer can currently use what they bought.
     *
     * Reactivating is deliberately not usable. The payment has landed and the
     * machine has not come back, and telling a customer it is available when
     * the hypervisor is still refusing them is the failure this state exists
     * to prevent.
     */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    /** Whether the service still holds infrastructure that costs money. */
    public function holdsResources(): bool
    {
        return match ($this) {
            self::Provisioning, self::Active, self::Suspended, self::Reactivating => true,
            self::Pending, self::Terminated, self::Failed => false,
        };
    }

    /**
     * Whether an operator needs to look at this service.
     *
     * Reactivating counts. A service that has been reactivating for more than
     * the few seconds a hypervisor call takes is a customer who has paid and
     * cannot use what they paid for, and nobody else is going to notice.
     */
    public function needsAttention(): bool
    {
        return $this === self::Failed || $this === self::Reactivating;
    }
}
