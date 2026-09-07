<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Provisioning\Application\Actions\DetectStaleJobs;

/**
 * Finds provisioning jobs nobody is waiting on any more.
 *
 * A job whose worker died, whose provider stopped answering, or whose process
 * was killed mid-flight leaves a row in `running` and no message on any queue.
 * DetectStaleJobs applies the platform's timeout rule to those: not "it
 * failed", which would licence a retry that might build a second machine, but
 * "the platform stopped waiting", which is a person's decision to make.
 *
 * The action existed and nothing called it, so a stuck job stayed stuck for
 * ever and the machine it may have created stayed invisible.
 */
final class DetectStaleProvisioning extends Command
{
    protected $signature = 'provisioning:detect-stale {--limit=100 : The most jobs to examine in one run}';

    protected $description = 'Quarantine provisioning jobs the platform has stopped waiting on';

    public function handle(DetectStaleJobs $detect): int
    {
        $quarantined = $detect->execute((int) $this->option('limit'));

        $this->line(json_encode([
            'command' => 'provisioning:detect-stale',
            'quarantined' => $quarantined,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
