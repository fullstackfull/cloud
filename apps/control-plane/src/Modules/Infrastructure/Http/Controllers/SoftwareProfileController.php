<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Lynomia\Modules\Infrastructure\Domain\DTOs\ComponentDefinition;
use Lynomia\Modules\Infrastructure\Domain\DTOs\ProfileDefinition;
use Lynomia\Modules\Infrastructure\Domain\Services\SoftwareCatalogue;

/**
 * The catalogue as this build carries it, for the screen that assigns a
 * profile. Read-only by construction: there is no endpoint that writes a
 * component or a profile, because they are source.
 */
final class SoftwareProfileController
{
    public function index(SoftwareCatalogue $catalogue): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (ProfileDefinition $profile): array => [
                'key' => $profile->key,
                'name' => $profile->name,
                'intended_role' => $profile->intendedRole,
                'playbook' => $profile->playbook,
                'description' => $profile->description,
                'components' => array_map(static fn (ComponentDefinition $component): array => [
                    'key' => $component->key,
                    'name' => $component->name,
                    'category' => $component->category,
                    'ansible_role' => $component->ansibleRole,
                    'risk' => $component->risk->value,
                    'requires_reboot' => $component->requiresReboot,
                    'verification' => $component->verification,
                    'accepts' => $component->accepts,
                    'description' => $component->description,
                ], $catalogue->componentsOf($profile)),
            ], $catalogue->profiles()),
        ]);
    }
}
