<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

/**
 * Two provisioning workers, two real database connections, one subnet.
 *
 * This is the test the module exists for. Two orders paid for in the same
 * second are two queue workers on two machines asking the same subnet for an
 * address at the same moment, and if they can both be handed 198.51.100.10 the
 * platform will build two VMs with one address and let ARP decide whose
 * networking works this minute.
 *
 * It deliberately does not use RefreshDatabase. That trait wraps the whole
 * test in one transaction on one connection, which makes a concurrency test
 * meaningless twice over: the second connection cannot see the fixtures, and
 * two queries issued on a single connection are serialised by definition and
 * can never race. Rows are therefore committed for real and cleaned up again
 * in tearDown.
 *
 * The interleaving is exact rather than hopeful. Worker A opens a transaction
 * and reserves, and is then frozen mid-transaction — its rows locked and its
 * writes invisible to anybody else — while worker B runs to completion on its
 * own connection. That is precisely the window SELECT ... FOR UPDATE SKIP
 * LOCKED exists to make safe.
 */
final class ConcurrentIpAllocationTest extends TestCase
{
    use CreatesIpamFixtures;

    private const string SECOND_CONNECTION = 'worker_b';

    private Subnet $subnet;

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
         * If SKIP LOCKED were ever dropped from the allocator, worker B would
         * block on worker A's row lock — and because A is frozen inside this
         * single-threaded test it would never be released, so the suite would
         * hang instead of failing. A lock timeout turns that into a visible
         * failure with a stack trace.
         */
        DB::connection(self::SECOND_CONNECTION)->statement("SET lock_timeout = '5s'");

        // 198.51.100.8/29: five allocatable hosts, .8 network, .15 broadcast,
        // .9 gateway.
        $this->subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($this->subnet);
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
    public function two_workers_on_separate_connections_never_receive_the_same_address(): void
    {
        $jobA = $this->createProvisioningJob();
        $jobB = $this->createProvisioningJob();

        // Worker A opens its transaction and takes an address. Its row lock is
        // held and its write is invisible to everyone else until it commits.
        DB::connection($this->defaultConnection)->beginTransaction();

        $reservedByA = app(IpAllocator::class)->reserve($this->subnet, $jobA)[0];

        $reservedByB = $this->asWorkerB(
            fn (): IpReservation => app(IpAllocator::class)->reserve($this->subnet, $jobB)[0],
        );

        // Only now does A finish. B did not wait for this.
        DB::connection($this->defaultConnection)->commit();

        $addressA = $this->addressOf($reservedByA->ip_address_id);
        $addressB = $this->addressOf($reservedByB->ip_address_id);

        $this->assertNotSame(
            $addressA,
            $addressB,
            'Two workers were handed the same address; SKIP LOCKED is not doing its job.',
        );

        // B took the next address in order rather than queueing for A's.
        $this->assertSame('198.51.100.10', $addressA);
        $this->assertSame('198.51.100.11', $addressB);

        // Both claims survived: neither worker had to be rolled back for the
        // other to succeed.
        $this->assertSame(2, IpReservation::query()->live()->count());
        $this->assertSame(3, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());

        foreach ([$reservedByA, $reservedByB] as $reservation) {
            $this->assertSame(
                IpAddressStatus::Reserved,
                IpAddress::query()->findOrFail($reservation->ip_address_id)->status,
            );
        }
    }

    #[Test]
    public function a_second_worker_sees_only_what_is_genuinely_free_and_refuses_to_over_allocate(): void
    {
        $jobA = $this->createProvisioningJob();
        $jobB = $this->createProvisioningJob();

        DB::connection($this->defaultConnection)->beginTransaction();

        // A takes four of the five hosts and holds them, uncommitted.
        app(IpAllocator::class)->reserve($this->subnet, $jobA, count: 4);

        try {
            $this->asWorkerB(function () use ($jobB): void {
                app(IpAllocator::class)->reserve($this->subnet, $jobB, count: 2);
            });

            $this->fail('The second worker must not be able to allocate addresses the first one is holding.');
        } catch (IpPoolExhaustedException $e) {
            // One address is genuinely free; the other four are locked, and a
            // locked row is not a candidate rather than something to wait for.
            $this->assertSame(1, $e->context()['available']);
            $this->assertSame(2, $e->context()['requested']);
        }

        DB::connection($this->defaultConnection)->commit();

        // B's failure left nothing behind, and A kept everything it took.
        $this->assertSame(4, IpReservation::query()->live()->count());
        $this->assertSame(1, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
    }

    #[Test]
    public function two_workers_between_them_take_each_address_exactly_once(): void
    {
        $jobA = $this->createProvisioningJob();
        $jobB = $this->createProvisioningJob();

        DB::connection($this->defaultConnection)->beginTransaction();

        $byA = app(IpAllocator::class)->reserve($this->subnet, $jobA, count: 3);

        $byB = $this->asWorkerB(
            fn (): array => app(IpAllocator::class)->reserve($this->subnet, $jobB, count: 2),
        );

        DB::connection($this->defaultConnection)->commit();

        $addresses = array_map(
            fn (IpReservation $reservation): string => $this->addressOf($reservation->ip_address_id),
            [...$byA, ...$byB],
        );

        sort($addresses);

        $this->assertSame([
            '198.51.100.10', '198.51.100.11', '198.51.100.12', '198.51.100.13', '198.51.100.14',
        ], $addresses);
        $this->assertCount(5, array_unique($addresses), 'An address was handed out twice.');
        $this->assertSame(0, IpAddress::query()->where('subnet_id', $this->subnet->id)->available()->count());
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

    private function addressOf(string $ipAddressId): string
    {
        return IpAddress::query()->findOrFail($ipAddressId)->address;
    }

    /**
     * Rows are committed for real here, so they have to be removed for real.
     * Children first: the foreign keys are what would otherwise refuse.
     */
    private function wipe(): void
    {
        foreach ([
            'ip_assignments',
            'ip_reservations',
            'reverse_dns_records',
            'ip_addresses',
            'subnets',
            'ip_pools',
            'networks',
            'provisioning_jobs',
            'datacenters',
            'regions',
        ] as $table) {
            DB::connection($this->defaultConnection)->table($table)->delete();
        }
    }
}
