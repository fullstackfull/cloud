<?php

declare(strict_types=1);

namespace Tests\Feature\Termination;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\Provisioning\Application\Actions\EndExpiredServices;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sweep that finishes what a cancellation started.
 *
 * The boundary these tests exist to hold is the one between "the customer
 * chose this date" and "the customer owes us money". Both look identical in
 * the database — suspended, window elapsed — and only one of them may be acted
 * on without a person.
 */
final class TheRetentionSweepTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private ?HostingNode $hostingNode = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    #[Test]
    public function a_cancelled_service_out_of_time_is_ended(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(1, $outcome['ended']);

        // Queued rather than done: the destruction is a provider call, and it
        // gets the same attempt accounting and timeout handling as every other
        // one.
        $this->assertTrue(
            ProvisioningJob::query()
                ->where('service_id', $service->getKey())
                ->where('kind', ProvisioningJobKind::DestroyVps->value)
                ->exists(),
        );
    }

    #[Test]
    public function a_service_suspended_for_non_payment_is_never_ended_by_the_sweep(): void
    {
        /*
         * The most important test in this file. A customer who has not paid is
         * somebody the business may still want back, and destroying their data
         * thirty days into a billing dispute is a decision that needs a
         * person's name on it. That person has the operator endpoint.
         */
        $service = $this->vps(BeginRetentionWindow::BY_NON_PAYMENT, CarbonImmutable::now()->subDay());

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(0, $outcome['ended']);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()?->status);
        $this->assertFalse(ProvisioningJob::query()->where('service_id', $service->getKey())->exists());
    }

    #[Test]
    public function a_service_still_inside_its_window_is_left_alone(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->addDays(10));

        $this->assertSame(0, app(EndExpiredServices::class)->execute()['ended']);
        $this->assertFalse(ProvisioningJob::query()->where('service_id', $service->getKey())->exists());
    }

    #[Test]
    public function the_customer_is_warned_before_anything_is_destroyed(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->addDays(2));

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(1, $outcome['warned']);
        $this->assertSame(0, $outcome['ended']);

        $notification = Notification::query()
            ->where('type', NotificationType::DataRetentionEnding->value)
            ->firstOrFail();

        // The date, so the sentence is actionable. "Your data will be
        // destroyed soon" is not a message anybody can act on.
        $this->assertSame(
            $service->retention_ends_at?->toDateString(),
            $notification->data['date'] ?? null,
        );

        // Once. A daily sweep that warned daily would be a daily sweep nobody
        // reads.
        app(EndExpiredServices::class)->execute();

        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::DataRetentionEnding->value)->count(),
        );
    }

    #[Test]
    public function a_warning_is_not_sent_after_the_window_has_already_closed(): void
    {
        // The ending itself is the message at that point, and a warning that
        // arrives with the deletion is worse than none.
        $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $this->assertSame(0, app(EndExpiredServices::class)->execute()['warned']);
    }

    #[Test]
    public function ending_a_service_is_audited_and_the_customer_is_told(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        app(EndExpiredServices::class)->execute();

        $entry = AuditEntry::query()->where('action', AuditAction::ServiceTerminated->value)->firstOrFail();

        // "Who terminated this service" must never answer "nobody knows".
        $this->assertSame('retention sweep', $entry->context['terminated_by'] ?? null);
        $this->assertSame(BeginRetentionWindow::BY_CUSTOMER, $entry->context['ended_reason'] ?? null);

        $this->assertTrue(
            Notification::query()
                ->where('type', NotificationType::ServiceTerminated->value)
                ->where('customer_id', $this->customer->getKey())
                ->exists(),
        );

        unset($service);
    }

    #[Test]
    public function a_physical_server_goes_to_maintenance_and_never_back_to_stock(): void
    {
        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'dedicated',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDay(),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ]);

        $server = DedicatedServer::factory()->create([
            'service_id' => $service->getKey(),
            'customer_id' => $this->customer->id,
            'status' => DedicatedServerStatus::Active,
        ]);

        $this->assertSame(1, app(EndExpiredServices::class)->execute()['ended']);

        /*
         * The one place this platform deliberately stops short of automating
         * something. The disks in that chassis hold the customer's data until
         * a person erases them, and no call the platform can make proves that
         * happened — so the machine is out of the sellable pool and stays
         * there until somebody says otherwise.
         */
        $this->assertSame(DedicatedServerStatus::Maintenance, $server->fresh()?->status);
        $this->assertNotSame(DedicatedServerStatus::Available, $server->fresh()?->status);
    }

    #[Test]
    public function one_service_that_cannot_be_ended_does_not_stop_the_others(): void
    {
        // A VPS service with no machine behind it: the termination action
        // refuses, and the sweep has to step over it rather than stopping.
        Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDays(2),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ]);

        $healthy = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(1, $outcome['failed']);
        $this->assertSame(1, $outcome['ended']);
        $this->assertTrue(ProvisioningJob::query()->where('service_id', $healthy->getKey())->exists());
    }

    #[Test]
    public function the_sweep_can_be_switched_off_without_switching_off_the_warnings(): void
    {
        config()->set('provisioning.termination.sweep_cancelled', false);

        $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());
        $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->addDays(2));

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(0, $outcome['ended']);

        // A deployment that has turned the automation off still owes its
        // customers the date.
        $this->assertSame(1, $outcome['warned']);
    }

    #[Test]
    public function a_service_already_on_its_way_out_is_not_ended_a_second_time(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $this->assertSame(1, app(EndExpiredServices::class)->execute()['ended']);

        /*
         * Terminating is not instant: the VPS path queues a job a worker may
         * not reach for minutes, and the service stays suspended until it
         * does. A daily sweep that found it again would write a second audit
         * entry and tell the customer a second time that their data had been
         * destroyed.
         */
        $this->assertSame(0, app(EndExpiredServices::class)->execute()['ended']);

        $this->assertNotNull($service->refresh()->termination_requested_at);

        /*
         * Scoped to this service rather than counting every termination in the
         * database. The queue proofs run the same sweep in a separate process
         * against committed rows, and the audit entries that leaves behind are
         * outside any test's transaction — a global count here passes alone
         * and fails in the full suite, which is a test about the suite rather
         * than about the sweep.
         */
        $this->assertSame(
            1,
            AuditEntry::query()
                ->where('action', AuditAction::ServiceTerminated->value)
                ->where('subject_id', (string) $service->getKey())
                ->count(),
        );
    }

    #[Test]
    public function the_command_reports_what_it_did(): void
    {
        $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $this->artisan('services:end-expired')
            ->expectsOutputToContain('1 services ended')
            ->assertSuccessful();
    }

    /* =====================================================================
     * Shared hosting — F-18
     * ===================================================================== */

    #[Test]
    public function a_hosting_account_put_back_by_hand_is_not_swept_away(): void
    {
        /*
         * The second road to F-18. Before the fix, putting an account back by
         * hand left its service holding a closed, customer-chosen window; the
         * account's `suspended_at` was cleared on the way back, and
         * `retentionHasElapsed()` read the missing date as an elapsed window.
         * So the nightly sweep destroyed a site that was serving, for a
         * customer somebody had just decided to keep.
         *
         * Built directly in the drifted shape — the row a deployment that
         * unsuspended by hand before this fix shipped may still hold — so the
         * test is about what the sweep does with it, not about how it arose.
         */
        [$service, $account] = $this->hosting('restored', HostingAccountStatus::Active, null);

        $outcome = app(EndExpiredServices::class)->execute();

        // Refused and stepped over, which is a sweep failure — loud in the log
        // rather than silent at the panel. Not ended.
        $this->assertSame(0, $outcome['ended']);
        $this->assertSame(1, $outcome['failed']);
        $this->assertSame(HostingAccountStatus::Active, $account->fresh()?->status);
        $this->assertTrue($this->hostingPanelHas('restored'), 'The sweep destroyed an account that was serving.');
        $this->assertNull($service->fresh()?->termination_requested_at);
    }

    #[Test]
    public function when_the_two_windows_disagree_the_accounts_window_decides(): void
    {
        /*
         * Two retention windows, from two configuration keys:
         * `provisioning.termination.suspended_retention_days` stamps the
         * service, `hosting.retention.suspended_days` is read against the
         * account. Both default to 30. When they disagree, the account's
         * window — the one guarding the customer's files — decides, and the
         * sweep steps over the service rather than ending it early.
         */
        config(['hosting.retention.suspended_days' => 60]);

        [, $account] = $this->hosting('longer', HostingAccountStatus::Suspended, 40);

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(0, $outcome['ended']);
        $this->assertSame(1, $outcome['failed']);
        $this->assertSame(HostingAccountStatus::Suspended, $account->fresh()?->status);
        $this->assertTrue($this->hostingPanelHas('longer'));

        // And once the account's own window has run, the same sweep ends it.
        config(['hosting.retention.suspended_days' => 30]);

        $this->assertSame(1, app(EndExpiredServices::class)->execute()['ended']);
        $this->assertSame(HostingAccountStatus::Terminated, $account->fresh()?->status);
        $this->assertFalse($this->hostingPanelHas('longer'));
    }

    #[Test]
    public function a_hosting_account_whose_window_has_run_out_is_ended_by_the_sweep(): void
    {
        [, $account] = $this->hosting('departed', HostingAccountStatus::Suspended, 40);

        $this->assertSame(1, app(EndExpiredServices::class)->execute()['ended']);
        $this->assertSame(HostingAccountStatus::Terminated, $account->fresh()?->status);
        $this->assertFalse($this->hostingPanelHas('departed'));
    }

    #[Test]
    public function unsuspending_by_hand_calls_the_services_window_off(): void
    {
        /*
         * The drifted shape is no longer manufactured. Putting an account
         * back — by the operator endpoint or any other caller of the action —
         * cancels the window its service was holding, so the sweep has
         * nothing to trip over the next night.
         */
        [$service, $account] = $this->hosting('broughtback', HostingAccountStatus::Suspended, 40);

        $this->seed(RolePermissionSeeder::class);

        $operator = User::factory()->create();
        $operator->givePermissionTo(Permission::HostingAccountManage->value);

        $this->actingAs($operator->fresh() ?? $operator)
            ->postJson('/api/admin/hosting-accounts/'.$account->getKey().'/unsuspend', ['reason' => 'Paid by bank transfer.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertNull($service->fresh()?->retention_ends_at);
        $this->assertNull($service->fresh()?->ended_reason);

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(0, $outcome['ended']);
        $this->assertSame(0, $outcome['failed']);
        $this->assertTrue($this->hostingPanelHas('broughtback'));
    }

    #[Test]
    public function the_repair_query_finds_exactly_the_drifted_rows(): void
    {
        /*
         * The operator's repair for rows that drifted before the fix shipped
         * is a query in UnsuspendHostingAccount's docblock, and a remediation
         * handed to an operator is code. It is run here, as written, against
         * one fixture per shape, and the returned set is pinned exactly: an
         * over-match cancels a window that is still owed, and an under-match
         * leaves a row failing the sweep every night.
         *
         * Wanted: an Active account behind a suspended service holding a
         * closed, customer-chosen window that nothing has started ending.
         */
        $cpanel = HostingNode::factory()->create(['panel' => HostingPanel::Cpanel]);

        $wanted = [
            // The drift itself, on the fake panel.
            $this->shape('fxa'),
            // The same, carrying a stale suspension date on the account.
            $this->shape('fxb', account: ['suspended_at' => CarbonImmutable::now()->subDays(90)]),
            /*
             * The same on a real panel's node. It differs from `fxa` in the
             * node's `panel` column, which the query selects and must not
             * filter on: a query narrowed to `hn.panel = 'fake'` returns
             * nothing on a real estate while every other fixture here would
             * still agree with it.
             */
            $this->shape('fxk', node: $cpanel),
            /*
             * An Active account carrying a `terminated_at` stamp: a row
             * re-armed by ReserveHostingNodeCapacity keeps the stamp, because
             * the re-arm clears neither date. Drifted and failing the sweep
             * nightly all the same, which is why the query has no
             * `terminated_at IS NULL`.
             */
            $this->shape('fxm', account: ['terminated_at' => CarbonImmutable::now()->subDays(50)]),
        ];

        // One fixture per discriminating clause, each excluded by it alone.
        $this->shape('fxc', service: ['status' => ServiceStatus::Active]);
        $this->shape('fxd', service: ['retention_ends_at' => CarbonImmutable::now()->addDays(3)]);
        $this->shape('fxe', service: ['ended_reason' => BeginRetentionWindow::BY_NON_PAYMENT]);
        $this->shape('fxf', service: ['termination_requested_at' => CarbonImmutable::now()->subHour()]);
        /*
         * Not Active. A pending or failed account behind a closed window is a
         * sweep failure too, but `cancel()` is the wrong repair for it — it
         * is serving nothing — and nothing owns what that row means yet. A
         * Suspended one is the shape a termination that reached the panel and
         * died before writing its row leaves behind; cancelling its window
         * would file a customer whose data is gone as a returning one.
         */
        $this->shape('fxg', account: ['status' => HostingAccountStatus::Pending]);
        $this->shape('fxh', account: ['status' => HostingAccountStatus::Failed]);
        $this->shape('fxl', account: [
            'status' => HostingAccountStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
        ]);
        // No window at all: excluded by `<= NOW()` as well as by `IS NOT NULL`,
        // the one clause that survives ablation.
        $this->shape('fxi', service: ['retention_ends_at' => null]);

        $rows = DB::select(self::repairQuery());

        $found = array_map(static fn (object $row): string => (string) $row->service_id, $rows);
        sort($found);

        $expected = array_map(static fn (Service $s): string => (string) $s->getKey(), $wanted);
        sort($expected);

        $this->assertSame($expected, $found, 'The repair query returned a different set from the drifted rows.');

        // The columns the manual step needs, by name.
        $this->assertSame(
            ['service_id', 'retention_ends_at', 'hosting_account_id', 'username', 'hostname', 'panel'],
            array_keys((array) $rows[0]),
        );
    }

    #[Test]
    public function the_repair_query_compares_the_window_with_now_on_one_line(): void
    {
        /*
         * A textual pin, and deliberately loud. A behavioural one — a window
         * half a second past the boundary — proves nothing here, because the
         * default Postgres date format Eloquent writes drops the fraction and
         * the fixture lands exactly on the bound. So the position is pinned
         * instead: one NOW(), on the window's own line, compared with `<=`.
         * If this fails after a deliberate rewrite, re-derive the boundary by
         * hand and move the pin with it.
         */
        $lines = explode("\n", self::repairQuery());
        $withNow = array_values(array_filter($lines, static fn (string $l): bool => stripos($l, 'now()') !== false));

        $this->assertCount(1, $withNow);
        $this->assertMatchesRegularExpression('/^\s*AND s\.retention_ends_at <= NOW\(\)/', $withNow[0]);
    }

    #[Test]
    public function the_repair_query_pastes_into_either_client(): void
    {
        /*
         * A `--` may open a comment only when a space or the end of the line
         * follows it: MySQL's client does not take `--x` as a comment and
         * psql does, so a query that works in one breaks in the other.
         * Anywhere on the line, not only at its start, and never inside a
         * string literal — the scan walks the line tracking quote state
         * rather than being a regex, which is why the scanner is checked here
         * before it is trusted.
         */
        $this->assertSame([], self::badCommentOpeners("AND s.ended_reason = 'customer--cancelled'"));
        $this->assertSame([], self::badCommentOpeners("AND x = 'it''s--fine' -- a real comment"));
        $this->assertSame([], self::badCommentOpeners('-- a comment'));
        $this->assertSame([], self::badCommentOpeners('AND x = 1 --'));
        $this->assertSame([10], self::badCommentOpeners('AND x = 1 --no space'));
        $this->assertSame([15], self::badCommentOpeners("AND x = 'a--b' --no space"));
        $this->assertSame([0], self::badCommentOpeners('--leading'));

        $bad = [];

        foreach (explode("\n", self::repairQuery()) as $number => $line) {
            foreach (self::badCommentOpeners($line) as $offset) {
                $bad[] = sprintf('line %d, column %d: %s', $number + 1, $offset + 1, $line);
            }
        }

        $this->assertSame([], $bad, 'A `--` comment opener not followed by a space breaks the MySQL client.');
    }

    /**
     * The SQL between the ```sql fence in UnsuspendHostingAccount's docblock
     * and the fence that closes it, with the docblock's ` * ` prefix removed.
     */
    private static function repairQuery(): string
    {
        $source = (string) file_get_contents(base_path('src/Modules/SharedHosting/Application/Actions/UnsuspendHostingAccount.php'));

        $inside = false;
        $sql = [];

        foreach (explode("\n", $source) as $line) {
            $text = (string) preg_replace('/^\s*\*( |$)/', '', $line);

            if (! $inside && trim($text) === '```sql') {
                $inside = true;

                continue;
            }

            if ($inside && trim($text) === '```') {
                break;
            }

            if ($inside) {
                $sql[] = $text;
            }
        }

        self::assertNotSame([], $sql, 'No ```sql block found in UnsuspendHostingAccount\'s docblock.');

        return implode("\n", $sql);
    }

    /**
     * @return list<int> offsets of `--` openers outside string literals that are followed by neither a space nor the end of the line
     */
    private static function badCommentOpeners(string $line): array
    {
        $quoted = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === "'") {
                // A doubled quote inside a literal toggles twice and leaves
                // the literal open, which is exactly what it means.
                $quoted = ! $quoted;

                continue;
            }

            if ($quoted || $char !== '-' || ($line[$i + 1] ?? '') !== '-') {
                continue;
            }

            $next = $line[$i + 2] ?? '';

            // Everything after the first opener is comment.
            return ($next === '' || $next === ' ') ? [] : [$i];
        }

        return [];
    }

    /**
     * One service/account pair in the drifted shape, with overrides.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, mixed>  $account
     */
    private function shape(string $username, array $service = [], array $account = [], ?HostingNode $node = null): Service
    {
        $row = Service::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'kind' => 'shared_hosting',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDays(10),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ], $service));

        HostingAccount::factory()->named($username)->create(array_merge([
            'hosting_node_id' => ($node ?? $this->hostingNode())->getKey(),
            'customer_id' => $this->customer->id,
            'service_id' => $row->getKey(),
            'status' => HostingAccountStatus::Active,
            'suspended_at' => null,
        ], $account));

        return $row;
    }

    /**
     * A shared-hosting service holding a closed, customer-chosen window, and
     * an account behind it that exists at the (fake) panel.
     *
     * @return array{Service, HostingAccount}
     */
    private function hosting(string $username, HostingAccountStatus $status, ?int $suspendedDaysAgo): array
    {
        $node = $this->hostingNode();

        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($node);
        $panel->createAccount($node, new CreateAccountRequest(
            username: $username,
            primaryDomain: $username.'.example.test',
            password: 's3cret-panel-password',
            packageName: 'starter',
            contactEmail: 'owner@'.$username.'.example.test',
        ));
        $node->increment('account_count');

        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'shared_hosting',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDay(),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
            'label' => $username,
        ]);

        $account = HostingAccount::factory()->named($username)->create([
            'hosting_node_id' => $node->getKey(),
            'customer_id' => $this->customer->id,
            'service_id' => $service->getKey(),
            'status' => $status,
            'suspended_at' => $suspendedDaysAgo === null ? null : CarbonImmutable::now()->subDays($suspendedDaysAgo),
        ]);

        return [$service, $account];
    }

    private function hostingNode(): HostingNode
    {
        if ($this->hostingNode === null) {
            // One provider factory for the test, so the actions and the
            // assertions talk to the same in-memory panel.
            $this->app->singleton(HostingProviderFactory::class);
            $this->hostingNode = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);
        }

        return $this->hostingNode;
    }

    private function hostingPanelHas(string $username): bool
    {
        $node = $this->hostingNode();

        foreach (app(HostingProviderFactory::class)->for($node)->listAccounts($node) as $remote) {
            if ($remote->username === $username) {
                return true;
            }
        }

        return false;
    }

    private function vps(string $reason, CarbonImmutable $windowEnds): Service
    {
        $cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $node = ComputeNode::factory()->withCapacity(16, 32_768, 1_000)->create([
            'cluster_id' => $cluster->id,
            'status' => NodeStatus::Active,
        ]);

        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Suspended,
            // Old enough for the termination action's own check as well as the
            // window's: the two agree in production, and a fixture that only
            // satisfied one would be testing half the guard.
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => $windowEnds,
            'ended_reason' => $reason,
            'label' => 'web-kw-01',
        ]);

        VirtualMachine::factory()->onNode($node)->create([
            'service_id' => $service->getKey(),
        ]);

        return $service;
    }
}
