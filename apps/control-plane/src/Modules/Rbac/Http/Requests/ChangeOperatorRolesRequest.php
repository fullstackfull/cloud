<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Rbac\Domain\Enums\Role;

/**
 * The shape of a role change. Whether the person may make it is decided in the
 * action, where the actor, the target and everybody else are all in scope.
 */
final class ChangeOperatorRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array'],
            /*
             * Staff roles only, and against the enum rather than the roles
             * table. `customer` is a row in that table and is the baseline
             * every customer login holds; handing it out from the operator
             * surface would make "operator" and "customer" the same word.
             */
            'roles.*' => ['string', Rule::in(self::assignable())],
        ];
    }

    /**
     * @return list<string>
     */
    public static function assignable(): array
    {
        return array_values(array_map(
            static fn (Role $role): string => $role->value,
            array_filter(Role::cases(), static fn (Role $role): bool => $role->isStaffRole()),
        ));
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        /** @var list<string> $roles */
        $roles = array_values(array_unique($this->input('roles', [])));

        return $roles;
    }
}
