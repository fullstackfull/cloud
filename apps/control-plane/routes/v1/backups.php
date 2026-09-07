<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Backups\Http\Controllers\BackupController;

/*
 * backups — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer.
 *
 * Nested under a machine on purpose. A backup is of something, and a flat
 * /backups collection would need a service id in the query string — an id from
 * another account, submitted by a client, is exactly the shape this API avoids
 * everywhere else. Nesting means the scope is established by the path and
 * cannot be omitted.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here yet
 * ---------------------------------------------------------------------------
 *
 * **No delete.** Deleting a backup is irreversible and interacts with the
 * datastore's own prune policy, which the platform does not own. Until the
 * platform can say what a customer's retention actually is, a delete button
 * would remove a copy on the strength of a policy nobody has agreed.
 *
 * Both are recorded in docs/build-status.md as not implemented rather than
 * left to be discovered as a missing route.
 */

Route::prefix('vps/{vm}/backups')->as('backups.')->group(function (): void {
    Route::get('/', [BackupController::class, 'index'])->name('index');

    /*
     * A tighter limiter than the group's ceiling. A backup costs real
     * datastore space and real I/O on a node other customers are running on,
     * and there is no legitimate client that asks for them faster than this.
     * The prefix keeps this allowance separate from every other numeric
     * limiter in the application — without it, a browser refreshing a list
     * would spend the allowance for taking one.
     */
    Route::post('/', [BackupController::class, 'store'])
        ->middleware('throttle:10,1,backup-create:')
        ->name('store');

    Route::get('{backup}', [BackupController::class, 'show'])->name('show');

    /*
     * Restore. The confirmation flow this was waiting for now exists: the
     * caller must send the machine's hostname exactly, and the action compares
     * it with hash_equals and refuses to case-fold, because it is not a lookup
     * — it is evidence that a person read the screen.
     *
     * Limited harder than taking a backup and separately from it. A restore
     * writes over live disks, and the one thing that must not happen is a
     * client retrying its way into two concurrent restores over the same
     * machine; the action refuses that as well, and the limiter means it is
     * not being asked to refuse it fifty times a second.
     */
    Route::post('{backup}/restore', [BackupController::class, 'restore'])
        ->middleware('throttle:5,1,backup-restore:')
        ->name('restore');
});
