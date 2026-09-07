<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Backups\Application\DTOs\ReconciliationSweep;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Throwable;

/**
 * Asks the provider what became of every backup the platform is still waiting
 * on.
 *
 * A backup is requested, the provider returns a task id, and from that moment
 * the platform knows nothing until it asks. ReconcileBackup does the asking for
 * one row; nothing called it, so a backup requested through the API stayed in
 * `running` for ever — never succeeding, never failing, never reaching
 * needs-review, and never telling anybody that the archive they think they have
 * does not exist.
 *
 * The scope orders by `last_polled_at nulls first`, so the least recently asked
 * about is asked first and a large backlog is worked through fairly across
 * runs rather than starving the oldest rows.
 *
 * A provider that is unreachable fails every row in the run. That is why each
 * failure is caught individually and counted: the sweep reports how many it
 * could not reach instead of stopping at the first, and the timeout rule inside
 * ReconcileBackup — record that we asked, ask again later, quarantine once
 * overdue — stays the only thing that decides what an unanswered poll means.
 */
final readonly class ReconcileRunningBackups
{
    public function __construct(
        private ReconcileBackup $reconcile,
    ) {}

    public function execute(int $limit = 200): ReconciliationSweep
    {
        $reconciled = 0;
        $failed = 0;

        $awaiting = Backup::query()
            ->awaitingProvider()
            ->limit($limit)
            ->get();

        foreach ($awaiting as $backup) {
            try {
                $before = $backup->state;
                $after = $this->reconcile->execute($backup);

                if ($after->state !== $before) {
                    $reconciled++;
                }
            } catch (Throwable $e) {
                $failed++;

                Log::error('A backup could not be reconciled with its provider.', [
                    'backup_id' => $backup->getKey(),
                    'cluster_id' => $backup->cluster_id,
                    'exception' => $e::class,
                    // The message is not logged. Provider exceptions are
                    // redacted inside ReconcileBackup, and a message that
                    // escaped here would be one nothing had redacted.
                ]);
            }
        }

        return new ReconciliationSweep(
            considered: $awaiting->count(),
            settled: $reconciled,
            failed: $failed,
        );
    }
}
