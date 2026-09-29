<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Support\Lifecycle\EndOfService;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\Support\BuysSharedHosting;
use Tests\TestCase;

/**
 * F-19 × F-18: two doors can end a shared-hosting account, and neither may be
 * the weaker one.
 *
 * `DELETE /api/admin/hosting-accounts/{account}` is F-18's door: the route
 * demands `hosting_account.manage`, and its controller demands
 * `service.terminate` as well for anything that is not an account already
 * waiting out its window, or for any forced call. `TerminateHostingAccount`
 * then refuses a non-suspended account whoever asks, unless forced.
 *
 * `DELETE /api/admin/services/{service}` is the second door. Before F-19 it
 * sent a hosting service down the VPS path and refused it by accident; now it
 * ends it through EndOfService → EndHostingService → TerminateHostingAccount,
 * which inherits F-18's action layer and NOT its controller gate. The gate it
 * has instead is EndOfService::authorityOver(): both permissions, always,
 * whatever `force` says.
 *
 * The table below is driven, not read: every cell is a fresh account bought
 * through checkout and built at the (fake) panel, one principal, one request,
 * and the panel is asked afterwards whether the account is still there. The
 * round-two retraction this file answers was an argument that the two doors
 * were "byte-identical" — true of the window and false of the permissions — so
 * nothing here is inferred from reading either controller.
 */
final class EndingAHostingAccountThroughEitherDoorTest extends TestCase
{
    use BuysSharedHosting;
    use RefreshDatabase;

    private const string NONE = '(none)';

    private const string TERMINATE = 'service.terminate';

    private const string MANAGE = 'hosting_account.manage';

    private const string BOTH = 'both';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        config([
            'hosting.retention.suspended_days' => 30,
            'provisioning.termination.suspended_retention_days' => 30,
        ]);
    }

    /**
     * A live account: bought, built and serving. The headline of both findings.
     *
     * The service door and the hosting door ask for the same two keys to
     * destroy it; `service.terminate` alone and `hosting_account.manage` alone
     * are each refused 403 at one door or the other, in both force modes.
     */
    #[Test]
    public function a_live_account_needs_both_permissions_at_both_doors(): void
    {
        $this->assertSame($this->expected(live: true), $this->drive(live: true));
    }

    /**
     * A suspended account still inside its window — the case the window exists
     * for. The hosting door lets `hosting_account.manage` alone ask and the
     * action says no (409); the service door asks for both keys before the
     * action is reached. Forced, both doors want both keys.
     */
    #[Test]
    public function an_account_inside_its_window_needs_both_permissions_to_skip_it(): void
    {
        $this->assertSame($this->expected(live: false), $this->drive(live: false));
    }

    #[Test]
    public function the_service_doors_own_gate_refuses_without_the_route_middleware(): void
    {
        /*
         * The service route's middleware demands `service.terminate`. Its gate
         * — authorityOver() — demands that and `hosting_account.manage`, so
         * the middleware is not what keeps the weaker principal out: with the
         * permission middleware stripped from the route, every principal short
         * of both keys is still refused 403 by the controller, forced or not.
         */
        $observed = [];

        foreach ([self::NONE, self::TERMINATE, self::MANAGE] as $who) {
            foreach ([false, true] as $force) {
                [$account, $service] = $this->liveAccount();

                $status = $this->withoutMiddleware(PermissionMiddleware::class)
                    ->actingAs($this->holding($who))
                    ->deleteJson('/api/admin/services/'.$service->getKey(), $this->body($force))
                    ->status();

                $observed[] = sprintf('%s force=%s → %d destroyed=%s', $who, $force ? 'yes' : 'no', $status, $this->destroyed($account));
            }
        }

        $this->assertSame([
            '(none) force=no → 403 destroyed=no',
            '(none) force=yes → 403 destroyed=no',
            'service.terminate force=no → 403 destroyed=no',
            'service.terminate force=yes → 403 destroyed=no',
            'hosting_account.manage force=no → 403 destroyed=no',
            'hosting_account.manage force=yes → 403 destroyed=no',
        ], $observed);
    }

    #[Test]
    public function the_authority_over_each_kind_is_stated_once_and_an_unknown_kind_has_none(): void
    {
        $this->assertSame(
            [Permission::ServiceTerminate, Permission::HostingAccountManage],
            EndOfService::authorityOver(ProductKind::SharedHosting->value),
        );
        $this->assertSame([Permission::ServiceTerminate], EndOfService::authorityOver(ProductKind::Vps->value));
        $this->assertSame([Permission::ServiceTerminate], EndOfService::authorityOver(ProductKind::Dedicated->value));

        // Guessing an unknown kind's authority is guessing what may destroy it.
        $this->expectException(RuntimeException::class);

        EndOfService::authorityOver('mainframe');
    }

    #[Test]
    public function the_service_door_ends_the_service_as_well_as_the_account(): void
    {
        /*
         * The hosting door ends an account; the service door ends a service,
         * and what was bought ends with it. Before F-19 the sweep and this
         * route left a hosting service `suspended` beside a terminated
         * account (seen on F-18's own branch).
         */
        [$account, $service] = $this->accountPastItsWindow();

        $this->actingAs($this->holding(self::BOTH))
            ->deleteJson('/api/admin/services/'.$service->getKey(), $this->body(false))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.hosting_account_id', (string) $account->getKey());

        $this->assertSame(HostingAccountStatus::Terminated, $account->fresh()?->status);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()?->status);
        $this->assertFalse($this->sharedHostingPanelHas($account->username));
    }

    /**
     * @return list<string>
     */
    private function drive(bool $live): array
    {
        $observed = [];

        foreach (['hosting-accounts', 'services'] as $door) {
            foreach ([self::NONE, self::TERMINATE, self::MANAGE, self::BOTH] as $who) {
                foreach ([false, true] as $force) {
                    // A fresh account for every cell: a destroyed account in
                    // one cell must not make the next cell's refusal look good.
                    [$account, $service] = $live ? $this->liveAccount() : $this->accountInsideItsWindow();

                    $uri = $door === 'services'
                        ? '/api/admin/services/'.$service->getKey()
                        : '/api/admin/hosting-accounts/'.$account->getKey();

                    $response = $this->actingAs($this->holding($who))->deleteJson($uri, $this->body($force));

                    $observed[] = sprintf(
                        '%-16s %-22s force=%-3s → %d %-38s destroyed=%s',
                        $door,
                        $who,
                        $force ? 'yes' : 'no',
                        $response->status(),
                        (string) ($response->json('error.code') ?? ''),
                        $this->destroyed($account),
                    );
                }
            }
        }

        return $observed;
    }

    /**
     * The table this change is closed on.
     *
     * @return list<string>
     */
    private function expected(bool $live): array
    {
        $unforcedRefusal = $live ? 'hosting.termination_before_suspension' : 'hosting.retention_period_active';

        $rows = [
            // The hosting door: F-18's route middleware, controller gate and action.
            ['hosting-accounts', self::NONE, false, 403, 'auth.forbidden', 'no'],
            ['hosting-accounts', self::NONE, true, 403, 'auth.forbidden', 'no'],
            ['hosting-accounts', self::TERMINATE, false, 403, 'auth.forbidden', 'no'],
            ['hosting-accounts', self::TERMINATE, true, 403, 'auth.forbidden', 'no'],
            // Live: the controller wants service.terminate. Inside the window:
            // the route's own grant, and the action refuses.
            ['hosting-accounts', self::MANAGE, false, $live ? 403 : 409, $live ? 'auth.forbidden' : $unforcedRefusal, 'no'],
            ['hosting-accounts', self::MANAGE, true, 403, 'auth.forbidden', 'no'],
            ['hosting-accounts', self::BOTH, false, 409, $unforcedRefusal, 'no'],
            ['hosting-accounts', self::BOTH, true, 200, '', 'yes'],
            // The service door: route middleware, then authorityOver(), then
            // the same action by way of EndHostingService.
            ['services', self::NONE, false, 403, 'auth.forbidden', 'no'],
            ['services', self::NONE, true, 403, 'auth.forbidden', 'no'],
            ['services', self::TERMINATE, false, 403, 'auth.forbidden', 'no'],
            ['services', self::TERMINATE, true, 403, 'auth.forbidden', 'no'],
            ['services', self::MANAGE, false, 403, 'auth.forbidden', 'no'],
            ['services', self::MANAGE, true, 403, 'auth.forbidden', 'no'],
            ['services', self::BOTH, false, 409, $unforcedRefusal, 'no'],
            ['services', self::BOTH, true, 202, '', 'yes'],
        ];

        return array_map(static fn (array $row): string => sprintf(
            '%-16s %-22s force=%-3s → %d %-38s destroyed=%s',
            $row[0],
            $row[1],
            $row[2] ? 'yes' : 'no',
            $row[3],
            $row[4],
            $row[5],
        ), $rows);
    }

    /**
     * @return array{HostingAccount, Service}
     */
    private function liveAccount(): array
    {
        $order = $this->buySharedHosting(
            Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']),
            $this->sharedHostingPlan(),
        );

        $service = Service::query()->where('order_id', $order->getKey())->sole();
        $account = HostingAccount::query()->where('service_id', $service->getKey())->sole();

        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(HostingAccountStatus::Active, $account->status);
        $this->assertTrue($this->sharedHostingPanelHas($account->username));

        return [$account, $service];
    }

    /**
     * Suspended for non-payment three days ago: well inside a thirty-day window.
     *
     * @return array{HostingAccount, Service}
     */
    private function accountInsideItsWindow(): array
    {
        [$account, $service] = $this->liveAccount();

        $this->suspend($service);

        $this->assertSame(HostingAccountStatus::Suspended, $account->fresh()?->status);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()?->status);

        return [$account->fresh() ?? $account, $service->fresh() ?? $service];
    }

    /**
     * @return array{HostingAccount, Service}
     */
    private function accountPastItsWindow(): array
    {
        [$account, $service] = $this->liveAccount();

        $this->suspend($service);
        $this->travel(31)->days();

        return [$account->fresh() ?? $account, $service->fresh() ?? $service];
    }

    private function suspend(Service $service): void
    {
        $subscription = Subscription::query()->where('order_id', $service->order_id)->sole();

        app(TransitionSubscription::class)->execute($subscription, SubscriptionStatus::PastDue);
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Suspended);
    }

    private function holding(string $who): User
    {
        /*
         * Granted directly rather than through a seeded role. The default seed
         * gives both permissions to one role, so the weaker principals need a
         * custom role — and custom roles are creatable.
         */
        $permissions = match ($who) {
            self::NONE => [],
            self::TERMINATE => [Permission::ServiceTerminate->value],
            self::MANAGE => [Permission::HostingAccountManage->value],
            self::BOTH => [Permission::ServiceTerminate->value, Permission::HostingAccountManage->value],
            default => throw new RuntimeException('Unknown principal '.$who),
        };

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user->fresh() ?? $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(bool $force): array
    {
        return ['reason' => 'Ticket 7731: asked to remove the account.', 'force' => $force];
    }

    private function destroyed(HostingAccount $account): string
    {
        $gone = ! $this->sharedHostingPanelHas($account->username);

        $this->assertSame(
            $gone,
            $account->fresh()?->status === HostingAccountStatus::Terminated,
            'The panel and the platform disagree about whether the account exists.',
        );

        return $gone ? 'yes' : 'no';
    }
}
