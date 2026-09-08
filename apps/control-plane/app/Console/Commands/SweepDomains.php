<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Domains\Application\Actions\SweepDomainLifecycle;

/**
 * Keep the promise `auto_renew` makes, and mirror the registry's own clock.
 *
 * It orders renewals and advances lapsed states. It never talks to a
 * registrar: the registry is asked when the money arrives, by the same
 * listener that handles a renewal a customer clicked. See
 * {@see SweepDomainLifecycle}.
 */
final class SweepDomains extends Command
{
    protected $signature = 'domains:sweep';

    protected $description = 'Order automatic renewals and advance expired, grace and redemption states';

    public function handle(SweepDomainLifecycle $sweep): int
    {
        $outcome = $sweep->execute();

        $this->info(sprintf(
            'Domains: %d renewals ordered, %d expired, %d in redemption, %d deleted, %d left alone.',
            $outcome['renewals_ordered'],
            $outcome['expired'],
            $outcome['redemption'],
            $outcome['deleted'],
            $outcome['skipped'],
        ));

        return self::SUCCESS;
    }
}
