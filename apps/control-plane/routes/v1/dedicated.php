<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Dedicated\Http\Controllers\DedicatedServerController;

/*
 * dedicated — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * Four routes, and what is deliberately not among them
 * ---------------------------------------------------------------------------
 *
 * **No customer id, anywhere.** Not in a path, not in a query string, not in a
 * body. The account is resolved once by the `customer` middleware from the
 * authenticated principal, and `{server}` is resolved *through* that account —
 * hence a plain string parameter rather than implicit model binding, which
 * would fetch the row globally before anything could scope it and would answer
 * 403-or-404 on an id the caller was never allowed to name.
 *
 * **Nothing about the platform's own infrastructure.** There is no
 * GET /dedicated/{server}/bmc, no console or virtual-media route, and no
 * endpoint that returns a datacenter or a rack. A management controller is a
 * complete second computer attached to a physical host, reachable only from
 * the management network; the customer surface talks to it exclusively through
 * the three power verbs below, and never hands out an address, a credential
 * reference or a firmware level.
 *
 * **No hard power cut.** The provider interface has one — it is what an
 * unresponsive host eventually needs — and it is not published. `off` asks the
 * operating system over ACPI, and a machine that ignores it is recovered with
 * `cycle`, so the API never offers "pull the cord and leave it down". Cutting
 * power to a physical host loses whatever it had not flushed, and that is a
 * decision taken by somebody who has looked at why it will not stop.
 *
 * **No boot-order or PXE route.** A network install is authorised by the
 * platform, once, for one boot, with a recorded reason — see AuthorisePxeBoot.
 * An endpoint that let a client arm PXE would be an endpoint that leaves a
 * machine reinstalling itself the next time it reboots for any reason.
 *
 * ---------------------------------------------------------------------------
 * Idempotency
 * ---------------------------------------------------------------------------
 *
 * POST /dedicated/{server}/reinstall REQUIRES an `Idempotency-Key` header:
 *
 *     Idempotency-Key: 7f9c1a2e-rebuild-2026-09-06
 *
 * The header is the only source — a body field of the same name is overwritten
 * by it, because two places to put one key is two answers to "is this the same
 * request?" and the wrong answer erases a machine twice. The key is combined
 * with the machine's id and the operation, so one customer's "rebuild-1"
 * cannot collide with another's, and a repeat returns the job the first
 * request created.
 *
 * POST /dedicated/{server}/power deliberately does NOT take one. An
 * idempotency key is a promise that a repeat is free, and the platform has
 * nowhere to keep that promise for a request sent straight to a controller; a
 * header that was required and then ignored would be worse than none, because
 * a client would retry believing it was protected. See PowerActionRequest for
 * why the three verbs are safe to repeat without it.
 */

Route::prefix('dedicated')->as('dedicated.')->group(function (): void {
    Route::get('/', [DedicatedServerController::class, 'index'])->name('index');
    Route::get('{server}', [DedicatedServerController::class, 'show'])->name('show');

    /*
     * Both write routes carry a narrower limiter than the group's `throttle:api`.
     *
     * Not to protect the platform — the group's limiter already does that —
     * but to protect the machine. A client in a retry loop against `cycle` is
     * a physical host being reset every few seconds, which no operating system
     * survives cleanly and which no customer ever intended.
     *
     * The third argument is not decoration. A numeric `throttle:N,1` keys on
     * the caller and nothing else — `ThrottleRequests` builds its key as
     * `$prefix . resolveRequestSignature($request)`, and the signature of an
     * authenticated request is the user id — so every unprefixed numeric
     * limiter in the whole application shares one counter per user. Without
     * these prefixes, five power requests spend the entire reinstall
     * allowance, and the first rebuild a customer asks for in that minute is a
     * 429 for reasons no client can see. The prefixes give each verb its own
     * bucket, which is what the numbers were chosen to mean.
     */
    Route::post('{server}/power', [DedicatedServerController::class, 'power'])
        ->middleware('throttle:10,1,dedicated-power:')
        ->name('power');

    Route::post('{server}/reinstall', [DedicatedServerController::class, 'reinstall'])
        ->middleware('throttle:5,1,dedicated-reinstall:')
        ->name('reinstall');
});
