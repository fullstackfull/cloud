<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'phone' => $this->phone,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Platform permissions, so the SPA can hide affordances the user
            // cannot use. The server still authorises every request: this list
            // is a convenience, never the enforcement point.
            'permissions' => $this->effectivePermissions(),

            'customers' => CustomerResource::collection($this->whenLoaded('customers')),
        ];
    }

    /**
     * What this login may actually do, not what is written next to its name.
     *
     * Super Admin holds no permission rows at all: it is granted everything by
     * a Gate::before rule, deliberately, so that a permission added in a later
     * release is not silently missing from it. Reporting the rows verbatim
     * therefore told the portal that the platform's most privileged account
     * could do nothing, and the operator area — which shows itself to a login
     * holding any operator permission — hid itself from the one person who
     * certainly qualifies. The API would have allowed every request; the screens
     * were simply unreachable.
     *
     * Enumerated from the Permission enum rather than from the database, for
     * the same reason the Gate rule exists: a new permission is covered the
     * moment it is declared.
     *
     * @return list<string>
     */
    private function effectivePermissions(): array
    {
        if ($this->resource instanceof User && $this->resource->hasRole(Role::SuperAdmin->value)) {
            return array_map(
                static fn (Permission $permission): string => $permission->value,
                Permission::cases(),
            );
        }

        /** @var list<string> $granted */
        $granted = $this->getAllPermissions()->pluck('name')->all();

        return $granted;
    }
}
