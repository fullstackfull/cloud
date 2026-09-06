<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Payments\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Provider webhooks
|--------------------------------------------------------------------------
|
| Unauthenticated in the session sense and authenticated by provider signature
| instead. No CSRF, no session, no cookies — a provider is not a browser, and
| requiring a CSRF token here would simply mean the endpoint never worked.
|
| A service is never provisioned because a browser landed on a success URL.
| Provisioning follows a verified webhook, or an explicit server-side retrieve
| through /api/v1/payments/confirm — never a redirect.
|
*/

Route::post('{provider}', WebhookController::class)
    // Constrained to the providers the platform actually implements, so an
    // arbitrary path segment cannot reach the registry and turn a 404 into a
    // container resolution error.
    ->whereIn('provider', ['stripe', 'myfatoorah', 'fake'])
    ->name('receive');
