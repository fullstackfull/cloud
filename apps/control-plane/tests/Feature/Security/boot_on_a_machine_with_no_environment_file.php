<?php

declare(strict_types=1);

/*
 * Boots the shipped application, in its own process, on what looks like a
 * machine with no environment file: the environment path is pointed at an
 * empty directory, and the caller has removed APP_ENV from this process.
 *
 * Run by ACachedConfigurationIsAConfiguredProductionTest. The shipped
 * .env of the checkout is never touched; only where this one process looks
 * for it moves.
 *
 * Arguments: the empty directory to use as the environment path; the mode.
 *   write  — print nothing but write the loaded configuration to the file
 *            APP_CONFIG_CACHE names, exactly as `config:cache` serialises it
 *            (config:cache itself cannot be used: it re-requires
 *            bootstrap/app.php for a fresh configuration and would read the
 *            checkout's .env).
 *   boot   — boot and print one line of JSON: what the environment settled
 *            as and whether the configuration was read from the cache, or,
 *            when the boot was refused, the refusal (and exit 1).
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../../vendor/autoload.php';

[$environmentPath, $mode] = [$argv[1], $argv[2]];

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->useEnvironmentPath($environmentPath);

try {
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $e) {
    // Caught here rather than left to the framework's handler, which renders
    // it to the console and does not reliably set the exit status.
    echo json_encode(['refused' => $e->getMessage()], JSON_THROW_ON_ERROR), PHP_EOL;

    exit(1);
}

if ($mode === 'write') {
    file_put_contents(
        $app->getCachedConfigPath(),
        '<?php return '.var_export($app->make('config')->all(), true).';'.PHP_EOL,
    );

    exit(0);
}

echo json_encode([
    'env' => $app['env'],
    'cached' => $app->configurationIsCached(),
], JSON_THROW_ON_ERROR), PHP_EOL;
