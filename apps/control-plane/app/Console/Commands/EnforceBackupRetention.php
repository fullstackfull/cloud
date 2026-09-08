<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Backups\Application\Actions\EnforceBackupRetention as Sweep;

/**
 * The sweep that keeps datastores from growing for ever.
 *
 * Two passes in one run and deliberately so: it marks what has expired, and it
 * acts on what was marked long enough ago to be past the grace period. A
 * backup therefore never goes on the same run that notices it — the hour in
 * between is what lets a customer or an operator stop a deletion they did not
 * mean, and it costs a little datastore space to have.
 */
final class EnforceBackupRetention extends Command
{
    protected $signature = 'backups:enforce-retention';

    protected $description = 'Mark expired backups for deletion, and remove the ones already marked.';

    public function handle(Sweep $sweep): int
    {
        $result = $sweep->execute();

        $this->info(sprintf(
            'Retention: %d marked for deletion, %d confirmed deleted at the provider, %d left alone.',
            $result['marked'],
            $result['deleted'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
