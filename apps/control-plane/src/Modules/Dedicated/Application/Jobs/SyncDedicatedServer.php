<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Dedicated\Application\Actions\SyncHardwareInventory;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;

/**
 * Refreshes one physical machine's hardware picture, off the request path.
 *
 * SyncHardwareInventory says it "runs on a schedule across the whole fleet",
 * and nothing ran it at all: the platform's record of a customer's server —
 * its firmware, its disks, whether a power supply is failing — was whatever
 * had been typed in when the chassis was racked.
 *
 * One job per machine rather than one for the fleet. A BMC is the slowest and
 * least reliable thing the platform talks to; a single job would let one
 * unreachable controller hold up the whole sweep, and the machine that stopped
 * answering is exactly the one worth hearing about.
 *
 * `tries = 1`, like every other infrastructure job here. A controller that
 * refused once will refuse again in thirty seconds, and the next scheduled
 * pass is a better retry than a queue worker in a hurry.
 */
final class SyncDedicatedServer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string QUEUE = 'infrastructure';

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private readonly string $serverId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(SyncHardwareInventory $sync): void
    {
        $server = DedicatedServer::query()->find($this->serverId);

        if ($server === null) {
            // Retired between the sweep and the worker. Failing the job would
            // page somebody about a machine deliberately removed.
            return;
        }

        try {
            $result = $sync->execute($server);
        } catch (BmcNotConfiguredException $e) {
            /*
             * A machine with no controller endpoint recorded. Worth knowing
             * about — it is a gap in the inventory — but not worth retrying,
             * because no number of attempts will invent an address.
             */
            Log::info('A dedicated server has no BMC endpoint to sync from.', [
                'server_id' => $this->serverId,
                'reason' => $e->getMessage(),
            ]);

            return;
        } catch (DedicatedProviderException $e) {
            /*
             * Logged rather than rethrown. The action has already recorded the
             * failure against the server row, which is where an operator looks;
             * failing the job as well would put one row in the failed-jobs
             * table per unreachable controller per pass and bury the genuine
             * failures nobody has seen before.
             *
             * The message is the adapter's, already redacted by it — a BMC
             * error can quote the request that carried the password.
             */
            Log::warning('Could not read a dedicated server\'s hardware.', [
                'server_id' => $this->serverId,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        Log::info('Refreshed a dedicated server\'s hardware picture.', [
            'server_id' => $this->serverId,
            'health' => $result->health->value,
            'components_missing' => $result->componentsMissing,
        ]);
    }
}
