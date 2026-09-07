<?php

declare(strict_types=1);

/*
 * catalog — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 */

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Catalog\Http\Controllers\PlanController;
use Lynomia\Modules\Catalog\Http\Controllers\ProductController;

/*
 * Browsing is read-only and identical for every customer except for the
 * currency it is quoted in, so nothing here needs a narrower middleware than
 * the group already applies. Route model binding is deliberately not used: it
 * would resolve a product by primary key before the active/public predicate
 * ran, which is how a withdrawn product stays reachable by direct link.
 */
Route::prefix('catalog')->as('catalog.')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'show'])->name('products.show');

    Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
});
