<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Provisioning\Http\Controllers\ServiceController;
use Lynomia\Modules\Provisioning\Http\Controllers\ServiceEventController;

/*
 * services — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * Three routes, and the shortness of the list is the point
 * ---------------------------------------------------------------------------
 *
 * This is the index across everything a customer bought — VPS, dedicated
 * server, hosting account — and it is read-only. Acting on a service belongs
 * to the module that fulfils it: a reboot, a reinstall or a resize provisions
 * hardware, needs its own idempotency key and its own authorisation, and a
 * generic POST /services/{service}/actions that dispatched by `kind` would be
 * one endpoint carrying every one of those decisions at once.
 *
 * **No customer id anywhere.** Not in the path, not in the query string, not
 * in a body. The account is resolved once by the `customer` middleware from
 * the authenticated principal, and `{service}` is resolved *through* that
 * account — hence a plain string parameter rather than implicit model binding,
 * which would fetch the row globally before anyone could scope it, and would
 * 403-or-404 on an id the caller was never allowed to name.
 *
 * **No job id.** The history is reached through the service that owns it, so
 * there is no GET /provisioning-jobs/{job} and no id on this surface that
 * names a row the acting customer does not already own.
 *
 * **Nothing retries a timed-out build.** There is no POST that re-runs failed
 * provisioning work. A timeout means the platform stopped waiting, not that
 * the provider stopped working; retrying one from an HTTP endpoint is how a
 * customer ends up with two servers and one of them billed to nobody. Those
 * jobs settle in `needs_review`, this surface reports them honestly as
 * `under_review`, and a person decides.
 */

Route::get('services', [ServiceController::class, 'index'])->name('services.index');
Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');

Route::get('services/{service}/events', [ServiceEventController::class, 'index'])
    ->name('services.events.index');
