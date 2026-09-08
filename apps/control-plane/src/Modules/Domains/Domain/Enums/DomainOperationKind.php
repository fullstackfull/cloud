<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Enums;

/**
 * The four things that can be done to a name, and they cost four amounts.
 *
 * Not a single "purchase" with a variable price. A registry charges
 * differently for taking a name, keeping it, moving it and rescuing it, and
 * the last of those is a penalty that can be ten times the others. Collapsing
 * them would mean a customer quoted a renewal price for a redemption, and a
 * registry refusing the order at the last step.
 */
enum DomainOperationKind: string
{
    case Register = 'register';
    case Renew = 'renew';
    case Transfer = 'transfer';
    case Redeem = 'redeem';

    /**
     * Whether this operation, if it does not answer, may have succeeded
     * anyway — and therefore must never be retried on a guess.
     *
     * All four, and stating it as a method rather than a comment is the point:
     * anything that adds a fifth operation has to answer this question about
     * it. Every one of these spends a customer's money at a registry, and a
     * registry that took the request and lost the response has still taken the
     * money.
     */
    public function isUnsafeToRepeat(): bool
    {
        return true;
    }

    /**
     * Whether the domain has to already exist here for this to make sense.
     *
     * Registration and transfer bring a name in; the other two act on one the
     * platform already holds. It decides whether an operation may be created
     * with a null domain_id.
     */
    public function actsOnAHeldDomain(): bool
    {
        return match ($this) {
            self::Renew, self::Redeem => true,
            self::Register, self::Transfer => false,
        };
    }
}
