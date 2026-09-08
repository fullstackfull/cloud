<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Compute\Application\Actions\PollProviderTasks as PollProviderTasksAction;

/**
 * Ask the hypervisor what became of the tasks the platform handed off.
 *
 * A job marked succeeded means the request was accepted. This is what turns
 * that into evidence that it was carried out — or into a queue entry for a
 * person when it was not.
 */
final class PollProviderTasks extends Command
{
    protected $signature = 'compute:poll-tasks';

    protected $description = 'Confirm with the hypervisor that accepted tasks actually finished';

    public function handle(PollProviderTasksAction $poll): int
    {
        $outcome = $poll->execute();

        $this->info(sprintf(
            'Provider tasks: %d asked about, %d confirmed, %d sent for review.',
            $outcome['polled'],
            $outcome['confirmed'],
            $outcome['review'],
        ));

        return self::SUCCESS;
    }
}
