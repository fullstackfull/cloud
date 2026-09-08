<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Dedicated\Application\Jobs\SyncDedicatedServer;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;

/**
 * Asks every physical machine's controller what it is made of.
 *
 * Fan-out only, for the same reason the cluster sweep is: a BMC that has
 * stopped answering must delay nothing but its own machine, and no single
 * process should hold a conversation with every chassis the platform owns.
 *
 * Retired machines are excluded — there is nothing at the other end, and a
 * daily failure against a box that left the rack a year ago is noise that
 * teaches operators to ignore this sweep. Failed machines are NOT excluded:
 * a machine marked failed is one somebody needs the current hardware picture
 * of, and that is exactly when its old picture is most misleading.
 */
final class SyncDedicatedInventory extends Command
{
    protected $signature = 'dedicated:sync-inventory
        {--server= : Sync only this server}';

    protected $description = 'Refresh the hardware inventory of every dedicated server from its BMC';

    public function handle(): int
    {
        $servers = DedicatedServer::query()
            ->when(
                is_string($this->option('server')) && $this->option('server') !== '',
                fn ($query) => $query->whereKey($this->option('server')),
                fn ($query) => $query->where('status', '!=', DedicatedServerStatus::Retired->value),
            )
            ->pluck('id');

        foreach ($servers as $id) {
            SyncDedicatedServer::dispatch((string) $id);
        }

        $this->line((string) json_encode([
            'command' => 'dedicated:sync-inventory',
            'queued' => $servers->count(),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
