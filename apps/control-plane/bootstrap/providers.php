<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
// Aliased because Lynomia\Providers\InfrastructureServiceProvider already
// holds that name and wires something else entirely — the provisioning
// engine to the things it provisions.
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
];
