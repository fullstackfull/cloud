<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Infrastructure\Application\Actions\DetectInfrastructureDrift as Detect;

final class DetectInfrastructureDrift extends Command
{
    protected $signature = 'infrastructure:detect-drift';

    protected $description = 'Record every managed machine that no longer matches its profile into the drift queue';

    public function handle(Detect $detect): int
    {
        $outcome = $detect->execute();

        $this->info(sprintf('%d managed machine(s) examined, %d drifted.', $outcome['examined'], $outcome['drifted']));

        return self::SUCCESS;
    }
}
