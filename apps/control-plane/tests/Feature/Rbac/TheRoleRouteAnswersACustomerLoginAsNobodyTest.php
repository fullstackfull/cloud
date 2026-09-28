<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Application\Actions\ChangeOperatorRoles;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PUT /api/admin/operators/{id}/roles answers a login that holds no staff
 * role exactly as it answers an id that does not exist (B8-2, re-audit after
 * round seven).
 *
 * Measured at af2bba2: `roles: []` on a customer login answered 200 with the
 * customer's name, address and creation date — to a delegate holding
 * `role.manage` as much as to a super admin — and recorded an
 * OperatorRolesChanged entry for a change that changed nothing. Any other
 * set answered 422 `rbac.not_an_operator`, and a role the delegate does not
 * hold 422 `rbac.role_not_yours_to_grant`, where an unknown id answers 404:
 * either way the route told its caller that the id was a login.
 * OperatorController says a customer login "appears nowhere on this surface".
 */
final class TheRoleRouteAnswersACustomerLoginAsNobodyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function asksOfACustomerLogin(): array
    {
        $asks = [
            'no roles' => ['roles' => []],
            'the delegate\'s own role' => ['roles' => [Role::Noc->value]],
            'a role the delegate does not hold' => ['roles' => [Role::SuperAdmin->value]],
            'the customer role, which the request refuses' => ['roles' => [Role::Customer->value]],
            'no roles key at all' => [],
        ];

        $cases = [];

        foreach (['a super admin' => 'super', 'a delegate' => 'delegate'] as $who => $actor) {
            foreach ($asks as $what => $body) {
                $cases["{$who}, {$what}"] = [$actor, $body];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[DataProvider('asksOfACustomerLogin')]
    public function a_customer_login_is_answered_as_an_id_that_does_not_exist(string $actor, array $body): void
    {
        $caller = $this->caller($actor);

        $customer = User::factory()->create(['name' => 'Customer Real Name', 'email' => 'customer@lynomia.test']);
        $customer->syncRoles([Role::Customer->value]);
        $noRoles = User::factory()->create(['email' => 'no-roles@lynomia.test']);

        $unknown = $this->actingAs($caller)->putJson('/api/admin/operators/'.Str::lower((string) Str::ulid()).'/roles', $body);

        foreach ([$customer, $noRoles] as $login) {
            $answer = $this->actingAs($caller)->putJson('/api/admin/operators/'.$login->id.'/roles', $body);

            $this->assertSame($unknown->status(), $answer->status());
            $this->assertSame($this->withoutRequestId($unknown), $this->withoutRequestId($answer));
            $this->assertStringNotContainsString((string) $login->email, (string) $answer->getContent());
        }

        $this->assertSame(0, AuditEntry::query()->count(), 'A refused role change was recorded.');
        $this->assertSame([Role::Customer->value], $customer->fresh()?->getRoleNames()->all());
        $this->assertSame([], $noRoles->fresh()?->getRoleNames()->all());

        // What an unknown id is answered: 404 for any set of staff roles, and
        // the request's own refusal of a body it refuses for any id.
        if (array_key_exists('roles', $body) && $body['roles'] !== [Role::Customer->value]) {
            $unknown->assertNotFound()->assertJsonPath('error.code', 'resource.not_found');
        } else {
            $unknown->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        }
    }

    /**
     * The action answers the same when it is reached another way, and asks
     * under the row lock: an operator whose staff roles were taken away
     * after the controller looked is a customer login by the time the change
     * would be made.
     */
    #[Test]
    public function the_action_answers_a_login_with_no_staff_role_as_one_that_does_not_exist(): void
    {
        $admin = $this->caller('super');
        $customer = User::factory()->create();
        $customer->syncRoles([Role::Customer->value]);

        foreach ([[], [Role::Noc->value]] as $roles) {
            try {
                app(ChangeOperatorRoles::class)->execute($admin, $customer, $roles);
                $this->fail('A login with no staff role was changed: roles '.json_encode($roles));
            } catch (ModelNotFoundException $e) {
                $this->assertSame(User::class, $e->getModel());
            }
        }

        $this->assertSame(0, AuditEntry::query()->count());
        $this->assertSame([Role::Customer->value], $customer->fresh()?->getRoleNames()->all());
    }

    /**
     * Positive control: an operator's roles still change through the route,
     * `roles: []` included, and the answer describes it.
     */
    #[Test]
    public function an_operators_roles_still_change(): void
    {
        $admin = $this->caller('super');
        $operator = User::factory()->create();
        $operator->syncRoles([Role::Customer->value, Role::Noc->value]);

        $this->actingAs($admin)
            ->putJson('/api/admin/operators/'.$operator->id.'/roles', ['roles' => []])
            ->assertOk()
            ->assertJsonPath('data.email', $operator->email)
            ->assertJsonPath('data.roles', []);

        $this->assertSame(1, AuditEntry::query()->count());
    }

    private function caller(string $actor): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        if ($actor === 'super') {
            $user->syncRoles([Role::SuperAdmin->value]);

            return $user;
        }

        $user->syncRoles([Role::Noc->value]);
        $user->givePermissionTo(Permission::RoleManage->value);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutRequestId(TestResponse $response): array
    {
        /** @var array<string, mixed> $body */
        $body = $response->json();
        unset($body['error']['request_id']);

        return $body;
    }
}
