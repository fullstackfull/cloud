<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Identity\Application\Actions\ApplyDueCountryCurrencyChanges;

/**
 * Applies the account country/currency changes an operator approved for a
 * later moment. Every five minutes: what it is closing is the gap between
 * "approved for the 1st" and the first invoice of the 1st.
 */
final class ApplyCountryCurrencyChanges extends Command
{
    protected $signature = 'customers:apply-country-currency-changes
        {--limit=100 : The most changes to apply in this run}';

    protected $description = 'Apply scheduled account country/currency changes whose moment has come';

    public function handle(ApplyDueCountryCurrencyChanges $apply): int
    {
        $sweep = $apply->execute(limit: (int) $this->option('limit'));

        $this->line(json_encode(['command' => 'customers:apply-country-currency-changes', ...$sweep], JSON_THROW_ON_ERROR));

        return $sweep['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
