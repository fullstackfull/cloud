<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
// Aliased because Lynomia\Providers\InfrastructureServiceProvider already
// holds that name and wires something else entirely — the provisioning
// engine to the things it provisions.
use Lynomia\Modules\Monitoring\Infrastructure\MonitoringServiceProvider;
use Lynomia\Modules\Notifications\Infrastructure\NotificationServiceProvider;
use Lynomia\Modules\Payments\Infrastructure\PaymentsServiceProvider;
use Lynomia\Modules\Providers\Infrastructure\ProvidersServiceProvider;
use Lynomia\Providers\ApiTokenServiceProvider;
use Lynomia\Providers\AuthorizationServiceProvider;
use Lynomia\Providers\DomainServiceProvider;
use Lynomia\Providers\EventServiceProvider;
use Lynomia\Providers\InfrastructureServiceProvider;
use Lynomia\Providers\NotificationRoutingServiceProvider;
use Lynomia\Providers\ProviderRegistryServiceProvider;
use Lynomia\Providers\RateLimitServiceProvider;
use Lynomia\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    AuthorizationServiceProvider::class,
    RateLimitServiceProvider::class,
    TenancyServiceProvider::class,
    NotificationRoutingServiceProvider::class,
    ApiTokenServiceProvider::class,
    ProviderRegistryServiceProvider::class,
    PaymentsServiceProvider::class,
    EventServiceProvider::class,
    InfrastructureServiceProvider::class,
    ProvidersServiceProvider::class,
    NotificationServiceProvider::class,
    /*
     * Registers the sixteen metric collectors with the registry that serves
     * /metrics.
     *
     * It was missing, and the consequence was total: without it
     * MetricsRegistry resolves to a bare instance with zero collectors, so a
     * booted application exported only the two families the registry describes
     * itself with, and every alert rule in infrastructure/monitoring read an
     * absent series. A rule over an absent series does not fail — it is
     * silent, which on a dashboard and in an alert list is indistinguishable
     * from passing.
     *
     * The monitoring tests did not catch it because each one registers this
     * provider itself before measuring, so they were measuring a registry the
     * application never had. Phase 30B-SIM's unified preflight found it by
     * asking the running application what it exports, which is the difference
     * between reading code and measuring it.
     */
    MonitoringServiceProvider::class,
];
