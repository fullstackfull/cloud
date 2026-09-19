<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Ipam\Application\Actions\WithdrawReverseDnsRecords;

/**
 * Takes back the PTRs of addresses that have changed hands.
 *
 * The reconciler `IpAllocator::withdrawReverseDns()` said somebody would write
 * and nobody did. Until this existed, every address the platform released kept
 * answering with the previous holder's hostname — which the quarantine between
 * customers exists to prevent, and which a PTR walked straight through.
 *
 * Every ten minutes. Faster than the quarantine an address sits out, so a
 * record is gone before the address can be handed to anybody, and slow enough
 * that a zone API is not asked the same question every minute.
 */
final class WithdrawReverseDns extends Command
{
    protected $signature = 'ipam:withdraw-reverse-dns
        {--limit=100 : The most records to withdraw in this run}';

    protected $description = 'Remove the PTR records of addresses that have been released';

    public function handle(WithdrawReverseDnsRecords $withdraw): int
    {
        $sweep = $withdraw->execute(limit: (int) $this->option('limit'));

        $this->line(json_encode([
            'command' => 'ipam:withdraw-reverse-dns',
            ...$sweep,
        ], JSON_THROW_ON_ERROR));

        // A zone API that refuses or cannot be reached fails every record in
        // the run, and the exit code is how an operator finds that out.
        return $sweep['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
