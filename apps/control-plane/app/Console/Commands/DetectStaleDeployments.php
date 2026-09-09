<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Infrastructure\Application\Actions\DetectStaleDeployments as Detect;

final class DetectStaleDeployments extends Command
{
    protected $signature = 'deployments:detect-stale';

    protected $description = 'Mark deployments that outlived their worker as indeterminate, for a person to look at';

    public function handle(Detect $detect): int
    {
        $outcome = $detect->execute();

        $this->info(sprintf('%d stale deployment(s) marked indeterminate.', $outcome['marked']));

        return self::SUCCESS;
    }
}
