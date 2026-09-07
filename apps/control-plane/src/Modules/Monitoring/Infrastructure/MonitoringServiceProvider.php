<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Monitoring\Application\Collectors\CapacityCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\IpamCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\NotificationCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\OrdersCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\PaymentsCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\ProvisioningCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\QueueCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\RevenueCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\SchedulerCollector;
use Lynomia\Modules\Monitoring\Application\Collectors\ServicesCollector;
use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;

/**
 * Container wiring for the monitoring module.
 *
 * The registry is a singleton with every collector attached at registration
 * time. Building the list here rather than discovering it means a collector
 * that is written and never wired is a visibly missing line in this file,
 * rather than a metric that silently does not exist and an alert that silently
 * never fires.
 *
 * Order is the order the exposition is collected in, not the order it is
 * served in — the registry sorts families by name before rendering.
 */
final class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetricsRegistry::class, static function ($app): MetricsRegistry {
            return (new MetricsRegistry)->register(
                $app->make(OrdersCollector::class),
                $app->make(ProvisioningCollector::class),
                $app->make(ServicesCollector::class),
                $app->make(QueueCollector::class),
                $app->make(IpamCollector::class),
                $app->make(CapacityCollector::class),
                $app->make(PaymentsCollector::class),
                $app->make(RevenueCollector::class),
                $app->make(SchedulerCollector::class),
                $app->make(NotificationCollector::class),
            );
        });
    }
}
