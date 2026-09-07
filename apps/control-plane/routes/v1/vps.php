<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Vps\Http\Controllers\VpsController;

/*
 * vps — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * Why {vm} is a plain string
 * ---------------------------------------------------------------------------
 *
 * No implicit model binding anywhere on this file. Binding would fetch the
 * machine globally, before anything could scope it, and the controller would
 * then be holding another tenant's row while it decided what to say about it.
 * Every id here is resolved *through* the acting customer's own services, so
 * an id from another account is not found rather than found-and-refused.
 *
 * No route accepts a customer id, a service id, a node or a cluster — in the
 * path, the query string or the body. The account is resolved once by the
 * `customer` middleware from the authenticated principal.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here
 * ---------------------------------------------------------------------------
 *
 * **No create and no destroy.** A machine comes into existence by buying one:
 * POST /orders places the order, and the provisioning engine builds it. A
 * second door into CreateVpsHandler that skipped the order would build
 * hardware nobody is billed for. Destroying is the same in reverse — it ends a
 * subscription and releases addresses, so it belongs with the commercial
 * lifecycle rather than behind a DELETE on a hostname.
 *
 * **No resize.** ResizeVmRequest exists at the provider and the engine has a
 * `resize` kind, but a resize changes what the customer pays; publishing it
 * here would let a machine grow without an invoice moving.
 *
 * **No reset and no force flag.** The provider offers resetVm() — the hard
 * counterpart of reboot — and nothing dispatches it. A customer who needs one
 * asks for `stop` and then `start`: two deliberate requests rather than one
 * word that quietly means "and lose whatever was in flight".
 *
 * **Nothing that polls the provider.** There is no GET on a job's provider
 * task and no "check if it finished yet" route that reaches a hypervisor. The
 * engine records outcomes; an endpoint that asked the cluster on every poll
 * would give every customer a way to generate load on the node their
 * neighbours are running on.
 */

Route::prefix('vps')->as('vps.')->group(function (): void {
    Route::get('/', [VpsController::class, 'index'])->name('index');
    Route::get('{vm}', [VpsController::class, 'show'])->name('show');

    /*
     * A tighter limiter than the shared `throttle:api` ceiling the group
     * already applies. Power is safe to repeat with the same idempotency key,
     * but a flood of distinct machine ids — the shape of a stolen token being
     * used to take an account's fleet down — should be expensive well before
     * the general API limit notices.
     *
     * The third argument is not decoration. A numeric `throttle:N,1` keys on
     * the caller and nothing else, so every unprefixed numeric limiter in the
     * application shares one counter per user: without these prefixes, ten
     * console GETs — a shape a browser produces by itself on a refresh — spend
     * the reinstall allowance, and a customer whose machine has just been
     * compromised meets a 429 on the endpoint that rebuilds it.
     */
    Route::post('{vm}/power', [VpsController::class, 'power'])
        ->middleware('throttle:30,1,vps-power:')
        ->name('power');

    /*
     * Tighter still. A reinstall erases a disk, and there is no legitimate
     * client that issues them faster than this. The confirmation stops an
     * accident; the limiter bounds a compromise.
     */
    Route::post('{vm}/reinstall', [VpsController::class, 'reinstall'])
        ->middleware('throttle:10,1,vps-reinstall:')
        ->name('reinstall');

    /*
     * Each call mints a single-use credential. Left on the general limit, a
     * loop here would issue thousands of live permits a minute; at ten it
     * issues at most ten, each dead within sixty seconds.
     */
    Route::get('{vm}/console', [VpsController::class, 'console'])
        ->middleware('throttle:10,1,vps-console:')
        ->name('console');
});
