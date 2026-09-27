<?php

declare(strict_types=1);

/*
 * `hosting:reconcile`, run in its own process, against a DirectAdmin node whose
 * account listing is the body in SWEEP_LISTING, held still just after it has
 * taken its first drift lock.
 *
 * For {@see \Tests\Feature\SharedHosting\TwoOverlappingHostingSweepsInTwoProcessesTest}.
 * RecordDrift serialises each drift on a transaction-scoped advisory lock
 * (`pg_advisory_xact_lock(hashtext(…))`), and inside a node's transaction that
 * lock is held until the node commits. Once the first one has been granted,
 * this process says LOCKED on standard error and waits on a shared advisory
 * lock the test holds exclusively (the number in SWEEP_PAUSE_LOCK). When the
 * test releases it, the command carries on. Its report is printed on standard
 * output as the command prints it.
 *
 * Nothing here changes what the command does: it is the production command,
 * called through Artisan, with the panel's HTTP answer faked (the same answer
 * the in-process hosting tests fake) and a query listener that only waits.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$base = dirname(__DIR__, 3);

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$slug = (string) getenv('SWEEP_NODE');
config(['hosting.credentials.'.$slug => ['username' => 'admin', 'login_key' => 'da-login-key-'.$slug]]);

$listing = (string) getenv('SWEEP_LISTING');
Http::fake(static fn () => Http::response($listing, 200));

$lock = (int) getenv('SWEEP_PAUSE_LOCK');
$paused = false;

DB::listen(static function (QueryExecuted $query) use (&$paused, $lock): void {
    if ($paused || ! str_contains($query->sql, 'pg_advisory_xact_lock(hashtext(')) {
        return;
    }

    $paused = true;
    fwrite(STDERR, "LOCKED\n");

    DB::select('select pg_advisory_lock_shared(?)', [$lock]);
    DB::select('select pg_advisory_unlock_shared(?)', [$lock]);
});

// The test reads pg_locks for this backend alone.
fwrite(STDERR, 'BACKEND '.DB::selectOne('select pg_backend_pid() as pid')->pid."\n");

$code = Artisan::call('hosting:reconcile', ['--no-interaction' => true]);

echo trim(Artisan::output()), "\n";

exit($code);
