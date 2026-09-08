<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Subscriptions\Application\Actions\SweepSubscriptionLifecycle;

/**
 * Applies the two clocks that are not the renewal clock: scheduled
 * cancellations, and the dunning sequence.
 *
 * Hourly, so that a cancellation promised for a particular time takes effect
 * near it. The dunning steps themselves are measured in days and are unhurt by
 * being examined more often; AdvanceDunning takes at most one step per
 * subscription per run whatever the schedule, so suspension and termination can
 * never land together.
 */
final class SweepSubscriptions extends Command
{
    protected $signature = 'subscriptions:sweep
        {--limit=500 : The most subscriptions to examine per stage in this run}';

    protected $description = 'Apply due cancellations and advance the dunning sequence';

    public function handle(SweepSubscriptionLifecycle $sweep): int
    {
        $result = $sweep->execute(limit: (int) $this->option('limit'));

        $this->line(json_encode([
            'command' => 'subscriptions:sweep',
            'cancellations_considered' => $result->cancellationsConsidered,
            'cancelled' => $result->cancelled,
            'dunning_considered' => $result->dunningConsidered,
            'advanced' => $result->advanced,
            'failed' => $result->failed,
        ], JSON_THROW_ON_ERROR));

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
