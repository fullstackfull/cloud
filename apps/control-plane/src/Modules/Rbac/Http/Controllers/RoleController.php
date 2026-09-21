<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Application\Actions\SetRolePermissions;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role as RoleEnum;
use Lynomia\Modules\Rbac\Http\Requests\SetRolePermissionsRequest;
use Spatie\Permission\Models\Role;

/**
 * Roles, and what each of them may do.
 *
 * The catalogue was readable only by reading the source. Eleven permissions
 * were held by no role and there was no way to give them to one, which made
 * them permanently super-admin-only — defensible as a decision, and not
 * defensible as a thing nobody chose.
 */
final class RoleController
{
    public function index(): JsonResponse
    {
        $roles = Role::query()->with('permissions')->orderBy('name')->get();

        $holders = User::query()
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->selectRaw('roles.name as role_name, count(*) as total')
            ->groupBy('roles.name')
            ->pluck('total', 'role_name');

        return response()->json([
            'data' => $roles->map(static function (Role $role) use ($holders): array {
                $known = RoleEnum::tryFrom($role->name);

                return [
                    'name' => $role->name,
                    'label' => $known?->label() ?? $role->name,
                    'is_staff_role' => $known?->isStaffRole() ?? true,
                    /*
                     * Super Admin's authority comes from the Gate::before
                     * bypass and not from these rows, so the list is empty and
                     * editing it would change nothing while looking like it
                     * had. Published as a flag so the screen can say so rather
                     * than offering a control that refuses.
                     */
                    'permissions_are_editable' => $role->name !== RoleEnum::SuperAdmin->value,
                    'grants_everything' => $role->name === RoleEnum::SuperAdmin->value,
                    'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                    'operators' => (int) ($holders[$role->name] ?? 0),
                ];
            })->values()->all(),
        ]);
    }

    /**
     * The permissions an operator can choose from, with the group they belong
     * to, so that a screen can present fifty-nine of them as something other
     * than a list of strings.
     */
    public function permissions(): JsonResponse
    {
        return response()->json([
            'data' => array_map(static function (Permission $permission): array {
                $value = $permission->value;
                $group = str_contains($value, '.') ? explode('.', $value)[0] : 'platform';

                return [
                    'name' => $value,
                    'group' => $group,
                    'held_by_default_roles' => array_values(array_map(
                        static fn (RoleEnum $role): string => $role->value,
                        array_filter(
                            RoleEnum::cases(),
                            static fn (RoleEnum $role): bool => in_array($permission, $role->defaultPermissions(), true),
                        ),
                    )),
                ];
            }, Permission::cases()),
        ]);
    }

    public function updatePermissions(
        SetRolePermissionsRequest $request,
        SetRolePermissions $set,
        string $role,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $found = Role::query()->where('name', $role)->firstOrFail();

        $changed = $set->execute($actor, $found, $request->permissions());

        return response()->json([
            'data' => [
                'name' => $changed->name,
                'permissions' => $changed->permissions()->pluck('name')->sort()->values()->all(),
            ],
        ]);
    }

    public function show(Request $request, string $role): JsonResponse
    {
        $found = Role::query()->with('permissions')->where('name', $role)->firstOrFail();
        $known = RoleEnum::tryFrom($found->name);

        return response()->json([
            'data' => [
                'name' => $found->name,
                'label' => $known?->label() ?? $found->name,
                'permissions_are_editable' => $found->name !== RoleEnum::SuperAdmin->value,
                'grants_everything' => $found->name === RoleEnum::SuperAdmin->value,
                'permissions' => $found->permissions->pluck('name')->sort()->values()->all(),
            ],
        ]);
    }
}
