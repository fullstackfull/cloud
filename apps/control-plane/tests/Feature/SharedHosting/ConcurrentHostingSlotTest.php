<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\SharedHosting\Application\Actions\ReserveHostingNodeCapacity;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\NodeAtCapacityException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two provisioning workers, two real database connections, one node with one
 * slot left.
 *
 * This is the test the reservation action exists for. Two orders paid for in
 * the same second are two queue workers on two machines, and scoring
 * deliberately reads without a lock — so both can choose the same node at the
 * same moment and both be right when they choose. If they can then both commit,
 * the node passes its ceiling, and on a shared machine the bill for that
 * arrives as every site on it slowing down together while support tries to work
 * out which account is responsible.
 *
 * It deliberately does not use RefreshDatabase. That trait wraps the whole test
 * in one transaction on one connection, which makes a concurrency test
 * meaningless twice over: the second connection cannot see the fixtures, and
 * two queries issued on a single connection are serialised by definition and
 * can never race. Rows are therefore committed for real and cleaned up again in
 * tearDown.
 *
 * The interleaving is exact rather than hopeful. Worker A opens a transaction
 * and reserves, and is then frozen mid-transaction — its row locked and its
 * write invisible to anybody else — while worker B runs on its own connection.
 */
final class ConcurrentHostingSlotTest extends TestCase
{
    private const string SECOND_CONNECTION = 'worker_b';

    private HostingNode $node;

    private HostingPackage $package;

    private Customer $customer;

    private string $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = (string) config('database.default');
        $this->wipe();

        // A genuinely separate connection to the same database: same
        // credentials, a different PDO handle, and therefore a different
        // transaction and a different set of locks.
        config([
            'database.connections.'.self::SECOND_CONNECTION => config(
                'database.connections.'.$this->defaultConnection,
            ),
        ]);

        /*
         * Worker B will genuinely block on worker A's row lock — that is the
         * point — and because A is frozen inside this single-threaded test the
         * lock would never be released. A short lock timeout turns a hang into
         * a visible, assertable failure.
         */
        DB::connection(self::SECOND_CONNECTION)->statement("SET lock_timeout = '3s'");

        // The disk threshold is put out of the way so the arithmetic in this
        // test is about the lock, not about policy.
        config(['hosting.scheduler.max_disk_used_percent' => 100]);

        $this->customer = Customer::factory()->create();
        $this->package = HostingPackage::factory()->create();

        // One slot left: the ceiling is 10 and nine accounts are already here.
        $this->node = HostingNode::factory()->create([
            'panel' => HostingPanel::Cpanel,
            'max_accounts' => 10,
            'account_count' => 9,
            'disk_total_mib' => 1_048_576,
            'disk_used_mib' => 104_857,
        ]);
    }

    protected function tearDown(): void
    {
        // A test that failed mid-transaction must not leave the connection
        // holding locks for the next one.
        while (DB::connection($this->defaultConnection)->transactionLevel() > 0) {
            DB::connection($this->defaultConnection)->rollBack();
        }

        DB::purge(self::SECOND_CONNECTION);
        $this->wipe();

        parent::tearDown();
    }

    #[Test]
    public function two_workers_on_separate_connections_cannot_both_take_the_last_slot(): void
    {
        // Worker A opens its transaction and takes the slot. The row lock is
        // held and the write is invisible to everyone else until it commits.
        DB::connection($this->defaultConnection)->beginTransaction();

        $this->reserve('acmeone');

        try {
            $this->asWorkerB(fn (): HostingAccount => $this->reserve('acmetwo'));

            $this->fail('The second worker read and wrote the node while the first was holding it.');
        } catch (QueryException $e) {
            /*
             * B waited for A's lock rather than proceeding, which is the whole
             * mechanism: without SELECT ... FOR UPDATE it would have read the
             * pre-reservation row, decided there was a slot, and written its
             * own increment over A's.
             */
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        }

        // Only now does A finish. B did not get to write anything while it ran.
        DB::connection($this->defaultConnection)->commit();

        try {
            $this->asWorkerB(fn (): HostingAccount => $this->reserve('acmetwo'));

            $this->fail('The node took an eleventh account onto a ceiling of ten.');
        } catch (NodeAtCapacityException $e) {
            // B's retry now reads the committed row under its own lock and
            // discovers the world moved. This is the re-check earning its keep:
            // B passed scoring against exactly the same node.
            $this->assertSame((string) $this->node->getKey(), $e->context()['node_id']);
            $this->assertSame('account_limit_reached', $e->context()['reason']);
        }

        $this->assertSame(10, $this->freshNode()->account_count);
        $this->assertSame(1, HostingAccount::on($this->defaultConnection)->count());
    }

    #[Test]
    public function the_worker_that_commits_first_wins_and_the_second_is_refused(): void
    {
        // No open transaction this time: A simply gets there first, which is
        // the ordinary case. The row both workers scored against is identical.
        $stale = $this->freshNode();

        $this->reserve('acmeone', $stale);

        $this->expectException(NodeAtCapacityException::class);

        // B is holding a copy of the node from before A committed — precisely
        // what a job that sat in a queue for a few seconds holds.
        $this->asWorkerB(fn (): HostingAccount => $this->reserve('acmetwo', $stale));
    }

    #[Test]
    public function a_retried_job_finds_its_own_reservation_instead_of_taking_a_second_slot(): void
    {
        /*
         * A worker killed after the panel accepted a create comes back and runs
         * the job from the top. Without the (node, username) lookup the retry
         * commits a second slot that release can never give back — there is
         * only ever one account to terminate — so the node loses a slot
         * permanently and silently on every retry.
         */
        $first = $this->reserve('acmeone');
        $second = $this->reserve('acmeone');

        $this->assertTrue($first->is($second));
        $this->assertSame(10, $this->freshNode()->account_count);
        $this->assertSame(1, HostingAccount::on($this->defaultConnection)->count());
    }

    #[Test]
    public function releasing_a_slot_gives_exactly_one_back(): void
    {
        $this->reserve('acmeone');

        $this->assertSame(10, $this->freshNode()->account_count);

        app(ReserveHostingNodeCapacity::class)->release($this->freshNode());

        $this->assertSame(9, $this->freshNode()->account_count);
    }

    private function reserve(string $username, ?HostingNode $node = null): HostingAccount
    {
        return app(ReserveHostingNodeCapacity::class)->execute(
            node: $node ?? $this->node,
            username: $username,
            primaryDomain: $username.'.example.test',
            customerId: (string) $this->customer->getKey(),
            package: $this->package,
        );
    }

    /**
     * Run a closure as if it were another worker: its own connection, its own
     * transaction, its own locks. Worker A's open transaction belongs to the
     * connection instance it began on and is untouched by the swap.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $worker
     * @return TReturn
     */
    private function asWorkerB(callable $worker): mixed
    {
        DB::setDefaultConnection(self::SECOND_CONNECTION);

        try {
            return $worker();
        } finally {
            DB::setDefaultConnection($this->defaultConnection);
        }
    }

    private function freshNode(): HostingNode
    {
        return HostingNode::on($this->defaultConnection)->findOrFail($this->node->getKey());
    }

    /**
     * Children first: the foreign keys are what would otherwise refuse.
     */
    private function wipe(): void
    {
        foreach ([
            'hosting_accounts',
            'hosting_nodes',
            'hosting_packages',
            'datacenters',
            'regions',
            'services',
            'customers',
        ] as $table) {
            DB::connection((string) config('database.default'))->table($table)->delete();
        }
    }
}
