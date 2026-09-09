<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\SharedHosting\Application\Actions\VerifyWordPressSites as Verify;

/**
 * Fetch every WordPress site and record what actually answered.
 *
 * It is the step that gives "ready" a meaning other than "somebody's API said
 * so". Read-only towards every site and every panel. See {@see Verify}.
 */
final class VerifyWordPressSites extends Command
{
    protected $signature = 'wordpress:verify {--limit=200}';

    protected $description = 'Fetch WordPress sites and record whether they actually answer';

    public function handle(Verify $verify): int
    {
        $outcome = $verify->execute((int) $this->option('limit'));

        $this->info(sprintf(
            'WordPress: %d checked, %d verified, %d still waiting on DNS, %d without a certificate.',
            $outcome['checked'],
            $outcome['verified'],
            $outcome['waiting'],
            $outcome['insecure'],
        ));

        return self::SUCCESS;
    }
}
