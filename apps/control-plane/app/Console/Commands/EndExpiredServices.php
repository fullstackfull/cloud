<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Provisioning\Application\Actions\EndExpiredServices as EndExpiredServicesAction;

/**
 * Finish what a cancellation started, once its retention window has closed.
 *
 * Only services a customer cancelled. A service suspended for non-payment
 * looks identical in the database and is a completely different decision; see
 * the action for why that one keeps a person's name on it.
 */
final class EndExpiredServices extends Command
{
    protected $signature = 'services:end-expired';

    protected $description = 'End cancelled services whose retention window has closed, and warn the ones approaching it';

    public function handle(EndExpiredServicesAction $sweep): int
    {
        $outcome = $sweep->execute();

        $this->info(sprintf(
            'Retention sweep: %d services ended, %d customers warned, %d could not be ended.',
            $outcome['ended'],
            $outcome['warned'],
            $outcome['failed'],
        ));

        return self::SUCCESS;
    }
}
