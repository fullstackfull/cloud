<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Identity\Domain\Enums\CustomerCapability;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;

/**
 * One team role, and what the server will actually let it do.
 *
 * The capability list is computed from {@see CustomerRole::permissions()} — the
 * same list `AuthorisesWithinAccount` reads before every write — so the screen
 * that explains a role and the endpoint that refuses it are reading one fact.
 * A React table of roles and ticks would have been half the code and would
 * have started lying the first time a permission moved.
 *
 * What is published per capability is the customer-facing id *and* the
 * permission string. The id is what the portal translates; the permission is
 * the canonical machine value and the thing the API refuses with, so a
 * customer can check this claim against a 403 instead of taking it on trust.
 *
 * `assignable` is here rather than inferred from `is_owner`, because "you
 * cannot pick this one" and "this one owns the account" are different facts
 * that happen to coincide today.
 *
 * @mixin CustomerRole
 */
final class TeamRoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerRole $role */
        $role = $this->resource;

        return [
            'id' => $role->value,
            'is_owner' => $role === CustomerRole::Owner,
            'assignable' => in_array($role, CustomerRole::assignable(), strict: true),
            'capabilities' => self::capabilities($role),
        ];
    }

    /**
     * Every published capability, with whether this role holds it.
     *
     * The whole matrix rather than only what the role has: "can this role see
     * billing?" is a question with two useful answers, and a list of only the
     * yeses makes the reader compare five different lists to find the no.
     *
     * Built in a private method so the specification test reads this
     * resource's own fields rather than the keys of the objects nested inside
     * it.
     *
     * @return list<array<string, mixed>>
     */
    private static function capabilities(CustomerRole $role): array
    {
        return array_map(
            static fn (CustomerCapability $capability): array => [
                'id' => $capability->value,
                'permission' => $capability->permission(),
                'granted' => $capability->heldBy($role),
            ],
            CustomerCapability::cases(),
        );
    }
}
