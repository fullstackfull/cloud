<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Super Admin bypasses individual permission checks.
         *
         * Returning null (rather than false) for everyone else is essential:
         * false here would short-circuit every other gate and policy in the
         * application. Only an explicit true grants.
         */
        Gate::before(static function (Authenticatable $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            return $user->hasRole(Role::SuperAdmin->value) ? true : null;
        });
    }
}
