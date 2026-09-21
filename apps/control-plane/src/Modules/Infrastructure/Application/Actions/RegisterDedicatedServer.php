<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * A physical machine, added to stock.
 *
 * The one piece of inventory the platform sells that has to be carried into a
 * building by a person, so there is nothing to discover it from: until
 * somebody says a chassis exists, it does not, and the dedicated reservation
 * path has nothing to reserve. That was the gap — `DedicatedServer` had a
 * reader, a reservation path, a termination path and a return-to-stock path,
 * and no way to put one there in the first place.
 *
 * It arrives `available` and owned by nobody. Power state is `unknown`, not
 * `off`: nothing has asked the BMC, and a platform that says a machine is off
 * because it was just written down is a platform that will one day say a
 * customer's machine is off when it is running.
 */
final readonly class RegisterDedicatedServer
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(
        Datacenter $datacenter,
        ?Rack $rack,
        string $manufacturer,
        string $model,
        string $serial,
        ?string $assetTag,
        ?int $rackUnit,
        int $heightUnits,
        ?string $hardwareProfile,
        ?string $notes,
        User $operator,
    ): DedicatedServer {
        return $this->record->execute(
            act: fn (): DedicatedServer => DedicatedServer::query()->create([
                'datacenter_id' => $datacenter->getKey(),
                'rack_id' => $rack?->getKey(),
                'manufacturer' => $manufacturer,
                'model' => $model,
                'serial' => $serial,
                'asset_tag' => $assetTag,
                'rack_unit' => $rackUnit,
                'height_units' => $heightUnits,
                'hardware_profile' => $hardwareProfile,
                'status' => DedicatedServerStatus::Available,
                // Nothing has been contacted, so nothing is known.
                'power_state' => PowerState::Unknown,
                'notes' => $notes,
            ]),
            describe: fn (DedicatedServer $server): AuditedAct => new AuditedAct(
                action: AuditAction::DedicatedServerRegistered,
                subject: $server,
                context: [
                    'serial' => $server->serial,
                    'model' => $server->model,
                    'datacenter' => $datacenter->slug,
                    'rack' => $rack?->name,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
