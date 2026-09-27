<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\SharedHosting\Application\Actions\ReconcileHostingNodes;
use Lynomia\Modules\SharedHosting\Domain\Contracts\HostingProvider;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Mockery;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * One node that cannot be reconciled stops that node, not the sweep (re-audit
 * after round six, band C, unnumbered).
 *
 * `ReconcileHostingNodes` caught only `HostingProviderException`. Anything
 * else thrown while a node was being reconciled left `execute()` before the
 * node was stamped, so the node stayed the least recently asked and was first
 * again on every later run: no node behind it was compared again. The case
 * the re-audit found was a DirectAdmin listing naming an account 300
 * characters long — read as that account, by design — whose orphan drift
 * overflowed `resource_drifts.provider_reference varchar(255)` (SQLSTATE
 * 22001) on every run.
 *
 * Two changes, each held here:
 *
 *  - the column is `text`, so that drift is recorded under the name the panel
 *    gave, however long;
 *  - a failure while reconciling one node rolls back what that node had
 *    recorded (its comparison is its own transaction, a savepoint here, under
 *    the test's), is kept on the node and logged, and the sweep moves on. The
 *    failure below is a real database error, raised by a trigger, so the
 *    sweep has to go on through a transaction that one statement failed in.
 */
final class AFailureWhileReconcilingOneNodeStopsOnlyThatNodeTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 12:00:00');

        $this->log = new TestHandler;
        Log::swap(new Logger(new \Monolog\Logger('testing', [$this->log])));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function an_account_name_longer_than_the_column_once_held_is_recorded_as_drift_under_that_name(): void
    {
        $name = str_repeat('a', 300);
        $node = $this->node('node-long', '2026-09-01 00:00:00');
        $this->listings(['node-long' => 'list[]='.$name]);

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['nodes']);
        $drift = ResourceDrift::query()->where('kind', DriftKind::OrphanAtProvider->value)->sole();
        $this->assertSame($name, $drift->provider_reference);
        $read = $node->fresh();
        $this->assertNull($read?->reconcile_error);
        $this->assertSame('2026-09-10 12:00:00', $read->reconciled_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_node_whose_comparison_fails_is_stamped_and_the_next_run_reaches_the_node_behind_it(): void
    {
        config(['hosting.reconcile_batch' => 1]);
        $this->refuseDriftNamed('poison');

        $failing = $this->node('node-fails', '2026-09-01 00:00:00');
        $fine = $this->node('node-fine', '2026-09-02 00:00:00');
        $this->listings(['node-fails' => 'list[]=poison', 'node-fine' => 'list[]=stranger']);

        // Run one asks the least recently asked node, and its comparison fails.
        $first = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(['nodes' => 0, 'accounts' => 0, 'drifts' => 0, 'unread' => 0, 'failed' => 1], $first);
        $stopped = $failing->fresh();
        $this->assertSame('2026-09-10 12:00:00', $stopped?->reconcile_attempted_at?->format('Y-m-d H:i:s'), 'The failed node was not stamped, so it is asked first again.');
        $this->assertSame('2026-09-01 00:00:00', $stopped->reconciled_at?->format('Y-m-d H:i:s'), 'A comparison that failed was recorded as made.');
        $this->assertSame(
            'Reconciliation failed while comparing its account listing with the platform\'s accounts ('.QueryException::class.'), so nothing was concluded. The log has the detail.',
            $stopped->reconcile_error,
        );
        $this->assertTrue($this->logged('A hosting node\'s reconciliation failed, so nothing was concluded about its accounts.', 'node-fails'));

        // Run two reaches the node that used to be starved.
        CarbonImmutable::setTestNow('2026-09-10 16:00:00');
        $second = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $second['nodes']);
        $this->assertSame('2026-09-10 16:00:00', $fine->fresh()?->reconciled_at?->format('Y-m-d H:i:s'), 'The node behind the failing one was never compared.');
        $this->assertSame(1, ResourceDrift::query()->where('provider_reference', 'stranger')->count());
    }

    #[Test]
    public function one_sweep_goes_on_past_a_failed_node_and_writes_the_nodes_after_it(): void
    {
        $this->refuseDriftNamed('poison');

        $failing = $this->node('node-fails', '2026-09-01 00:00:00');
        $fine = $this->node('node-fine', '2026-09-02 00:00:00');
        $this->listings(['node-fails' => 'list[]=poison', 'node-fine' => 'list[]=stranger']);

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(['nodes' => 1, 'accounts' => 0, 'drifts' => 1, 'unread' => 0, 'failed' => 1], $outcome);
        $this->assertNotNull($failing->fresh()?->reconcile_error);
        $this->assertSame('2026-09-10 12:00:00', $fine->fresh()?->reconciled_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, ResourceDrift::query()->where('provider_reference', 'stranger')->count());
    }

    #[Test]
    public function what_a_failed_node_had_recorded_is_rolled_back_and_never_alerted(): void
    {
        $this->refuseDriftNamed('poison');

        $failing = $this->node('node-fails', '2026-09-01 00:00:00');
        $failing->forceFill(['account_count' => 1])->save();
        // Missing at the panel: Critical, recorded before the listed stranger fails.
        HostingAccount::factory()->named('liveone')->create([
            'hosting_node_id' => $failing->getKey(),
            'status' => HostingAccountStatus::Active,
        ]);
        $this->listings(['node-fails' => 'list[]=poison']);

        app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(0, ResourceDrift::query()->count(), 'A node whose comparison failed left half its conclusions behind.');
        $this->assertFalse(
            $this->logged('Critical drift was seen between the platform and a provider.'),
            'An alert went out about a drift row that was rolled back.',
        );
        $this->assertNotNull($failing->fresh()?->reconcile_error);
    }

    #[Test]
    public function a_node_whose_listing_fails_other_than_by_refusal_is_stamped_and_the_sweep_goes_on(): void
    {
        // An adapter fault on the way to the listing: not a HostingProviderException.
        $faulty = $this->node('node-fault', '2026-09-01 00:00:00');
        $panel = Mockery::mock(HostingProvider::class);
        $panel->shouldReceive('listAccounts')->andThrow(new RuntimeException('an adapter fault'));
        $this->app->singleton(HostingProviderFactory::class);
        app(HostingProviderFactory::class)->swap($faulty, $panel);
        $fine = $this->node('node-fine', '2026-09-02 00:00:00');
        $this->listings(['node-fine' => 'list[]=']);

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['nodes']);
        $this->assertSame(1, $outcome['failed'], 'An adapter fault on the way to the listing was not counted as a failed node.');
        $this->assertSame(0, $outcome['unread']);
        $stopped = $faulty->fresh();
        $this->assertSame('2026-09-10 12:00:00', $stopped?->reconcile_attempted_at?->format('Y-m-d H:i:s'));
        $this->assertSame('Reconciliation failed while asking for its account listing ('.RuntimeException::class.'), so nothing was concluded. The log has the detail.', $stopped?->reconcile_error);
        $this->assertSame('2026-09-01 00:00:00', $stopped->reconciled_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 12:00:00', $fine->fresh()?->reconciled_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_failed_node_is_read_again_before_it_is_stamped_so_the_failed_success_stamp_is_not_written(): void
    {
        // The comparison succeeds; the node's own success stamp is refused.
        DB::unprepared(<<<'SQL'
            create function r7_refuse_success_stamp() returns trigger language plpgsql as $$
            begin
                if new.reconciled_at is distinct from old.reconciled_at and new.reconcile_error is null then
                    raise exception 'refused for this test' using errcode = '23514';
                end if;
                return new;
            end
            $$;
            create trigger r7_refuse_success_stamp before update on hosting_nodes
                for each row execute function r7_refuse_success_stamp();
            SQL);

        $node = $this->node('node-stamp', '2026-09-01 00:00:00');
        $this->listings(['node-stamp' => 'list[]=']);

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['failed']);
        $stopped = $node->fresh();
        $this->assertSame('2026-09-01 00:00:00', $stopped?->reconciled_at?->format('Y-m-d H:i:s'), 'The failed comparison\'s success stamp was written beside its error.');
        $this->assertSame('2026-09-10 12:00:00', $stopped->reconcile_attempted_at?->format('Y-m-d H:i:s'));
        $this->assertNotNull($stopped->reconcile_error);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function concurrencyFailures(): iterable
    {
        // PostgreSQL's own words for each.
        yield 'a deadlock' => ['40P01', 'deadlock detected'];
        yield 'a serialization failure' => ['40001', 'could not serialize access due to concurrent update'];
    }

    /**
     * Inside a caller's transaction (here, the test's), Laravel does not roll
     * a nested transaction back to its savepoint on a concurrency failure: the
     * caller's transaction is aborted. Stamping the node would fail with
     * 25P02 and hide what happened; the original is let out instead. With no
     * caller's transaction it is recorded on the node like any other failure
     * ({@see AConcurrencyFailureWithNoCallersTransactionStopsOnlyThatNodeTest}).
     */
    #[Test]
    #[DataProvider('concurrencyFailures')]
    public function a_concurrency_failure_inside_a_callers_transaction_is_let_out_as_it_was_thrown(string $sqlState, string $message): void
    {
        $this->refuseDriftNamed('poison', $sqlState, $message);
        $this->node('node-fails', '2026-09-01 00:00:00');
        $this->listings(['node-fails' => 'list[]=poison']);

        try {
            app(ReconcileHostingNodes::class)->execute();
            $this->fail('A concurrency failure inside a caller\'s transaction was swallowed.');
        } catch (PDOException $e) {
            $states = [];
            for ($link = $e; $link !== null; $link = $link->getPrevious()) {
                if ($link instanceof QueryException) {
                    $states[] = $link->errorInfo[0] ?? null;
                }
            }
            $this->assertSame([$sqlState], array_values(array_unique($states)), 'The original failure was hidden: '.$e->getMessage());
        }
    }

    #[Test]
    public function a_concurrency_failure_on_the_way_to_the_listing_inside_a_callers_transaction_is_let_out_too(): void
    {
        $node = $this->node('node-fault', '2026-09-01 00:00:00');
        $deadlock = new DeadlockException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
        $panel = Mockery::mock(HostingProvider::class);
        $panel->shouldReceive('listAccounts')->andThrow($deadlock);
        $this->app->singleton(HostingProviderFactory::class);
        app(HostingProviderFactory::class)->swap($node, $panel);

        try {
            app(ReconcileHostingNodes::class)->execute();
            $this->fail('A concurrency failure inside a caller\'s transaction was swallowed.');
        } catch (DeadlockException $e) {
            $this->assertSame($deadlock, $e);
        }
    }

    #[Test]
    public function the_command_fails_when_a_node_failed_after_comparing_every_other(): void
    {
        $this->refuseDriftNamed('poison');
        $this->node('node-fails', '2026-09-01 00:00:00');
        $fine = $this->node('node-fine', '2026-09-02 00:00:00');
        $this->listings(['node-fails' => 'list[]=poison', 'node-fine' => 'list[]=']);

        $this->artisan('hosting:reconcile')
            ->expectsOutputToContain('1 nodes checked, 0 accounts compared, 0 disagreements recorded, 0 listings not read, 1 nodes failed.')
            ->assertFailed();

        $this->assertSame('2026-09-10 12:00:00', $fine->fresh()?->reconciled_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_command_fails_when_a_node_failed_on_the_way_to_its_listing(): void
    {
        $faulty = $this->node('node-fault', '2026-09-01 00:00:00');
        $panel = Mockery::mock(HostingProvider::class);
        $panel->shouldReceive('listAccounts')->andThrow(new RuntimeException('an adapter fault'));
        $this->app->singleton(HostingProviderFactory::class);
        app(HostingProviderFactory::class)->swap($faulty, $panel);
        $this->node('node-fine', '2026-09-02 00:00:00');
        $this->listings(['node-fine' => 'list[]=']);

        $this->artisan('hosting:reconcile')
            ->expectsOutputToContain('1 nodes checked, 0 accounts compared, 0 disagreements recorded, 0 listings not read, 1 nodes failed.')
            ->assertFailed();
    }

    /**
     * A missing credential does not reach the sweep as a fault: both adapters
     * translate the configuration error into a HostingProviderException
     * before any request is made, so it is a listing not read, kept on the
     * node, and does not fail the command.
     */
    #[Test]
    public function a_missing_credential_is_a_listing_not_read(): void
    {
        HostingNode::factory()->create([
            'slug' => 'node-bare', 'hostname' => 'node-bare.lynomia.test', 'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://node-bare.lynomia.test:2222', 'credentials_reference' => 'nobody-configured-this',
            'status' => HostingNodeStatus::Active, 'account_count' => 0,
            'reconcile_attempted_at' => '2026-09-01 00:00:00', 'reconciled_at' => '2026-09-01 00:00:00',
        ]);
        $this->listings([]);

        $this->artisan('hosting:reconcile')
            ->expectsOutputToContain('0 nodes checked, 0 accounts compared, 0 disagreements recorded, 1 listings not read, 0 nodes failed.')
            ->assertSuccessful();
    }

    /**
     * The SQLSTATE is read as well as Laravel's detector. The detector knows a
     * serialization failure by its code, but a deadlock only by its English
     * message, and PostgreSQL words its messages in the server's
     * lc_messages: a deadlock reported in another language is still 40P01.
     * Inside a caller's transaction it is let out as it was thrown.
     */
    #[Test]
    public function a_deadlock_worded_in_another_language_inside_a_callers_transaction_is_let_out(): void
    {
        $this->refuseDriftNamed('poison', '40P01', 'interblocage détecté');
        $this->node('node-fails', '2026-09-01 00:00:00');
        $this->listings(['node-fails' => 'list[]=poison']);

        try {
            app(ReconcileHostingNodes::class)->execute();
            $this->fail('A deadlock the detector does not recognise by its message was swallowed.');
        } catch (QueryException $e) {
            $this->assertSame('40P01', $e->errorInfo[0] ?? null);
        }
    }

    #[Test]
    public function the_command_still_succeeds_when_a_listing_was_only_refused(): void
    {
        $this->node('node-refused', '2026-09-01 00:00:00');
        $this->listings(['node-refused' => 'list[]=bob,alice']);

        $this->artisan('hosting:reconcile')
            ->expectsOutputToContain('0 nodes checked, 0 accounts compared, 0 disagreements recorded, 1 listings not read, 0 nodes failed.')
            ->assertSuccessful();
    }

    /**
     * A real database refusal, raised inside the insert of one drift: the
     * shape of the varchar overflow, without depending on a column width.
     * Created inside the test's transaction, so it is gone with it.
     */
    private function refuseDriftNamed(string $reference, string $sqlState = '22001', string $message = 'value too long for this test'): void
    {
        DB::unprepared(<<<SQL
            create function r7_refuse_drift() returns trigger language plpgsql as \$\$
            begin
                if new.provider_reference = '{$reference}' then
                    raise exception '{$message}' using errcode = '{$sqlState}';
                end if;
                return new;
            end
            \$\$;
            create trigger r7_refuse_drift before insert on resource_drifts
                for each row execute function r7_refuse_drift();
            SQL);
    }

    private function node(string $slug, string $asked): HostingNode
    {
        config(['hosting.credentials.'.$slug => ['username' => 'admin', 'login_key' => 'da-login-key-'.$slug]]);

        return HostingNode::factory()->create([
            'slug' => $slug,
            'hostname' => $slug.'.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://'.$slug.'.lynomia.test:2222',
            'credentials_reference' => $slug,
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
            'reconcile_attempted_at' => $asked,
            'reconciled_at' => $asked,
        ]);
    }

    /**
     * @param  array<string, string>  $bodies  node slug => CMD_API_SHOW_USERS body
     */
    private function listings(array $bodies): void
    {
        Http::fake(static function (Request $request) use ($bodies) {
            foreach ($bodies as $slug => $body) {
                if (str_contains($request->url(), $slug.'.lynomia.test') && str_contains($request->url(), 'CMD_API_SHOW_USERS')) {
                    return Http::response($body, 200);
                }
            }

            return Http::response('error=1&text=not+faked', 200);
        });
    }

    private function logged(string $message, ?string $node = null): bool
    {
        foreach ($this->log->getRecords() as $record) {
            /** @var LogRecord $record */
            if ($record->message === $message && ($node === null || ($record->context['node'] ?? null) === $node)) {
                return true;
            }
        }

        return false;
    }
}
