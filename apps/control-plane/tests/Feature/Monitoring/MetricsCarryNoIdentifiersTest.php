<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Infrastructure\MonitoringServiceProvider;
use Lynomia\Modules\Notifications\Domain\Enums\DeliveryStatus;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A metric is scraped for ever; a label is a decision you cannot take back.
 *
 * Two failures, and both are one careless line away at any time.
 *
 * A series labelled by customer, service, machine, hostname or address grows
 * one new series per customer and never releases the old ones. Prometheus
 * keeps them in memory, the alerting rules slow down, and the moment it hurts
 * most is during the incident that created the labels — a fleet-wide outage
 * being exactly when the platform would emit thousands of new ones.
 *
 * And a label carries its value into a system with weaker access control than
 * the platform's own. A dashboard is not the database: an email address or a
 * customer's hostname in a metric is that data published to everyone who can
 * read a graph, permanently, including in screenshots.
 *
 * So the check is on names and on values. A label called `node` holding a
 * hostname would pass a name-only rule, which is why what is in them is
 * inspected too.
 */
final class MetricsCarryNoIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Label names that are always wrong here, whatever they hold.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_NAMES = [
        'customer', 'customer_id', 'service', 'service_id', 'subscription', 'subscription_id',
        'user', 'user_id', 'email', 'hostname', 'host', 'fqdn', 'domain',
        'ip', 'ip_address', 'address', 'machine', 'machine_id', 'vm', 'vm_id', 'vmid',
        'provider_resource_id', 'provider_reference', 'resource_id', 'session', 'session_id',
        'permit', 'permit_id', 'token', 'id',
    ];

    /**
     * A ceiling on the whole exposition.
     *
     * Not a target — the fleet below is small — but a tripwire. A collector
     * that started emitting one series per row would blow through this long
     * before it reached production.
     */
    private const int MAX_SERIES = 1_000;

    #[Test]
    public function no_metric_is_labelled_by_anything_that_identifies_somebody(): void
    {
        $this->aFleetWithRealIdentifiers();

        $offences = [];
        $series = 0;

        foreach ($this->collect() as $metric) {
            foreach ($metric->samples as $sample) {
                $series++;

                foreach ($sample->labels as $name => $value) {
                    if (in_array(mb_strtolower((string) $name), self::FORBIDDEN_NAMES, true)) {
                        $offences[] = sprintf('%s is labelled `%s`', $metric->name, $name);

                        continue;
                    }

                    $shape = $this->whatThatLooksLike((string) $value);

                    if ($shape !== null) {
                        $offences[] = sprintf(
                            '%s has a label `%s` holding %s',
                            $metric->name,
                            $name,
                            $shape,
                        );
                    }
                }
            }
        }

        sort($offences);

        $this->assertSame([], array_values(array_unique($offences)), sprintf(
            "These metrics carry identifying labels:\n  %s",
            implode("\n  ", array_unique($offences)),
        ));

        $this->assertGreaterThan(0, $series, 'The scrape produced no series, so this proved nothing.');

        $this->assertLessThanOrEqual(self::MAX_SERIES, $series, sprintf(
            'The exposition is %d series for a fleet of a handful of rows.',
            $series,
        ));
    }

    /**
     * What a label value looks like, or null if it looks like a category.
     */
    private function whatThatLooksLike(string $value): ?string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value) === 1) {
            return 'a ULID';
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return 'an email address';
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return 'an IP address';
        }

        // A dotted name with a TLD-shaped tail: web-kw-01.customer.example.
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+){2,}$/i', $value) === 1) {
            return 'a hostname';
        }

        return null;
    }

    /**
     * A fleet whose rows all carry the kind of value that must not escape.
     *
     * The point is that every identifier in the database is a real one, so a
     * collector that put any of them in a label produces a failure here rather
     * than an innocuous-looking `test` string.
     */
    private function aFleetWithRealIdentifiers(): void
    {
        $customer = Customer::factory()->create([
            'billing_email' => 'finance@customer.example',
            'currency' => 'KWD',
            'country' => 'KW',
        ]);

        $service = Service::factory()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'label' => 'web-kw-01.customer.example',
        ]);

        ProvisioningJob::factory()->count(2)->for($service)->create([
            'customer_id' => $customer->getKey(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        ComputeNode::factory()->create(['provider_name' => 'pve-kw-03']);

        $pool = IpPool::factory()->create();
        $subnet = Subnet::factory()->for($pool, 'ipPool')->create();
        IpAddress::factory()->count(2)->for($subnet)->create();

        $notification = Notification::factory()->create(['customer_id' => $customer->getKey()]);

        NotificationDelivery::query()->create([
            'notification_id' => $notification->getKey(),
            'channel' => NotificationChannel::Email,
            'status' => DeliveryStatus::Failed,
            'destination' => 'finance@customer.example',
        ]);
    }

    /**
     * @return list<Metric>
     */
    private function collect(): array
    {
        $this->app->register(MonitoringServiceProvider::class);

        return $this->app->make(MetricsRegistry::class)->collect();
    }
}
