<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Dns\Http\Controllers\DnsRecordController;
use Lynomia\Modules\Dns\Http\Controllers\DnsZoneController;
use Lynomia\Modules\Dns\Http\Controllers\DnsZoneTransferController;

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

/*
 * The third argument on every numeric limiter below is not decoration. A bare
 * `throttle:N,1` keys on the caller and nothing else — ThrottleRequests builds
 * its key as `$prefix . resolveRequestSignature($request)`, and the signature
 * of an authenticated request is the user id — so every unprefixed numeric
 * limiter in the application shares one counter per user. Without the
 * prefixes, editing six records spends the whole zone-deletion allowance, and
 * the customer's next request is a 429 for a reason no client can see.
 */
Route::prefix('dns')->as('dns.')->group(function (): void {
    Route::get('zones', [DnsZoneController::class, 'index'])->name('zones.index');

    Route::post('zones', [DnsZoneController::class, 'store'])
        // Tighter than the shared ceiling. Each one of these creates a zone at
        // a third party whose API has its own rate limit, and a loop here
        // would spend the whole platform's allowance.
        ->middleware('throttle:10,1,dns-zone-create:')
        ->name('zones.store');

    Route::get('zones/{zone}', [DnsZoneController::class, 'show'])
        ->whereUlid('zone')
        ->name('zones.show');

    Route::delete('zones/{zone}', [DnsZoneController::class, 'destroy'])
        ->whereUlid('zone')
        ->middleware('throttle:5,1,dns-zone-delete:')
        ->name('zones.destroy');

    Route::get('zones/{zone}/export', [DnsZoneTransferController::class, 'export'])
        ->whereUlid('zone')
        ->middleware('throttle:20,1,dns-zone-export:')
        ->name('zones.export');

    Route::post('zones/{zone}/import/plan', [DnsZoneTransferController::class, 'plan'])
        ->whereUlid('zone')
        ->middleware('throttle:20,1,dns-zone-import:')
        ->name('zones.import.plan');

    Route::post('zones/{zone}/import', [DnsZoneTransferController::class, 'apply'])
        ->whereUlid('zone')
        ->middleware('throttle:10,1,dns-zone-import:')
        ->name('zones.import.apply');

    Route::get('zones/{zone}/records', [DnsRecordController::class, 'index'])
        ->whereUlid('zone')
        ->name('records.index');

    Route::post('zones/{zone}/records', [DnsRecordController::class, 'store'])
        ->whereUlid('zone')
        ->middleware('throttle:60,1,dns-record-write:')
        ->name('records.store');

    Route::patch('zones/{zone}/records/{record}', [DnsRecordController::class, 'update'])
        ->whereUlid('zone')
        ->whereUlid('record')
        ->middleware('throttle:60,1,dns-record-write:')
        ->name('records.update');

    Route::delete('zones/{zone}/records/{record}', [DnsRecordController::class, 'destroy'])
        ->whereUlid('zone')
        ->whereUlid('record')
        ->middleware('throttle:60,1,dns-record-write:')
        ->name('records.destroy');
});
