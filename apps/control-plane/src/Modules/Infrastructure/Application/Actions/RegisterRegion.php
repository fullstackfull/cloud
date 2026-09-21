<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * The first row of an estate, and the one that had no writer.
 *
 * RegisterDatacenter began with `Region::findOrFail`, and nothing in the
 * platform could put a region there — so on a fresh deployment the whole
 * inventory chain was unreachable from its first link, and the only ways in
 * were a SQL client, an edited seeder, or the reference topology, which is a
 * model of an estate and says so.
 *
 * A region is sold in, so it is created accepting new services. The two flags
 * stay separate for the reason the model says: winding a region down means
 * refusing new orders while the customers already there keep running, and a
 * single is_active would make those the same act.
 */
final readonly class RegisterRegion
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  array<string, string>  $name
     */
    public function execute(string $slug, array $name, string $country, ?string $city, User $operator): Region
    {
        return $this->record->execute(
            act: fn (): Region => Region::query()->create([
                'slug' => $slug,
                'name' => $name,
                'country' => strtoupper($country),
                'city' => $city,
                'is_active' => true,
                'accepts_new_services' => true,
            ]),
            describe: fn (Region $region): AuditedAct => new AuditedAct(
                action: AuditAction::RegionRegistered,
                subject: $region,
                context: [
                    'slug' => $region->slug,
                    'country' => $region->country,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
