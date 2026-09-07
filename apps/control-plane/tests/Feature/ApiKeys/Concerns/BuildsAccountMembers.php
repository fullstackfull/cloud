<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKeys\Concerns;

use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Fixtures shared by the token tests: a login that is a member of an account,
 * and a token bound to one.
 *
 * Memberships are created accepted, because an unaccepted invitation resolves
 * no acting customer at all — that path is Identity's to test, and a test here
 * that used it would be testing the middleware rather than this module.
 */
trait BuildsAccountMembers
{
    private function member(Customer $customer, ?User $user = null, CustomerRole $role = CustomerRole::Owner): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * A token row bound to one user and one account.
     *
     * Written directly rather than through the endpoint so a test can put a
     * token into a state the endpoint will not produce — already revoked, long
     * expired — without pretending the API did it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function tokenFor(User $user, Customer $customer, array $attributes = []): PersonalAccessToken
    {
        /** @var PersonalAccessToken $token */
        $token = $user->tokens()->make();

        $token->forceFill(array_merge([
            'customer_id' => $customer->getKey(),
            'name' => 'fixture',
            'token' => hash('sha256', 'fixture-'.uniqid('', true)),
            'abilities' => ['*'],
        ], $attributes))->save();

        return $token;
    }
}
