<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Providers\Application\Actions\RefreshLicenceStates as RefreshLicenceStatesAction;

final class RefreshLicenceStates extends Command
{
    protected $signature = 'licences:refresh';

    protected $description = 'Let the calendar move licence states — active to expiring to expired — and reassess the providers under each one that changed';

    public function handle(RefreshLicenceStatesAction $refresh): int
    {
        $outcome = $refresh->execute();

        $this->info(sprintf(
            'Licence sweep: %d examined, %d changed state, %d providers reassessed.',
            $outcome['examined'],
            $outcome['changed'],
            $outcome['providers_reassessed'],
        ));

        return self::SUCCESS;
    }
}
