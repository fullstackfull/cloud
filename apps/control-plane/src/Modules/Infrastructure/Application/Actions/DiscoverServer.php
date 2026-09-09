<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\FactSource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ServerFact;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use Lynomia\Modules\Providers\Domain\DTOs\ServerDiscovery;

/**
 * Look at a machine and write down what it said.
 *
 * ---------------------------------------------------------------------------
 * Read-only, and what that costs
 * ---------------------------------------------------------------------------
 *
 * Discovery is the act DISCOVERY_ONLY exists to permit: a look, through the
 * machine's own BMC, that changes nothing on it. The safety gate is asked for
 * Read and nothing more, and the tester's contract is that discover() asks and
 * records. What this action changes is the platform's picture of the machine —
 * the facts table — and that is the only thing it may change.
 *
 * ---------------------------------------------------------------------------
 * Facts are versioned, not overwritten
 * ---------------------------------------------------------------------------
 *
 * A fact that changes between two looks is information: a disk that was there
 * last week and is not there now is the finding somebody needs, and an UPDATE
 * would have erased it. So a new value supersedes the old row and the old row
 * stays, with the moment it stopped being current. The partial unique index
 * server_facts_one_current_value keeps exactly one current row per key, in
 * the database, whatever this code does.
 *
 * A key the machine no longer reports is superseded too. Discovery describes
 * what is there now; a fact from a previous look that this look did not
 * confirm is no longer a current fact.
 */
final readonly class DiscoverServer
{
    public function __construct(
        private TestConnection $connection,
        private RecordActAtomically $record,
    ) {}

    public function execute(ManagedServer $server, ?User $operator = null): ServerDiscovery
    {
        // Gated inside: Read, the same as a test, and refused the same way.
        $discovery = $this->connection->discoverServer($server, $operator);

        if ($discovery->facts === []) {
            // Nothing answered usefully, so nothing is written. The test row
            // already records what happened and the screen reads that.
            return $discovery;
        }

        $this->record->execute(
            act: function () use ($server, $discovery): int {
                $now = CarbonImmutable::now();
                $changed = 0;

                /** @var array<string, ServerFact> $current */
                $current = $server->facts()->current()->get()->keyBy('key')->all();

                foreach ($discovery->facts as $key => $value) {
                    $existing = $current[$key] ?? null;

                    if ($existing !== null && $existing->value === $value) {
                        // Confirmed, unchanged. The row stays current and its
                        // observation time is not touched: observed_at is
                        // when the value was first seen, and the test row is
                        // when it was last confirmed.
                        unset($current[$key]);

                        continue;
                    }

                    $existing?->forceFill(['superseded_at' => $now])->save();

                    ServerFact::create([
                        'managed_server_id' => $server->getKey(),
                        'key' => $key,
                        'value' => $value,
                        'source' => FactSource::Discovered,
                        'observed_at' => $now,
                    ]);

                    $changed++;
                    unset($current[$key]);
                }

                // Whatever this look did not confirm is no longer current.
                foreach ($current as $stale) {
                    if ($stale->source === FactSource::Discovered) {
                        $stale->forceFill(['superseded_at' => $now])->save();
                        $changed++;
                    }
                }

                $server->forceFill(['last_discovery_at' => $now])->save();

                return $changed;
            },
            describe: fn (int $changed): AuditedAct => new AuditedAct(
                action: AuditAction::ServerDiscovered,
                subject: $server,
                context: [
                    'server' => $server->name,
                    'facts' => count($discovery->facts),
                    'changed' => $changed,
                    'operator' => $operator?->getKey(),
                ],
            ),
        );

        return $discovery;
    }
}
