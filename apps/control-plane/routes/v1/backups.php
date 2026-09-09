<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Backups\Http\Controllers\BackupController;
use Lynomia\Modules\Backups\Http\Controllers\BackupFileController;

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

    /*
     * Deletion.
     *
     * `service.destroy`, not `service.manage`: a technical contact who may
     * rebuild a machine may not destroy the thing that would let it be rebuilt
     * afterwards. Behind the archive's own id typed back, and limited like the
     * restore rather than like the list — a client retrying its way through an
     * account's backups is what this limiter is for.
     *
     * The endpoint records a decision; the retention sweep acts on it after a
     * grace period. Nothing here calls a provider, and the row says
     * `delete_requested` rather than pretending the archive has gone.
     */
    Route::delete('{backup}', [BackupController::class, 'destroy'])
        ->middleware('throttle:5,1,backup-delete:')
        ->name('destroy');

    /*
     * Calling one off. The grace period exists so that a mis-click can be
     * undone, and a grace period with no way to use it is an hour of waiting
     * for nothing.
     */
    Route::post('{backup}/keep', [BackupController::class, 'keep'])->name('keep');

    /*
     * Files out of a backup, for the providers that can open one. Every
     * route here answers 409 `backup.file_level_unsupported` for the others,
     * and the backup row says which it is before anything is clicked.
     */
    Route::get('{backup}/files', [BackupFileController::class, 'index'])->name('files.index');

    Route::post('{backup}/files/downloads', [BackupFileController::class, 'download'])
        ->middleware('throttle:30,1,backup-file-download:')
        ->name('files.downloads.store');

    Route::post('{backup}/files/restore', [BackupFileController::class, 'restore'])
        ->middleware('throttle:5,1,backup-file-restore:')
        ->name('files.restore');

    Route::get('{backup}/file-restores', [BackupFileController::class, 'restores'])->name('files.restores');
});

/*
 * Following a download link. Not under the machine: the link already names
 * exactly one file of one backup, and carries a token that is spent on the
 * first request. The session and the account are still required.
 */
Route::get('backups/downloads/{token}', [BackupFileController::class, 'fetch'])
    ->where('token', '[0-9a-f]{64}')
    ->middleware('throttle:30,1,backup-file-fetch:')
    ->name('backups.downloads.show');
