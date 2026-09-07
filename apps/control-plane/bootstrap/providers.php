<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Lynomia\Modules\Payments\Infrastructure\PaymentsServiceProvider;
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
];
