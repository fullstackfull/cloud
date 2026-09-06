<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Monitoring\Application\Collectors\OrdersCollector;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;
use Lynomia\Modules\Monitoring\Infrastructure\Formatters\PrometheusTextFormatter;
use Lynomia\Modules\Monitoring\Infrastructure\MonitoringServiceProvider;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Domain\Enums\WebhookEventStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\WebhookEvent;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Monitoring\Concerns\ParsesExposition;
use Tests\TestCase;

/**
 * The numbers the exposition reports have to be the numbers in the database,
 * and the bytes it reports them in have to be bytes Prometheus will accept.
 *
 * The zero-filling assertions are the ones worth reading twice. A metric that
 * disappears when it has no data cannot be alerted on: `orders in manual review
 * > 0` never fires while the series does not exist, so "nothing to review" and
 * "the collector is broken" look identical from the alerting side, and the one
 * that matters is the one that stays silent.
 */
final class MetricsExpositionTest extends TestCase
{
    use ParsesExposition;
    use RefreshDatabase;

    private MetricsRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(MonitoringServiceProvider::class);
        $this->registry = $this->app->make(MetricsRegistry::class);
    }

    #[Test]
    public function the_exposition_parses_as_the_prometheus_text_format(): void
    {
        $this->seedFleet();

        $parsed = $this->parseExposition($this->render());

        // A handful of families of each type, so the parser has actually had to
        // deal with counters, gauges and a histogram rather than one shape.
        $this->assertSame('gauge', $parsed['types']['lynomia_orders_total']);
        $this->assertSame('counter', $parsed['types']['lynomia_failed_jobs_total']);
        $this->assertSame('histogram', $parsed['types']['lynomia_provisioning_duration_seconds']);

        foreach (array_keys($parsed['types']) as $family) {
            $this->assertArrayHasKey($family, $parsed['help'], "No HELP for {$family}.");
        }
    }

    #[Test]
    public function a_node_name_containing_a_quote_does_not_corrupt_the_scrape(): void
    {
        /*
         * Not a hypothetical. provider_name and slug are free text, and an
         * operator who names a node from a copy-pasted string can put a quote
         * or a backslash in one. If that escaped badly, Prometheus would reject
         * the entire response and the platform would have no metrics at all —
         * not one missing node, all of them.
         */
        ComputeNode::factory()->create(['provider_name' => 'pve-"01"\\edge']);
        HostingNode::factory()->create(['slug' => "web-01\r\ninjected"]);

        $parsed = $this->parseExposition($this->render());

        $this->assertSame(
            0.0,
            $parsed['samples'][$this->seriesKey('lynomia_node_capacity_ratio', [
                'cluster' => ComputeNode::query()->firstOrFail()->cluster->slug,
                'node' => 'pve-"01"\\edge',
                'dimension' => 'cpu',
            ])],
        );

        /*
         * The two control characters are handled differently on purpose. A
         * line feed has an escape in the format, so it survives as data and
         * round-trips; a carriage return has none, so it is replaced with a
         * space. Either way the sample stays on one line — the property that
         * decides whether Prometheus accepts the response or discards all of
         * it.
         */
        $labels = array_column($parsed['labels'], 'node');
        $this->assertContains("web-01 \ninjected", $labels);

        $this->assertStringContainsString('lynomia_hosting_node_disk_ratio{node="web-01 \\ninjected"}', $this->render());
    }

    #[Test]
    public function order_counts_aggregate_against_seeded_rows(): void
    {
        Order::factory()->count(3)->create(['status' => OrderStatus::Paid]);
        Order::factory()->count(2)->create(['status' => OrderStatus::QueuedForProvisioning]);
        Order::factory()->create(['status' => OrderStatus::ManualReview]);

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(3.0, $samples['lynomia_orders_total{status=paid}']);
        $this->assertSame(2.0, $samples['lynomia_orders_total{status=queued_for_provisioning}']);
        $this->assertSame(1.0, $samples['lynomia_orders_total{status=manual_review}']);
    }

    #[Test]
    public function every_order_status_is_present_even_with_no_rows(): void
    {
        $samples = $this->parseExposition($this->render())['samples'];

        foreach (OrderStatus::cases() as $status) {
            $key = 'lynomia_orders_total{status='.$status->value.'}';

            $this->assertArrayHasKey($key, $samples, "Missing series for {$status->value}.");
            $this->assertSame(0.0, $samples[$key]);
        }
    }

    #[Test]
    public function provisioning_jobs_aggregate_by_status_and_kind(): void
    {
        $service = Service::factory()->create();

        ProvisioningJob::factory()->count(4)->for($service)->create([
            'kind' => ProvisioningJobKind::CreateVps,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);
        ProvisioningJob::factory()->for($service)->create([
            'kind' => ProvisioningJobKind::CreateVps,
            'status' => ProvisioningJobStatus::Failed,
        ]);
        ProvisioningJob::factory()->count(2)->for($service)->create([
            'kind' => ProvisioningJobKind::ProvisionDedicated,
            'status' => ProvisioningJobStatus::NeedsReview,
        ]);

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(4.0, $samples['lynomia_provisioning_jobs_total{kind=create_vps,status=succeeded}']);
        $this->assertSame(1.0, $samples['lynomia_provisioning_jobs_total{kind=create_vps,status=failed}']);
        $this->assertSame(2.0, $samples['lynomia_provisioning_jobs_total{kind=provision_dedicated,status=needs_review}']);

        // The combination that has never happened still has a series, because
        // the alert on it must be able to fire the first time it does.
        $this->assertSame(0.0, $samples['lynomia_provisioning_jobs_total{kind=destroy_vps,status=failed}']);
    }

    #[Test]
    public function provisioning_durations_are_bucketed_cumulatively(): void
    {
        $service = Service::factory()->create();

        // 20s, 45s and 1200s. The buckets are cumulative, so each boundary must
        // include everything at or below it — the property histogram_quantile()
        // depends on and the one a hand-rolled histogram usually gets wrong.
        foreach ([20, 45, 1200] as $seconds) {
            ProvisioningJob::factory()->for($service)->create([
                'kind' => ProvisioningJobKind::CreateVps,
                'status' => ProvisioningJobStatus::Succeeded,
                'started_at' => now()->subSeconds($seconds),
                'finished_at' => now(),
            ]);
        }

        $samples = $this->parseExposition($this->render())['samples'];
        $prefix = 'lynomia_provisioning_duration_seconds_bucket{kind=create_vps,le=';

        $this->assertSame(0.0, $samples[$prefix.'10}']);
        $this->assertSame(1.0, $samples[$prefix.'30}']);
        $this->assertSame(2.0, $samples[$prefix.'60}']);
        $this->assertSame(2.0, $samples[$prefix.'900}']);
        $this->assertSame(3.0, $samples[$prefix.'1800}']);
        $this->assertSame(3.0, $samples[$prefix.'+Inf}']);

        $this->assertSame(3.0, $samples['lynomia_provisioning_duration_seconds_count{kind=create_vps}']);
        $this->assertSame(1265.0, $samples['lynomia_provisioning_duration_seconds_sum{kind=create_vps}']);

        // The +Inf bucket must equal _count, or histogram_quantile() has no
        // upper bound to interpolate towards and quietly returns NaN.
        $this->assertSame(
            $samples['lynomia_provisioning_duration_seconds_count{kind=create_vps}'],
            $samples[$prefix.'+Inf}'],
        );
    }

    #[Test]
    public function only_active_services_are_counted(): void
    {
        Service::factory()->count(2)->create(['kind' => 'vps', 'status' => ServiceStatus::Active]);
        Service::factory()->create(['kind' => 'vps', 'status' => ServiceStatus::Terminated]);
        Service::factory()->create(['kind' => 'shared_hosting', 'status' => ServiceStatus::Active]);

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(2.0, $samples['lynomia_services_active{kind=vps}']);
        $this->assertSame(1.0, $samples['lynomia_services_active{kind=shared_hosting}']);
        $this->assertSame(0.0, $samples['lynomia_services_active{kind=dedicated}']);
    }

    #[Test]
    public function queue_depth_and_failed_jobs_come_from_the_configured_backend(): void
    {
        // The suite runs on the sync driver, which has no queue to be behind
        // on. Pointing at the database driver exercises the branch that a
        // development deployment actually uses.
        config()->set('queue.default', 'database');

        $this->insertQueuedJob('default');
        $this->insertQueuedJob('provisioning');
        $this->insertQueuedJob('provisioning');

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'provisioning',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(1.0, $samples['lynomia_queue_depth{queue=default}']);
        $this->assertSame(2.0, $samples['lynomia_queue_depth{queue=provisioning}']);
        $this->assertSame(1.0, $samples['lynomia_failed_jobs_total']);
    }

    #[Test]
    public function ip_pool_runway_is_measured_from_assignment_history(): void
    {
        $pool = IpPool::factory()->create(['slug' => 'kw-public-v4']);
        $subnet = Subnet::factory()->for($pool, 'ipPool')->forBlock('198.51.100.0/24')->create();

        IpAddress::factory()->count(28)->for($subnet)->create();

        // Fourteen assignments over the fourteen-day observation window is one
        // a day, so twenty-eight free addresses are twenty-eight days.
        $assigned = IpAddress::factory()->count(14)->for($subnet)->assigned()->create();

        foreach ($assigned as $index => $address) {
            IpAssignment::factory()->for($address, 'ipAddress')->create([
                'assigned_at' => now()->subDays($index % 14),
            ]);
        }

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(28.0, $samples['lynomia_ip_pool_available{pool=kw-public-v4}']);
        $this->assertSame(28.0, $samples['lynomia_ip_pool_runway_days{pool=kw-public-v4}']);
    }

    #[Test]
    public function a_pool_with_no_measured_consumption_reports_infinite_runway(): void
    {
        /*
         * Not a large number, and not zero. Infinity is what the measurement
         * actually says, `runway < 30` is correctly false for it, and inventing
         * "9999 days" would put a reassuring figure on a dashboard whose real
         * meaning is "we have no idea".
         */
        $pool = IpPool::factory()->create(['slug' => 'quiet-pool']);
        $subnet = Subnet::factory()->for($pool, 'ipPool')->forBlock('203.0.113.0/29')->create();
        IpAddress::factory()->count(4)->for($subnet)->create();

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(INF, $samples['lynomia_ip_pool_runway_days{pool=quiet-pool}']);
        $this->assertStringContainsString('lynomia_ip_pool_runway_days{pool="quiet-pool"} +Inf', $this->render());
    }

    #[Test]
    public function capacity_ratios_report_allocation_rather_than_usage(): void
    {
        $node = ComputeNode::factory()->create([
            'provider_name' => 'pve-01',
            'cpu_cores' => 32,
            'allocated_cpu_cores' => 24,
            'memory_mib' => 262144,
            'allocated_memory_mib' => 65536,
            'storage_gib' => 8192,
            'allocated_storage_gib' => 0,
            // Reported usage is deliberately far from allocation: the metric
            // must follow what has been sold, not what is being used.
            'reported_cpu_usage' => 0.05,
        ]);

        HostingNode::factory()->create([
            'slug' => 'web-01',
            'disk_total_mib' => 1000,
            'disk_used_mib' => 750,
        ]);

        $samples = $this->parseExposition($this->render())['samples'];
        $cluster = $node->cluster->slug;

        $this->assertSame(0.75, $samples["lynomia_node_capacity_ratio{cluster={$cluster},dimension=cpu,node=pve-01}"]);
        $this->assertSame(0.25, $samples["lynomia_node_capacity_ratio{cluster={$cluster},dimension=memory,node=pve-01}"]);
        $this->assertSame(0.0, $samples["lynomia_node_capacity_ratio{cluster={$cluster},dimension=storage,node=pve-01}"]);
        $this->assertSame(0.75, $samples['lynomia_hosting_node_disk_ratio{node=web-01}']);
    }

    #[Test]
    public function a_hosting_node_that_has_never_synced_reports_zero_rather_than_vanishing(): void
    {
        HostingNode::factory()->create([
            'slug' => 'web-new',
            'disk_total_mib' => null,
            'disk_used_mib' => null,
        ]);

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(0.0, $samples['lynomia_hosting_node_disk_ratio{node=web-new}']);
    }

    #[Test]
    public function webhook_and_payment_failures_aggregate(): void
    {
        WebhookEvent::factory()->count(3)->create(['status' => WebhookEventStatus::Processed]);
        WebhookEvent::factory()->count(2)->create(['status' => WebhookEventStatus::Failed]);

        PaymentAttempt::factory()->count(2)->create(['status' => PaymentAttemptStatus::Failed]);
        PaymentAttempt::factory()->create(['status' => PaymentAttemptStatus::Succeeded]);

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(3.0, $samples['lynomia_webhook_events_total{provider=fake,status=processed}']);
        $this->assertSame(2.0, $samples['lynomia_webhook_events_total{provider=fake,status=failed}']);
        $this->assertSame(0.0, $samples['lynomia_webhook_events_total{provider=fake,status=ignored}']);
        $this->assertSame(2.0, $samples['lynomia_failed_payments_total']);
    }

    #[Test]
    public function webhook_events_are_a_gauge_because_a_retried_event_leaves_the_failed_state(): void
    {
        /*
         * WebhookEventStatus::Failed is deliberately not settled: the provider's
         * own redelivery retries it, and when that succeeds the row moves to
         * processed. So {status="failed"} goes DOWN in ordinary operation.
         *
         * Typed as a counter, that decrease is a counter reset to Prometheus,
         * and increase() then reports the pre-reset value as brand-new
         * failures — PaymentWebhookFailures pages at critical precisely because
         * the failures were cleared. The type declaration is what tells every
         * rule author which functions are safe over this family, so it is
         * asserted here alongside the behaviour that makes it true.
         */
        $events = WebhookEvent::factory()->count(4)->create(['status' => WebhookEventStatus::Failed]);

        $parsed = $this->parseExposition($this->render());

        $this->assertSame('gauge', $parsed['types']['lynomia_webhook_events_total']);
        $this->assertSame(4.0, $parsed['samples']['lynomia_webhook_events_total{provider=fake,status=failed}']);

        // The provider redelivers and two of them succeed.
        WebhookEvent::query()
            ->whereIn('id', $events->take(2)->pluck('id'))
            ->update(['status' => WebhookEventStatus::Processed->value]);

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(2.0, $samples['lynomia_webhook_events_total{provider=fake,status=failed}']);
    }

    #[Test]
    public function mrr_normalises_billing_periods_to_a_month_without_losing_a_fils(): void
    {
        // 9.000 KWD monthly, twice.
        Subscription::factory()->count(2)->create([
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9000,
        ]);

        // 120.000 KWD a year is 10.000 a month exactly.
        Subscription::factory()->create([
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Yearly,
            'recurring_amount_minor' => 120_000,
        ]);

        // 30.000 KWD a quarter is 10.000 a month exactly.
        Subscription::factory()->create([
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Quarterly,
            'recurring_amount_minor' => 30_000,
        ]);

        $samples = $this->parseExposition($this->render())['samples'];

        $this->assertSame(38_000.0, $samples['lynomia_mrr_minor{currency=KWD}']);
    }

    #[Test]
    public function mrr_reports_zero_for_the_platform_currency_before_the_first_subscription(): void
    {
        $samples = $this->parseExposition($this->render())['samples'];

        $currency = strtoupper((string) config('billing.default_currency'));

        $this->assertSame(0.0, $samples["lynomia_mrr_minor{currency={$currency}}"]);
    }

    #[Test]
    public function a_collector_that_throws_reports_itself_down_without_taking_the_scrape_with_it(): void
    {
        /*
         * One broken query must not cost the platform its queue depth, its
         * provisioning outcomes and its IP runway. The failure becomes data —
         * a zero on lynomia_metrics_collector_up — because absence itself
         * cannot be alerted on.
         */
        $registry = (new MetricsRegistry)->register(
            new class implements MetricsCollector
            {
                public function name(): string
                {
                    return 'exploding';
                }

                public function collect(): array
                {
                    throw new \RuntimeException('the database went away');
                }
            },
            $this->app->make(OrdersCollector::class),
        );

        $samples = $this->parseExposition(
            (new PrometheusTextFormatter)->render($registry->collect())
        )['samples'];

        $this->assertSame(0.0, $samples['lynomia_metrics_collector_up{collector=exploding}']);
        $this->assertSame(1.0, $samples['lynomia_metrics_collector_up{collector=orders}']);
        $this->assertArrayHasKey('lynomia_orders_total{status=draft}', $samples);
    }

    private function render(): string
    {
        return (new PrometheusTextFormatter)->render($this->registry->collect());
    }

    private function seedFleet(): void
    {
        Order::factory()->create(['status' => OrderStatus::Paid]);
        Service::factory()->create(['kind' => 'vps', 'status' => ServiceStatus::Active]);
        ProvisioningJob::factory()->create([
            'status' => ProvisioningJobStatus::Succeeded,
            'started_at' => now()->subSeconds(42),
            'finished_at' => now(),
        ]);
        WebhookEvent::factory()->create();
        Subscription::factory()->create();
        ComputeNode::factory()->create();
        HostingNode::factory()->create();

        $pool = IpPool::factory()->create();
        $subnet = Subnet::factory()->for($pool, 'ipPool')->forBlock('192.0.2.0/29')->create();
        IpAddress::factory()->count(4)->for($subnet)->create();
    }

    private function insertQueuedJob(string $queue): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
    }
}
