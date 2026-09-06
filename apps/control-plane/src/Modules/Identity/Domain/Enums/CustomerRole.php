<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

/**
 * A user's role *within* one customer account.
 *
 * This is distinct from platform roles (Super Admin, NOC, Finance, …), which
 * are granted globally through spatie/laravel-permission. A user can be the
 * owner of their own account and a mere member of a colleague's.
 */
enum CustomerRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Billing = 'billing';
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
            self::Member => [
                'service.view', 'support.manage',
            ],
        };
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), strict: true);
    }
}
