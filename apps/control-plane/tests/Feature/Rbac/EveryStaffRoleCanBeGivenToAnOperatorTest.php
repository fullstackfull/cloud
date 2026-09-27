<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\EveryEnumCaseHasAProducerTest;
use Tests\TestCase;

/**
 * Every staff role is one an operator can actually be given, through both
 * routes that give one: the invitation (`POST /api/admin/operators`) and the
 * role change (`PUT /api/admin/operators/{operator}/roles`).
 *
 * This is what holds the staff cases of {@see Role} as produced. Nothing in
 * production code names them one by one where a role is granted: the two
 * requests validate against `ChangeOperatorRolesRequest::assignable()`, which
 * walks `Role::cases()` through `isStaffRole()`, and the actions hand the
 * names to `syncRoles`. So {@see EveryEnumCaseHasAProducerTest} excuses the
 * six as `spelled` by that one shared walk, and a source-reading gate cannot
 * tell whether the walk still admits any particular case: narrowing
 * `assignable()` to leave out `billing-admin` keeps the spelling and kept the
 * gate green. This test goes red on it, for whichever case is left out.
 *
 * The data set is every {@see Role} case whose `isStaffRole()` is true, read
 * from the enum when the test runs, so a staff role added to the enum is
 * asked about too. What is asserted is that the route accepts the role
 * (201 / 200) and the operator afterwards holds it; not which other roles
 * they hold, nor how the role change treats the ones it replaces.
 */
final class EveryStaffRoleCanBeGivenToAnOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return iterable<string, array{Role}>
     */
    public static function staffRoles(): iterable
    {
        foreach (Role::cases() as $role) {
            if ($role->isStaffRole()) {
                yield $role->value => [$role];
            }
        }
    }

    #[Test]
    public function the_data_set_is_every_staff_role(): void
    {
        $this->assertSame(
            ['super-admin', 'infrastructure-admin', 'billing-admin', 'support', 'network-engineer', 'noc', 'finance'],
            array_keys(iterator_to_array(self::staffRoles())),
            'The staff roles changed; this list is the check that the data set is read from the enum, not the answer to keep in step.',
        );
    }

    #[Test]
    #[DataProvider('staffRoles')]
    public function an_operator_can_be_invited_with_it(Role $role): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/admin/operators', [
                'email' => 'invited-'.$role->value.'@lynomia.test',
                'name' => 'Invited Operator',
                'roles' => [$role->value],
            ])
            ->assertCreated();

        $invited = User::query()->where('email', 'invited-'.$role->value.'@lynomia.test')->firstOrFail();

        $this->assertTrue($invited->hasRole($role->value), "The invitation was accepted and the operator does not hold {$role->value}.");
    }

    #[Test]
    #[DataProvider('staffRoles')]
    public function an_operator_can_be_given_it_by_a_role_change(Role $role): void
    {
        $target = User::factory()->create();
        $target->syncRoles([($role === Role::Support ? Role::Noc : Role::Support)->value]);

        $this->actingAs($this->superAdmin())
            ->putJson('/api/admin/operators/'.$target->id.'/roles', ['roles' => [$role->value]])
            ->assertOk();

        $this->assertTrue($target->fresh()?->hasRole($role->value), "The role change was accepted and the operator does not hold {$role->value}.");
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }
}
