<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Ipam\Http\Controllers\IpAddressController;

/*
 * ipam — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * Three routes, and the several that are deliberately absent
 * ---------------------------------------------------------------------------
 *
 * **No customer id, anywhere.** Not in a path, not in a query string, not in a
 * body. The account is resolved once by the `customer` middleware from the
 * authenticated principal, and `{assignment}` is resolved *through* that
 * account — hence a plain string parameter rather than implicit model binding,
 * which would fetch the row globally before anything could scope it and would
 * answer 403-or-404 on an id the caller was never allowed to name.
 *
 * **Nothing about the address space itself.** There is no GET /ips/pools, no
 * /subnets, no /networks and no endpoint that reports free capacity. A pool is
 * the platform's inventory: its size is how much room is left before the next
 * purchase, its contents are every other customer's addresses, and its subnet
 * boundaries are a map of where a scan would be worth someone's time. The
 * customer surface publishes the addresses a customer holds and the two facts
 * needed to configure them.
 *
 * **No quarantine list, and no history.** A released address is not shown, not
 * listed, and cannot be named: between release and reallocation it is nobody's,
 * and after reallocation it is somebody else's. The assignment table keeps that
 * history forever — it is what an abuse report is answered from — and this
 * surface is not where it is read.
 *
 * **No allocate and no release.** Addresses arrive with a service and leave
 * with it. An endpoint that handed out an address on request would be a way to
 * drain a public pool with a loop, and one that released an address would let a
 * customer detach the address their own machine is answering on.
 *
 * ---------------------------------------------------------------------------
 * Reverse DNS
 * ---------------------------------------------------------------------------
 *
 * PUT rather than POST, because a PTR is a value on an address rather than a
 * collection to append to: one address has one record, and sending the same
 * hostname twice converges. There is no idempotency key for the same reason —
 * see SetReverseDnsRequest and the action — and no DELETE, because a customer
 * removing their PTR is not something the platform can act on usefully; an
 * address with no reverse record is the platform's own default name, which is
 * an operator's to set.
 */

Route::prefix('ips')->as('ips.')->group(function (): void {
    Route::get('/', [IpAddressController::class, 'index'])->name('index');
    Route::get('{assignment}', [IpAddressController::class, 'show'])->name('show');

    /*
     * A narrower limiter than the group's `throttle:api`, and not to protect
     * the platform — the group's limiter already does that. Every accepted
     * request here becomes a write against a third-party zone API whose own
     * rate limit is shared by the whole estate, so a client in a retry loop on
     * one address would spend the budget every other customer's records need.
     */
    Route::put('{assignment}/rdns', [IpAddressController::class, 'setReverseDns'])
        ->middleware('throttle:10,1,rdns-update:')
        ->name('rdns.update');
});
