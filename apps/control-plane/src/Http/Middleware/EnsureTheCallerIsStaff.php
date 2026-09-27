<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff gate on /api/admin: a login that holds no staff role is refused,
 * whatever permissions it holds.
 *
 * `/api/admin` and `/api/v1` authenticate with the same guard, and until this
 * existed the permission named on each admin route was the only thing between
 * a customer session and the operator surface. That made the customer role's
 * permission list a single point of failure: the re-audit of round three
 * (OB-1) gave the customer role a delegate's permissions through
 * PUT /api/admin/roles/customer/permissions, and every customer login then
 * read GET /api/admin/operators and GET /api/admin/customers. The role route
 * now refuses that edit; this is the second layer, so a customer role that
 * came to hold an operator permission some other way — a seeder change, a SQL
 * client — still opens nothing here.
 *
 * "Staff" is Role::staffRoleNames(): every role the enum declares except
 * `customer`. A permission given directly to a login with no staff role is not
 * operator authority either. Nothing legitimate reaches /api/admin without a
 * staff role: `perf:token` mints a customer-role login for the customer API
 * and its harness requests no admin route, and customer impersonation is a
 * declared permission (`customer.impersonate`) with no route or action that
 * implements it.
 *
 * Refuses with 403 `auth.forbidden` — the same code a permission refusal
 * carries, so the response says nothing a permission refusal would not.
 *
 * Runs after `auth:sanctum`; a route test holds that order. Reached without a
 * user it refuses as unauthenticated rather than letting the request through,
 * so a mis-ordered group fails closed.
 */
final class EnsureTheCallerIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if (! $user->hasAnyRole(Role::staffRoleNames())) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
