<?php

declare(strict_types=1);

/*
 * The metrics route.
 *
 * Not registered anywhere yet: routes/** belongs to the coordinator. Include
 * this file from routes/web.php (or a dedicated ops route file) so the module
 * owns its own definition and the path stays configurable:
 *
 *     require base_path('src/Modules/Monitoring/Http/Routes/metrics.php');
 *
 * Deliberately NOT inside the api/v1 or api/admin prefixes. Those groups carry
 * Sanctum, session and throttling middleware whose behaviour is tuned for
 * browsers and customer tokens; a scraper is neither, and a rate limiter shared
 * with the customer API would drop scrapes during exactly the traffic spike a
 * scrape is most needed for.
 */

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Monitoring\Http\Controllers\MetricsController;
use Lynomia\Modules\Monitoring\Http\Middleware\MetricsTokenGuard;

Route::get((string) config('monitoring.metrics.path', 'metrics'), MetricsController::class)
    ->middleware(MetricsTokenGuard::class)
    ->name('monitoring.metrics');
