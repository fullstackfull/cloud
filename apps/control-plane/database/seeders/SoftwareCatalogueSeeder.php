<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Lynomia\Modules\Infrastructure\Domain\Services\SoftwareCatalogue;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\SoftwareComponent;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\SoftwareProfile;

/**
 * Mirrors the catalogue in source into the tables the rest of the module
 * joins against. Idempotent, and the tables are never the authority: a
 * component edited in the database is overwritten by the next seed, which
 * is the point.
 */
final class SoftwareCatalogueSeeder extends Seeder
{
    public function run(SoftwareCatalogue $catalogue): void
    {
        $components = [];

        foreach ($catalogue->components() as $definition) {
            $components[$definition->key] = SoftwareComponent::query()->updateOrCreate(
                ['key' => $definition->key],
                [
                    'name' => $definition->name,
                    'category' => $definition->category,
                    'ansible_role' => $definition->ansibleRole,
                    'requires_licence' => $definition->requiresLicence,
                    'licence_product' => $definition->licenceProduct,
                    'verification' => $definition->verification,
                    'depends_on' => $definition->dependsOn,
                    'description' => $definition->description,
                ],
            );
        }

        foreach ($catalogue->profiles() as $definition) {
            $profile = SoftwareProfile::query()->updateOrCreate(
                ['key' => $definition->key],
                [
                    'name' => $definition->name,
                    'intended_role' => $definition->intendedRole,
                    'description' => $definition->description,
                    'is_active' => true,
                ],
            );

            $sync = [];

            foreach ($definition->components as $position => $key) {
                $sync[$components[$key]->getKey()] = ['is_required' => true, 'position' => $position, 'configuration' => '{}'];
            }

            $profile->components()->sync($sync);
        }

        // Profiles that left the catalogue are retired, not deleted: a
        // machine's desired state may still point at one.
        SoftwareProfile::query()
            ->whereNotIn('key', array_map(static fn ($p): string => $p->key, $catalogue->profiles()))
            ->update(['is_active' => false]);
    }
}
