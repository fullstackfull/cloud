<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Identity\Infrastructure\Notifications\OperatorInvitation;
use Lynomia\Modules\Rbac\Application\Actions\InviteOperator;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An invitation whose address became an operator's after the request looked
 * is refused, not promoted (B8-1, re-audit after round seven).
 *
 * Measured at af2bba2: InviteOperatorRequest refused an operator's address
 * with a read outside any lock, and InviteOperator promoted whatever login it
 * found under its row lock. A second invitation that committed in between —
 * another super admin inviting the same person a moment earlier — was re-roled
 * through the creation endpoint: 201 `promoted_existing_account: true`, the
 * other invitation's roles replaced, every credential revoked and the reset
 * token the other invitation had just mailed deleted. A delegate holding
 * `role.manage` got the same 201 on an existing operator.
 *
 * ---------------------------------------------------------------------------
 * How the interleaving is made exact
 * ---------------------------------------------------------------------------
 *
 * Invitation B runs from inside a query listener, on the request's own
 * validation read of the address (the one select on `users` by email without
 * FOR UPDATE — InviteOperator::alreadyAnOperator()). So B has committed after
 * A's request refused nothing and before A's transaction reads the row. The
 * lock is not what is raced here — one connection cannot wait on itself — so
 * this pins the check under the lock; the advisory lock that makes two
 * connections take turns is pinned by TwoInvitationsOfOneAddressAtOnceMakeOneOperatorTest.
 */
final class AnInvitationThatLosesARaceDoesNotReRoleAnOperatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    #[Test]
    public function a_super_admins_invitation_is_refused_when_another_made_the_address_an_operators_first(): void
    {
        $a = $this->operator([Role::SuperAdmin->value]);
        $b = $this->operator([Role::SuperAdmin->value]);

        $raced = $this->inviteWhileBInvitesFirst($a, $b, 'raced@lynomia.test', [Role::Noc->value], [Role::InfrastructureAdmin->value]);

        $this->assertRefusedAsTheRequestRefuses($raced, $a, 'raced@lynomia.test', [Role::Noc->value]);
        $this->assertBsInvitationStands('raced@lynomia.test', [Role::InfrastructureAdmin->value]);
    }

    #[Test]
    public function a_delegates_invitation_is_refused_when_a_super_admin_made_the_address_an_operators_first(): void
    {
        $a = $this->operator([Role::Noc->value]);
        $a->givePermissionTo(Permission::RoleManage->value);
        $b = $this->operator([Role::SuperAdmin->value]);

        // The same staff role as the delegate's: nothing but the check under
        // the lock stands between this and a 201.
        $raced = $this->inviteWhileBInvitesFirst($a, $b, 'same-role@lynomia.test', [Role::Noc->value], [Role::Noc->value]);

        $this->assertRefusedAsTheRequestRefuses($raced, $a, 'same-role@lynomia.test', [Role::Noc->value]);
        $this->assertBsInvitationStands('same-role@lynomia.test', [Role::Noc->value]);
    }

    /**
     * Positive control: with no second invitation in between, the same
     * request invites, so the refusals above answer the interleaving.
     */
    #[Test]
    public function an_address_nobody_else_invited_is_still_invited(): void
    {
        $a = $this->operator([Role::SuperAdmin->value]);

        $this->actingAs($a)
            ->postJson('/api/admin/operators', ['email' => 'alone@lynomia.test', 'name' => 'Alone', 'roles' => [Role::Noc->value]])
            ->assertCreated()
            ->assertJsonPath('data.promoted_existing_account', false);
    }

    /**
     * @param  list<string>  $rolesA
     * @param  list<string>  $rolesB
     */
    private function inviteWhileBInvitesFirst(User $a, User $b, string $address, array $rolesA, array $rolesB): TestResponse
    {
        $fired = false;

        DB::listen(static function (QueryExecuted $query) use (&$fired, $b, $address, $rolesB): void {
            $sql = strtolower($query->sql);

            if ($fired
                || ! str_starts_with($sql, 'select')
                || ! str_contains($sql, 'from "users"')
                || ! str_contains($sql, '"email" = ?')
                || str_contains($sql, 'for update')
                || ! in_array($address, $query->bindings, true)) {
                return;
            }

            $fired = true;
            app(InviteOperator::class)->execute($b, $address, 'Invited by B', $rolesB);
        });

        $response = $this->actingAs($a)->postJson('/api/admin/operators', ['email' => $address, 'name' => 'Invited by A', 'roles' => $rolesA]);

        $this->assertTrue($fired, 'The listener never saw the request\'s validation read, so nothing was interleaved.');

        return $response;
    }

    /**
     * @param  list<string>  $roles
     */
    private function assertRefusedAsTheRequestRefuses(TestResponse $raced, User $a, string $address, array $roles): void
    {
        $raced->assertUnprocessable()
            ->assertJsonPath('error.details.fields.email.0', InviteOperator::THE_ADDRESS_IS_AN_OPERATORS);

        // The same answer, byte for byte but the request id, as the request's
        // own refusal of the address now that it is an operator's.
        $sequential = $this->actingAs($a)->postJson('/api/admin/operators', ['email' => $address, 'name' => 'Invited by A', 'roles' => $roles]);
        $sequential->assertUnprocessable();

        $strip = static function (TestResponse $response): array {
            /** @var array<string, mixed> $body */
            $body = $response->json();
            unset($body['error']['request_id']);

            return $body;
        };

        $this->assertSame($strip($sequential), $strip($raced));
    }

    /**
     * @param  list<string>  $roles
     */
    private function assertBsInvitationStands(string $address, array $roles): void
    {
        $operator = User::query()->where('email', $address)->sole();

        $this->assertEqualsCanonicalizing($roles, $operator->getRoleNames()->all(), 'The losing invitation re-roled the operator.');
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::OperatorInvited)->where('subject_id', $operator->id)->count());
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::OperatorRolesChanged)->where('subject_id', $operator->id)->count());

        // B's link is still the way in: the losing invitation neither deleted
        // its token nor mailed another.
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $address)->count());
        Notification::assertSentToTimes($operator, OperatorInvitation::class, 1);
    }

    /**
     * @param  list<string>  $roles
     */
    private function operator(array $roles): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles($roles);

        return $user;
    }
}
