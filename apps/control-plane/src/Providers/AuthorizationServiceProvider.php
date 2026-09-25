<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
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

        $this->authoriseTheQueueDashboard();
    }

    /**
     * Who may use the Horizon dashboard: an operator holding the capability,
     * in every environment alike.
     *
     * ---------------------------------------------------------------------
     * What this replaces
     * ---------------------------------------------------------------------
     *
     * Nothing called `Horizon::auth()`, so `Horizon::check()` fell back to the
     * package default, `app()->environment('local')`, and that one string
     * comparison was the whole gate on Horizon's 22 routes. It failed closed
     * in production, but it delegated authorisation to the value of `APP_ENV`:
     * any host labelled `local` served every job payload and failed-job stack
     * trace to a visitor nobody had signed in, a signed-in operator with no
     * queue permission got the same access as one with all of them, and this
     * was the only operator surface in the platform gated by environment name
     * rather than by capability.
     *
     * No environment name appears below, and none may be added. The exact-
     * string comparison is the same instrument as the miscapitalised
     * `APP_ENV=Production` that once disarmed every production guard at once;
     * a closure that tests the environment reproduces that class rather than
     * leaving it. Staging is therefore reachable by whoever holds the
     * capability on the staging installation — not by a carve-out, but because
     * the environment is not consulted at all. The operator-facing account of
     * this, including how to obtain a session, is in
     * `docs/runbooks/queue-backlog.md`.
     *
     * ---------------------------------------------------------------------
     * Why here
     * ---------------------------------------------------------------------
     *
     * `bootstrap/providers.php` already loads this provider, whereas a
     * published `HorizonServiceProvider` is one more file that must be
     * remembered in that list. And the callback depends on the `Gate::before`
     * above: Super Admin holds no permission rows and is admitted only by that
     * bypass. That is a dependency on the bypass being registered, not on the
     * order of the two calls inside `boot()` — the callback is consulted per
     * request, long after both have run.
     *
     * ---------------------------------------------------------------------
     * The two capabilities, and how a route is classified
     * ---------------------------------------------------------------------
     *
     * Reading needs `provisioning.view`. Horizon's job list is the question
     * `GET /api/admin/provisioning/jobs` already answers with that permission,
     * asked at a second surface — and the failed-job view renders serialised
     * payloads (identifiers, amounts and verbatim decline reasons from the
     * payments queue), so `monitoring.view` would hand them to Network
     * Engineer, which holds no commercial permission at all. Across the seeded
     * roles this is narrower than `monitoring.view`; an installation that has
     * composed a role holding `provisioning.view` without `monitoring.view`
     * sees it the other way round.
     *
     * Writing — starting or stopping a tag monitor, retrying a job or a batch —
     * needs `provisioning.retry`, the permission the admin API's own retry
     * route requires.
     *
     * The split is by verb (`isMethodSafe()`), not by route name, so a route a
     * future Horizon release adds is classified without anybody remembering to
     * list it. `OPTIONS` never actually reaches this closure: the router
     * answers it with an `Allow` header before any route middleware runs, so
     * its classification here is dead code and needs no "fix".
     *
     * ---------------------------------------------------------------------
     * An unverified address is refused
     * ---------------------------------------------------------------------
     *
     * The admin API carries the `verified` middleware on its whole group;
     * this surface is reached through Horizon's own route group, which does
     * not. And the platform's own path to an operator — `InviteOperator` —
     * promotes an existing login and sets `email_verified_at` only for a new
     * one, so a self-registered customer who never verified keeps it null
     * after being made NOC or Support. Without this clause that account would
     * be refused `/api/admin/provisioning/jobs` and handed every job payload
     * here. The clause lives inside this callback, not in the `Gate::before`
     * above, which would make verification a precondition for every
     * capability in the product rather than for this dashboard.
     */
    private function authoriseTheQueueDashboard(): void
    {
        Horizon::auth(static function (Request $request): bool {
            $user = $request->user();

            if (! $user instanceof User) {
                return false;
            }

            if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
                return false;
            }

            $capability = $request->isMethodSafe()
                ? Permission::ProvisioningView
                : Permission::ProvisioningRetry;

            return $user->can($capability->value);
        });
    }
}
