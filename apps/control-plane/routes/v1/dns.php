<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Dns\Http\Controllers\DnsRecordController;
use Lynomia\Modules\Dns\Http\Controllers\DnsZoneController;

/*
 * dns — customer surface.
 *
 * Inside the business group, so authenticated, verified, throttled and
 * resolved to one account before anything here runs. Every zone is reached
 * through a `where` on that account, and every record through its zone, so an
 * id from somewhere else is a 404.
 *
 * Reading takes `service.view` and writing takes `service.manage` — the same
 * pair the machines use, because DNS is a technical contact's work and the
 * role that may rebuild a server should not be locked out of the name that
 * points at it. Giving a domain up stays under `service.manage` rather than
 * `service.destroy`: it costs the account nothing and ends no service, and the
 * typed confirmation is what stands between a click and an outage.
 */

Route::prefix('dns')->as('dns.')->group(function (): void {
    Route::get('zones', [DnsZoneController::class, 'index'])->name('zones.index');

    Route::post('zones', [DnsZoneController::class, 'store'])
        // Tighter than the shared ceiling. Each one of these creates a zone at
        // a third party whose API has its own rate limit, and a loop here
        // would spend the whole platform's allowance.
        ->middleware('throttle:10,1')
        ->name('zones.store');

    Route::get('zones/{zone}', [DnsZoneController::class, 'show'])
        ->whereUlid('zone')
        ->name('zones.show');

    Route::delete('zones/{zone}', [DnsZoneController::class, 'destroy'])
        ->whereUlid('zone')
        ->middleware('throttle:5,1')
        ->name('zones.destroy');

    Route::get('zones/{zone}/records', [DnsRecordController::class, 'index'])
        ->whereUlid('zone')
        ->name('records.index');

    Route::post('zones/{zone}/records', [DnsRecordController::class, 'store'])
        ->whereUlid('zone')
        ->middleware('throttle:60,1')
        ->name('records.store');

    Route::patch('zones/{zone}/records/{record}', [DnsRecordController::class, 'update'])
        ->whereUlid('zone')
        ->whereUlid('record')
        ->middleware('throttle:60,1')
        ->name('records.update');

    Route::delete('zones/{zone}/records/{record}', [DnsRecordController::class, 'destroy'])
        ->whereUlid('zone')
        ->whereUlid('record')
        ->middleware('throttle:60,1')
        ->name('records.destroy');
});
