<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;
use Lynomia\Modules\Monitoring\Infrastructure\Formatters\PrometheusTextFormatter;
use Lynomia\Modules\Monitoring\Infrastructure\MonitoringServiceProvider;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Collection has to be cheap, and cheapness has to be enforced rather than
 * intended.
 *
 * A metrics endpoint that takes ten seconds gets scraped every fifteen and
 * becomes the outage it was installed to detect: the collections overlap, each
 * one holding connections the application needs, and the first symptom is the
 * database running out of backends during whatever incident prompted someone
 * to look at the dashboard.
 *
 * The assertion that matters is not the absolute number of queries, which will
 * move as collectors are added. It is that the number does not change when the
 * amount of data does. That is the difference between a GROUP BY and a loop,
 * and it is invisible on a development database with forty rows.
 */
final class MetricsQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Generous on purpose: this is a guard against a collector quietly
     * acquiring a per-row query, not a target to optimise towards. The N+1
     * assertion below is the real test.
     *
     * Raised from 26 when the DNS and product collectors were added — nine
     * more GROUP BYs, each of them one query whatever the fleet does — and to
     * 33 for the backup retention gauge, whose three dispositions come back
     * from one query with three FILTERs rather than three queries, and to 35
     * for the two domain gauges and 36 for the WordPress one, which follow the
     * same shape: one query each, whatever the number of dispositions. The
     * number moves when collectors are added and must not move when data is.
     */
    // 36 for the platform, plus the one round trip the control centre
    // collector makes for all six of its tables.
    // 38: the scope addendum's domain-redemption series is one grouped query
    // over domain_operations, beside the disposition query that was there.
    // 39: zone imports by outcome, one grouped query over dns_zone_imports.
    private const int BUDGET = 40;

    private MetricsRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(MonitoringServiceProvider::class);
        $this->registry = $this->app->make(MetricsRegistry::class);

        // The database queue driver is the one with a query to count; the sync
        // driver the suite defaults to would hide it.
        config()->set('queue.default', 'database');
    }

    #[Test]
    public function collection_issues_a_bounded_number_of_queries(): void
    {
        $this->seedFleet(pools: 1, nodes: 1);

        $queries = $this->queriesDuring(fn () => $this->registry->collect());

        $this->assertLessThanOrEqual(
            self::BUDGET,
            count($queries),
            'Collection issued '.count($queries)." queries:\n".implode("\n", $queries),
        );
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_amount_of_data(): void
    {
        $this->seedFleet(pools: 1, nodes: 1);
        $small = $this->queriesDuring(fn () => $this->registry->collect());

        // Ten times the pools, nodes, orders, jobs and subscriptions. A
        // collector that loops — one query per pool to compute runway, say —
        // shows up here and nowhere else.
        $this->seedFleet(pools: 9, nodes: 9);
        $large = $this->queriesDuring(fn () => $this->registry->collect());

        $this->assertSame(
            count($small),
            count($large),
            'Query count grew from '.count($small).' to '.count($large)." when the data did:\n"
                .implode("\n", array_diff($large, $small)),
        );
    }

    #[Test]
    public function every_metric_family_is_produced_by_a_single_pass(): void
    {
        // No collector may be run twice to build the exposition — once for the
        // values and again for the labels, say. Two passes double the cost and
        // can disagree with each other between them.
        $this->seedFleet(pools: 2, nodes: 2);

        $collected = $this->registry->collect();
        $names = array_map(static fn ($metric): string => $metric->name, $collected);

        $this->assertSame($names, array_unique($names));
        $this->assertSame($names, array_values(array_unique($names)));
    }

    #[Test]
    public function a_repeated_scrape_within_the_cache_window_does_not_hit_the_database_again(): void
    {
        /*
         * An HA pair of Prometheus servers scrapes this endpoint twice per
         * interval by design, a timed-out scrape is retried while the first is
         * still running, and a human runs curl during an incident. A short TTL
         * turns a burst into one collection without ever making consecutive
         * scrapes look stale.
         */
        $this->app->register(MonitoringServiceProvider::class);

        config()->set('monitoring.metrics.enabled', true);
        config()->set('monitoring.metrics.token', 'test-token');
        config()->set('monitoring.metrics.path', 'metrics');
        config()->set('monitoring.metrics.cache_seconds', 10);

        require base_path('src/Modules/Monitoring/Http/Routes/metrics.php');

        $this->seedFleet(pools: 1, nodes: 1);

        $first = $this->queriesDuring(function (): void {
            $this->withToken('test-token')->get('/metrics')->assertOk();
        });

        $second = $this->queriesDuring(function (): void {
            $this->withToken('test-token')->get('/metrics')->assertOk();
        });

        $this->assertNotSame([], $first);
        $this->assertSame([], $second);
    }

    #[Test]
    public function a_scrape_still_succeeds_when_the_cache_store_is_unreachable(): void
    {
        /*
         * In production the cache store is Redis, and Redis is also the queue
         * backend — so the incident that takes the cache away is the same one
         * that takes the queue away. If Cache::remember() were allowed to
         * propagate, every scrape would 500 during that incident and the
         * platform would lose ALL of its metrics: orders awaiting provisioning,
         * MRR, IP runway, node capacity, none of which involve Redis at all.
         *
         * The collectors already handle this one layer down — QueueCollector
         * reports NaN rather than a comfortable zero when it cannot see the
         * queue — and that care is worth nothing if the request never reaches
         * them. A broken cache must degrade the scrape, not fail it.
         */
        $this->app->register(MonitoringServiceProvider::class);

        config()->set('monitoring.metrics.enabled', true);
        config()->set('monitoring.metrics.token', 'test-token');
        config()->set('monitoring.metrics.path', 'metrics');
        config()->set('monitoring.metrics.cache_seconds', 10);

        require base_path('src/Modules/Monitoring/Http/Routes/metrics.php');

        $this->seedFleet(pools: 1, nodes: 1);

        // A real cache repository over a store whose every operation fails the
        // way an unreachable Redis does. Not a mock with an expectation on it:
        // the assertion is on what the endpoint returns, not on what it called.
        Cache::swap(new Repository(new class extends ArrayStore
        {
            /*
             * Extends the real ArrayStore rather than implementing Store by
             * hand: an interface this test re-declares would drift from the
             * framework's on the next upgrade and start passing for the wrong
             * reason. Only the two operations Cache::remember() performs are
             * overridden, and both fail the way an unreachable Redis does.
             */
            public function get($key): mixed
            {
                throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
            }

            public function put($key, $value, $seconds): bool
            {
                throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
            }
        }));

        $response = $this->withToken('test-token')->get('/metrics');

        $response->assertOk();

        $body = (string) $response->getContent();

        // Not merely a 200: the numbers that have nothing to do with the cache
        // must still be in it.
        $this->assertStringContainsString('# TYPE lynomia_orders_total gauge', $body);
        $this->assertStringContainsString('lynomia_mrr_minor{', $body);
        $this->assertStringContainsString('lynomia_ip_pool_runway_days{', $body);
    }

    #[Test]
    public function rendering_the_exposition_touches_the_database_not_at_all(): void
    {
        // Formatting is pure. If it were not, a slow scrape would be
        // impossible to attribute between collection and rendering.
        $this->seedFleet(pools: 2, nodes: 2);
        $collected = $this->registry->collect();

        $queries = $this->queriesDuring(function () use ($collected): void {
            (new PrometheusTextFormatter)->render($collected);
        });

        $this->assertSame([], $queries);
    }

    /**
     * @return list<string>
     */
    private function queriesDuring(callable $work): array
    {
        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $work();

        // Laravel has no public API for detaching a query listener, so the
        // array is snapshotted and the closure left holding a reference it no
        // longer appends to any measurement being read.
        $captured = $queries;
        $queries = [];

        return $captured;
    }

    private function seedFleet(int $pools, int $nodes): void
    {
        Order::factory()->count($pools * 3)->create();
        Subscription::factory()->count($pools * 2)->create();

        $service = Service::factory()->create();
        ProvisioningJob::factory()->count($pools * 3)->for($service)->create([
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        for ($i = 0; $i < $nodes; $i++) {
            ComputeNode::factory()->create();
            HostingNode::factory()->create();
        }

        for ($i = 0; $i < $pools; $i++) {
            $pool = IpPool::factory()->create();
            $subnet = Subnet::factory()->for($pool, 'ipPool')->create();
            IpAddress::factory()->count(4)->for($subnet)->create();
        }

        DB::table('jobs')->insert([
            'queue' => 'provisioning',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
    }
}
