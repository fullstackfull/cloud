<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\SharedHosting\Application\Actions\TerminateHostingAccount;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\AccountStillInServiceException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\RetentionPeriodActiveException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F-18: a live hosting account is destroyed only by somebody holding both keys.
 *
 * `DELETE /api/admin/hosting-accounts/{account}` sits behind
 * `hosting_account.manage`, which is the permission for clearing out accounts
 * whose retention window has run out. Destroying anything else — a live
 * customer's site, or a suspended one still inside its window — is a different
 * decision and needs `service.terminate` as well.
 *
 * The defect this file exists for had that exactly backwards. A live account
 * has no `suspended_at`, `retentionHasElapsed()` read the missing date as an
 * elapsed window, and the second permission was asked for only under `force` —
 * so the WEAKER grant, sent WITHOUT `force`, destroyed a paying customer's
 * site, while the stronger path was the one that asked for more.
 *
 * Two layers, each pinned on its own. The controller refuses the weaker
 * principal with 403 before anything else happens; the action refuses a live
 * account with 409 whoever asks, unless forced. The tests below insist on the
 * specific status, so reverting either layer turns something red rather than
 * being covered by the other one refusing "somehow".
 */
final class TerminatingAHostingAccountTest extends TestCase
{
    use RefreshDatabase;

    private HostingNode $node;

    private FakeHostingProvider $panel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->app->singleton(HostingProviderFactory::class);

        $this->node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($this->node);
        $this->panel = $panel;

        config(['hosting.retention.suspended_days' => 30]);
    }

    /**
     * @return array<string, array{HostingAccountStatus, ?int}>
     */
    public static function accountsThatAreNotWaitingToBeReleased(): array
    {
        return [
            // The headline: a serving account, which has never been suspended.
            'active, never suspended' => [HostingAccountStatus::Active, null],
            /*
             * A serving account carrying a months-old suspension date. The
             * create handler writes Active without clearing `suspended_at`, and
             * a re-armed row keeps it, so this shape is real — and it is the
             * one a date-only check lets straight through, because its "window"
             * elapsed long ago.
             */
            'active, stale suspension date' => [HostingAccountStatus::Active, 90],
            'pending' => [HostingAccountStatus::Pending, null],
            'failed' => [HostingAccountStatus::Failed, null],
        ];
    }

    #[Test]
    #[DataProvider('accountsThatAreNotWaitingToBeReleased')]
    public function the_weaker_permission_cannot_destroy_an_account_that_is_not_suspended(
        HostingAccountStatus $status,
        ?int $suspendedDaysAgo,
    ): void {
        $account = $this->account('livesite', $status, $suspendedDaysAgo);

        $this->actingAs($this->holding(Permission::HostingAccountManage))
            ->deleteJson('/api/admin/hosting-accounts/'.$account->getKey(), ['reason' => 'Clearing out old accounts.'])
            ->assertStatus(403);

        $this->assertSame($status, $account->fresh()?->status);
        $this->assertTrue($this->atThePanel('livesite'), 'The weaker permission removed the account from the panel.');
    }

    #[Test]
    #[DataProvider('accountsThatAreNotWaitingToBeReleased')]
    public function the_action_itself_refuses_an_account_that_is_not_suspended(
        HostingAccountStatus $status,
        ?int $suspendedDaysAgo,
    ): void {
        /*
         * The second layer, reached with both permissions and no `force`, so
         * the controller has nothing to object to. The status decides before
         * the date is read: the stale-date row has an "elapsed window" and is
         * refused all the same.
         */
        $account = $this->account('livesite', $status, $suspendedDaysAgo);

        $this->actingAs($this->holding(Permission::HostingAccountManage, Permission::ServiceTerminate))
            ->deleteJson('/api/admin/hosting-accounts/'.$account->getKey(), ['reason' => 'Clearing out old accounts.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.termination_before_suspension');

        $this->assertSame($status, $account->fresh()?->status);
        $this->assertTrue($this->atThePanel('livesite'));

        try {
            app(TerminateHostingAccount::class)->execute($account->fresh() ?? $account);
            $this->fail('The action destroyed an account that was not suspended.');
        } catch (AccountStillInServiceException $e) {
            $this->assertSame(409, $e->httpStatus());
        }
    }

    #[Test]
    public function both_permissions_and_force_may_destroy_a_live_account_deliberately(): void
    {
        // The documented override — an abuse case or an erasure request — is
        // still there, for the principal the route promised it to.
        $account = $this->account('abuser', HostingAccountStatus::Active, null);

        $this->actingAs($this->holding(Permission::HostingAccountManage, Permission::ServiceTerminate))
            ->deleteJson('/api/admin/hosting-accounts/'.$account->getKey(), [
                'reason' => 'Phishing kit, confirmed by abuse desk.',
                'force' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated');

        $this->assertFalse($this->atThePanel('abuser'));
    }

    #[Test]
    public function the_weaker_permission_still_clears_an_account_whose_window_has_run_out(): void
    {
        // What `hosting_account.manage` is for, and still does.
        $account = $this->account('departed', HostingAccountStatus::Suspended, 31);

        $this->actingAs($this->holding(Permission::HostingAccountManage))
            ->deleteJson('/api/admin/hosting-accounts/'.$account->getKey(), ['reason' => 'Retention elapsed.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated');

        $this->assertFalse($this->atThePanel('departed'));
    }

    #[Test]
    public function the_weaker_permission_cannot_skip_a_window_that_is_still_running(): void
    {
        $account = $this->account('disputed', HostingAccountStatus::Suspended, 3);

        $this->actingAs($this->holding(Permission::HostingAccountManage))
            ->deleteJson('/api/admin/hosting-accounts/'.$account->getKey(), [
                'reason' => 'Asked to delete now.',
                'force' => true,
            ])
            ->assertStatus(403);

        $this->actingAs($this->holding(Permission::HostingAccountManage))
            ->deleteJson('/api/admin/hosting-accounts/'.$account->getKey(), ['reason' => 'Asked to delete now.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.retention_period_active');

        $this->assertTrue($this->atThePanel('disputed'));
    }

    #[Test]
    public function a_suspension_with_no_date_is_not_an_elapsed_window(): void
    {
        /*
         * The model-level half of the defect. A suspended row with no
         * `suspended_at` cannot prove its window has passed, and the safe
         * reading of an unprovable window is that it has not.
         */
        $account = $this->account('undated', HostingAccountStatus::Suspended, null);
        $this->assertNull($account->suspended_at);
        $this->assertFalse($account->retentionHasElapsed());

        $asked = CarbonImmutable::now();

        $response = $this->actingAs($this->holding(Permission::HostingAccountManage))
            ->deleteJson('/api/admin/hosting-accounts/'.$account->getKey(), ['reason' => 'Retention elapsed.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.retention_period_active');

        $this->assertTrue($this->atThePanel('undated'));

        /*
         * The refusal still names a date, and it is a real timestamp rather
         * than an empty string or a sentence — the field is a Timestamp
         * everywhere else it appears. With no anchor it is a full window
         * from the moment of asking, the same reading the VPS path makes, so
         * it moves on each ask; the magnitude is pinned here and not merely
         * the movement: doubling the window, changing its unit or adding a
         * year all land outside these bounds.
         */
        try {
            app(TerminateHostingAccount::class)->execute($account->fresh() ?? $account);
            $this->fail('An undated suspension was treated as an elapsed window.');
        } catch (RetentionPeriodActiveException $e) {
            $releasesAt = CarbonImmutable::parse((string) ($e->context()['retention_releases_at'] ?? ''));

            $this->assertTrue($releasesAt->greaterThanOrEqualTo($asked->addDays(30)->subSecond()));
            $this->assertTrue($releasesAt->lessThanOrEqualTo(CarbonImmutable::now()->addDays(30)->addSecond()));
        }

        unset($response);
    }

    private function holding(Permission ...$permissions): User
    {
        /*
         * Granted directly rather than through a seeded role. In the default
         * seed the only role holding either permission holds both, so the
         * escalation needs a custom role — and custom roles are creatable.
         */
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(static fn (Permission $p): string => $p->value, $permissions));

        return $user->fresh() ?? $user;
    }

    private function account(string $username, HostingAccountStatus $status, ?int $suspendedDaysAgo): HostingAccount
    {
        $this->panel->createAccount($this->node, new CreateAccountRequest(
            username: $username,
            primaryDomain: $username.'.example.test',
            password: 's3cret-panel-password',
            packageName: 'starter',
            contactEmail: 'owner@'.$username.'.example.test',
        ));

        $this->node->increment('account_count');

        return HostingAccount::factory()->named($username)->create([
            'hosting_node_id' => $this->node->getKey(),
            'status' => $status,
            'suspended_at' => $suspendedDaysAgo === null ? null : now()->subDays($suspendedDaysAgo),
        ]);
    }

    private function atThePanel(string $username): bool
    {
        foreach ($this->panel->listAccounts($this->node) as $remote) {
            if ($remote->username === $username) {
                return true;
            }
        }

        return false;
    }
}
