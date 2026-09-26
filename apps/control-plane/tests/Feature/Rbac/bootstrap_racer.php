<?php

declare(strict_types=1);

/*
 * One `operator:bootstrap`, in its own process, racing its rivals.
 *
 * Run by TwoBootstrapsAtOnceEstablishOneOperatorTest, for the reason
 * Orders/place_order_racer.php exists: two statements on one connection are
 * serialised by definition, so two console runs deciding "is there a super
 * admin yet?" at once cannot be shown from one process.
 *
 * Argument: the address to bootstrap. `--show-link` so that no mail is sent.
 * Writes one line of JSON: the command's exit status, or what it threw.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $exit = Artisan::call('operator:bootstrap', ['email' => $argv[1], '--show-link' => true]);
    $outcome = ['exit' => $exit, 'error' => null];
} catch (Throwable $e) {
    $outcome = ['exit' => null, 'error' => $e::class.': '.$e->getMessage()];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
