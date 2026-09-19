<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Backups\Application\Actions\VerifyStoredArchives;

/**
 * Asks the datastore to read back the archives nobody has checked.
 *
 * This is the trigger that did not exist. `startVerification` was on the
 * provider contract and on both drivers, `Verifying` was a state with a
 * settled verdict waiting for it, and no action, job or command ever put a row
 * into it — so `verified` was null for every backup the platform had ever
 * taken, and a customer reading "succeeded" was reading "the job reported
 * success", not "the data is readable".
 *
 * On the same schedule as the reconciler and for the same reason: the two are
 * halves of one loop. This one asks, that one finds out, and an archive is
 * only verified once the second has heard back.
 */
final class VerifyBackups extends Command
{
    protected $signature = 'backups:verify
        {--limit=50 : The most archives to send for verification in this run}';

    protected $description = 'Send stored archives nobody has checked for verification';

    public function handle(VerifyStoredArchives $verify): int
    {
        $sweep = $verify->execute(limit: (int) $this->option('limit'));

        $this->line(json_encode([
            'command' => 'backups:verify',
            'considered' => $sweep->considered,
            'started' => $sweep->settled,
            'failed' => $sweep->failed,
        ], JSON_THROW_ON_ERROR));

        /*
         * A datastore that cannot be reached fails every archive in the run,
         * and the exit code is how an operator finds that out — the same
         * contract the reconciler's command keeps.
         */
        return $sweep->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
