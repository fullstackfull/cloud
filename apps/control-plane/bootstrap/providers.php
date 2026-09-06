<?php

use App\Providers\AppServiceProvider;
use Lynomia\Providers\AuthorizationServiceProvider;
use Lynomia\Providers\DomainServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    AuthorizationServiceProvider::class,
];
