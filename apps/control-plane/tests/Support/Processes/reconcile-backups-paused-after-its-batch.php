<?php

declare(strict_types=1);

/*
 * `backups:reconcile`, run in its own process, held still right after it has
 * loaded the rows it will work through.
 *
 * For {@see \Tests\Feature\Backups\TwoOverlappingSweepsInTwoProcessesTest}. The
 * first query that reads from `backups` with a WHERE clause is the sweep's
 * batch; once it has run, this process says PAUSED on standard error and waits
 * on a shared advisory lock the test holds exclusively (the number in
 * SWEEP_PAUSE_LOCK). When the test releases it, the command carries on with
 * the copies it loaded, which is what makes it the stale sweep. Its report is
 * printed on standard output as the command prints it.
 *
 * Nothing here changes what the command does: it is the production command,
 * called through Artisan, with a query listener that only waits.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$base = dirname(__DIR__, 3);

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$lock = (int) getenv('SWEEP_PAUSE_LOCK');
$paused = false;

DB::listen(static function (QueryExecuted $query) use (&$paused, $lock): void {
    if ($paused || preg_match('/from "backups" where/i', $query->sql) !== 1) {
        return;
    }

    $paused = true;
    fwrite(STDERR, "PAUSED\n");

    DB::select('select pg_advisory_lock_shared(?)', [$lock]);
    DB::select('select pg_advisory_unlock_shared(?)', [$lock]);
});

$code = Artisan::call('backups:reconcile', ['--no-interaction' => true]);

echo trim(Artisan::output()), "\n";

exit($code);
