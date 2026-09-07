<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;

/**
 * Bills every subscription whose next period has come due.
 *
 * Scheduled hourly rather than daily. A subscription's next_invoice_at can fall
 * at any hour — it is derived from when the customer bought, not from midnight
 * — and a daily sweep would invoice up to twenty-four hours late, which for a
 * service that suspends on non-payment is a day of grace the customer did not
 * get. Hourly also means a run interrupted by a deploy costs an hour rather
 * than a day.
 */
final class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew
        {--limit=500 : The most subscriptions to attempt in this run}';

    protected $description = 'Renew subscriptions that are due and issue their invoices';

    public function handle(RenewDueSubscriptions $renew): int
    {
        $sweep = $renew->execute(limit: (int) $this->option('limit'));

        $this->line(json_encode([
            'command' => 'subscriptions:renew',
            'considered' => $sweep->considered,
            'renewed' => $sweep->renewed,
            'skipped' => $sweep->skipped,
            'failed' => $sweep->failed,
        ], JSON_THROW_ON_ERROR));

        // A failure here is money that was not billed. The exit code is what a
        // cron wrapper, a systemd timer or an alerting rule reads, and it must
        // say so rather than reporting a clean run that shipped a shortfall.
        return $sweep->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
