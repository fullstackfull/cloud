<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Application\Actions\ChangeOperatorRoles;
use Lynomia\Modules\Rbac\Application\Actions\InviteOperator;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Rbac\Http\Requests\ChangeOperatorRolesRequest;
use Lynomia\Modules\Rbac\Http\Requests\InviteOperatorRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * The people who operate the platform.
 *
 * Nothing here existed. `role.manage` was declared in the permission catalogue
 * and referenced by no route, so a deployment had exactly as many operators as
 * it was born with — and a production deployment was born with none, because
 * the only seeder that assigns anybody refuses to run in production.
 *
 * Staff only. A customer login holds the `customer` role and appears nowhere on
 * this surface: the list would otherwise grow to the size of the customer base
 * and turn an operator screen into an account directory.
 */
final class OperatorController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $operators = User::query()
            ->with('roles')
            ->whereHas('roles', static fn ($query) => $query->whereIn('name', ChangeOperatorRolesRequest::assignable()))
            ->when(
                $request->filled('q'),
                static fn ($query) => $query->where(static function ($inner) use ($request): void {
                    $term = '%'.$request->string('q')->value().'%';
                    $inner->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term);
                }),
            )
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paginated($operators, static fn (User $user): array => self::describe($user));
    }

    public function store(InviteOperatorRequest $request, InviteOperator $invite): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $invited = $invite->execute(
            $actor,
            $request->string('email')->value(),
            $request->string('name')->value(),
            $request->roles(),
        );

        $described = self::describe($invited->operator->load('roles'));

        if ($actor->hasRole(Role::SuperAdmin->value)) {
            /*
             * Whether the address already had a login — a customer account,
             * perhaps one somebody else registered — which was promoted, and
             * had every credential it held taken away, rather than a new
             * login created. A super admin may know that an address has a
             * login.
             */
            return response()->json(['data' => [
                ...$described,
                'promoted_existing_account' => $invited->promotedAnExistingLogin,
            ]], Response::HTTP_CREATED);
        }

        /*
         * Anybody else holding `role.manage` gets a response that does not
         * distinguish a promoted login from a new one: the flag is null, the
         * name is the one they supplied rather than the one the login holds
         * (a registrant's own choice, for a promoted one), and `created_at`
         * is null rather than the login's creation date. `has_signed_in` and
         * `two_factor_enabled` are false either way, because promotion clears
         * both; `id` differs as any two ids do. This is about this response
         * only: GET /api/admin/operators lists every operator's stored name
         * and creation date to the same people, a promoted one included.
         */
        return response()->json(['data' => [
            ...$described,
            'name' => $request->string('name')->value(),
            'created_at' => null,
            'promoted_existing_account' => null,
        ]], Response::HTTP_CREATED);
    }

    public function updateRoles(
        ChangeOperatorRolesRequest $request,
        ChangeOperatorRoles $change,
        string $operator,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $target = User::query()->findOrFail($operator);

        $changed = $change->execute($actor, $target, $request->roles());

        return response()->json(['data' => self::describe($changed->load('roles'))]);
    }

    /**
     * What an operator screen may show about another operator.
     *
     * No password state, no two-factor secret, no session or token detail, and
     * no customer memberships: this surface answers "who may operate the
     * platform and with what authority", and anything else about the person is
     * a different question with a different authorisation.
     *
     * @return array<string, mixed>
     */
    private static function describe(User $user): array
    {
        return [
            'id' => (string) $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames()->values()->all(),
            'is_privileged' => $user->hasRole(Role::SuperAdmin->value),
            // Whether the person has ever taken the account over. An invited
            // operator who never followed their link is the commonest reason
            // for "I added them and nothing happened".
            'has_signed_in' => $user->last_login_at !== null,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
