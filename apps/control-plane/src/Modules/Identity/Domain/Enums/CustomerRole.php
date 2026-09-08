<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

/**
 * A user's role *within* one customer account.
 *
 * This is distinct from platform roles (Super Admin, NOC, Finance, …), which
 * are granted globally through spatie/laravel-permission. A user can be the
 * owner of their own account and a mere member of a colleague's.
 *
 * Five tiers, and the two in the middle exist because the two questions a
 * hosting account asks — who may spend money, and who may touch the machines —
 * are asked of different people. A finance department that can pay an invoice
 * has no business rebuilding a server, and the engineer who rebuilds it has no
 * business changing the card the account pays with. `Billing` and `Technical`
 * are those two answers; giving one person both means making them an
 * administrator, deliberately, rather than by accumulation.
 *
 * `Member` is the read-only tier. It can see the account and ask for help, and
 * that is all: no machine changes, no money, no membership changes. It is the
 * safe default for somebody who needs to watch rather than act.
 */
enum CustomerRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Billing = 'billing';
    case Technical = 'technical';
    case Member = 'member';

    /**
     * @return list<string> permissions this role implies inside its customer
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [
                'customer.manage', 'customer.members.manage', 'customer.close',
                'billing.view', 'billing.pay', 'billing.methods.manage',
                'service.view', 'service.manage', 'service.destroy',
                'apikey.manage', 'support.manage',
            ],
            self::Administrator => [
                'customer.manage', 'customer.members.manage',
                'billing.view',
                'service.view', 'service.manage', 'service.destroy',
                'apikey.manage', 'support.manage',
            ],
            self::Billing => [
                'billing.view', 'billing.pay', 'billing.methods.manage',
                'service.view', 'support.manage',
            ],
            /*
             * Everything about the machines and nothing about the money.
             *
             * `service.destroy` is deliberately absent: ending a service is
             * the end of something the account is paying for, and a technical
             * contact who can rebuild a machine should still not be able to
             * make the account stop owning it.
             */
            self::Technical => [
                'service.view', 'service.manage',
                'apikey.manage', 'support.manage',
            ],
            self::Member => [
                'service.view', 'support.manage',
            ],
        };
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }

    /**
     * The roles a member of an account may be given.
     *
     * Owner is not among them, and that is the whole point of the list. An
     * account has exactly one owner — the person the platform holds
     * responsible for it — so ownership moves by transfer, which names both
     * the person losing it and the person gaining it in one act. Granting it
     * as a role would make two owners, and then no owner, the first time one
     * of them removed the other.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $role): bool => $role !== self::Owner,
        ));
    }

    /**
     * @return list<string>
     */
    public static function assignableValues(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::assignable());
    }
}
