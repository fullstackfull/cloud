<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A change to who may operate the platform that the platform will not make.
 *
 * Each of these is an escalation path if it is not refused, so they are
 * modelled as distinct codes rather than one "forbidden": an operator who is
 * told "that is not yours to grant" can act on it, and an operator who is told
 * "you would be the last administrator" is being stopped from something quite
 * different.
 *
 * The context carries role names and nothing else. Role names are already
 * visible to anybody who may reach this surface, and everything else about the
 * decision — who asked, what the set was before — belongs in the audit trail
 * rather than in a response body.
 */
final class RoleChangeRefusedException extends DomainException
{
    private string $errorCode = 'rbac.refused';

    /**
     * An operator editing their own authority is the shortest escalation there
     * is: grant, use, revoke. Somebody else does it, or it does not happen.
     */
    public static function becauseItIsYourOwnAccount(): self
    {
        $exception = new self('You cannot change your own roles. Ask another operator.');

        return $exception->as('rbac.self_management');
    }

    /**
     * Authority is passed on, never invented. An operator who may manage roles
     * is not thereby able to create a power they do not have — otherwise
     * `role.manage` silently means "everything", through an account they make.
     */
    public static function becauseTheRoleIsNotYoursToGrant(string $role): self
    {
        $exception = new self('You cannot grant a role you do not hold yourself.');
        $exception->withContext(['role' => $role]);

        return $exception->as('rbac.role_not_yours_to_grant');
    }

    /**
     * The mirror of the grant rule: authority is not taken away by somebody
     * who does not hold it either. Otherwise a delegate holding `role.manage`
     * demotes a super admin simply by naming a smaller set.
     */
    public static function becauseTheRoleIsNotYoursToRemove(string $role): self
    {
        $exception = new self('You cannot remove a role you do not hold yourself.');
        $exception->withContext(['role' => $role]);

        return $exception->as('rbac.role_not_yours_to_remove');
    }

    /**
     * The same rule for a role's permission list: the list is replaced as a
     * whole, so checking only what the new list adds let a delegate empty a
     * role of permissions they could never have granted.
     */
    public static function becauseThePermissionIsNotYoursToRemove(string $permission): self
    {
        $exception = new self('You cannot remove a permission you do not hold yourself.');
        $exception->withContext(['permission' => $permission]);

        return $exception->as('rbac.permission_not_yours_to_remove');
    }

    /**
     * Super Admin is granted by a Gate::before bypass, not by the permission
     * rows attached to it. Letting somebody edit that list would let them
     * believe they had restricted it.
     */
    public static function becauseTheRoleIsProtected(string $role): self
    {
        $exception = new self('That role is defined by the platform and its permissions cannot be edited.');
        $exception->withContext(['role' => $role]);

        return $exception->as('rbac.role_is_protected');
    }

    /**
     * `customer` is the baseline every customer login holds, so its list is
     * a grant to the whole customer base at once, including logins that do
     * not exist yet. It is fixed by the platform (Role::defaultPermissions()
     * and the seeder) and edited by nobody at runtime, super admin included.
     */
    public static function becauseItIsTheCustomerBaseline(string $role): self
    {
        $exception = new self(
            'The customer role is held by every customer login; its permissions are set by the platform and cannot be edited here.'
        );
        $exception->withContext(['role' => $role]);

        return $exception->as('rbac.role_is_the_customer_baseline');
    }

    /**
     * The dead end this whole area exists to close, recreated from the inside:
     * a deployment with nobody able to administer it has no supported way back,
     * because the console bootstrap refuses once a privileged operator exists.
     */
    public static function becauseItWouldLeaveNobodyInCharge(): self
    {
        $exception = new self(
            'That would leave the platform with no administrator. Give the role to somebody else first.'
        );

        return $exception->as('rbac.last_administrator');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }
}
