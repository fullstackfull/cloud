<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;

final class SetRolePermissionsRequest extends FormRequest
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
            'permissions' => ['present', 'array'],
            // Against the enum, not the table: a permission row that the
            // catalogue no longer declares is one nothing checks, and granting
            // it would look like authority and be none.
            'permissions.*' => [
                'string',
                Rule::in(array_map(static fn (Permission $p): string => $p->value, Permission::cases())),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        /** @var list<string> $permissions */
        $permissions = array_values(array_unique($this->input('permissions', [])));

        return $permissions;
    }
}
