<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * A place machines can be. Registered, never inferred: the platform has no
 * way to know a building exists until somebody says so.
 */
final readonly class RegisterDatacenter
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(Region $region, string $slug, string $name, ?string $facility, User $operator): Datacenter
    {
        return $this->record->execute(
            act: fn (): Datacenter => Datacenter::query()->create([
                'region_id' => $region->getKey(),
                'slug' => $slug,
                'name' => $name,
                'facility' => $facility,
                'is_active' => true,
            ]),
            describe: fn (Datacenter $datacenter): AuditedAct => new AuditedAct(
                action: AuditAction::DatacenterRegistered,
                subject: $datacenter,
                context: ['slug' => $slug, 'name' => $name, 'region' => $region->slug, 'operator' => $operator->getKey()],
            ),
        );
    }
}
