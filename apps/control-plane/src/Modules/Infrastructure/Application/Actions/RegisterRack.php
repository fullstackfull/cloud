<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Database\QueryException;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;

/**
 * A rack in a datacenter, with the two notes the dedicated module never had
 * a place for: which circuits feed it and which switch ports it patches
 * into. Free text, and never handed to anything that executes.
 */
final readonly class RegisterRack
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(Datacenter $datacenter, string $name, ?string $row, int $units, ?string $powerNotes, ?string $networkNotes, User $operator): Rack
    {
        try {
            return $this->record->execute(
                act: fn (): Rack => Rack::query()->create([
                    'datacenter_id' => $datacenter->getKey(),
                    'name' => $name,
                    'row' => $row,
                    'units' => $units,
                    'power_notes' => $powerNotes,
                    'network_notes' => $networkNotes,
                ]),
                describe: fn (Rack $rack): AuditedAct => new AuditedAct(
                    action: AuditAction::RackRegistered,
                    subject: $rack,
                    context: ['datacenter' => $datacenter->slug, 'rack' => $name, 'units' => $units, 'operator' => $operator->getKey()],
                ),
            );
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'racks_datacenter_id_name_unique')) {
                throw new DeploymentRefused(sprintf('%s already has a rack named %s.', $datacenter->slug, $name), 'rack_exists');
            }

            throw $e;
        }
    }
}
