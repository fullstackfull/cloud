<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Actions\RecordOsInstallProfile;
use Lynomia\Modules\Infrastructure\Application\Actions\WithdrawOsInstallProfile;
use Lynomia\Modules\Infrastructure\Http\Requests\RecordOsInstallProfileRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * The recipes a Dedicated build installs from, as operator data.
 *
 * Onboarding physical hardware has to be a person in the Control Center, not
 * a developer writing a factory, so the answer files are recorded here for the
 * same reason templates, racks and datacenters are.
 */
final class OsInstallProfileController
{
    public function index(): JsonResponse
    {
        $profiles = OsInstallProfile::query()->orderBy('slug')->get();

        return response()->json([
            // Withdrawn profiles are listed: "why is this OS not installed any
            // more" is answered by the row that says it was withdrawn.
            'data' => $profiles->map(static fn (OsInstallProfile $profile): array => self::present($profile))->all(),
        ]);
    }

    public function store(RecordOsInstallProfileRequest $request, RecordOsInstallProfile $record): JsonResponse
    {
        /** @var array<string, string> $name */
        $name = array_map(static fn (mixed $value): string => trim((string) $value), $request->array('name'));

        /** @var array<string, scalar|null> $defaults */
        $defaults = $request->array('defaults');

        /** @var User $operator */
        $operator = $request->user();

        $profile = $record->execute(
            slug: $request->string('slug')->value(),
            name: $name,
            osFamily: $request->string('os_family')->value(),
            osVersion: $request->string('os_version')->value(),
            installer: InstallerKind::from($request->string('installer')->value()),
            // As the framework hands it over: request strings are trimmed
            // before anything reads them, so leading and trailing whitespace
            // is not kept. Whitespace inside the file is.
            template: (string) $request->input('template'),
            defaults: $defaults,
            operator: $operator,
        );

        return response()->json(['data' => self::present($profile)], Response::HTTP_CREATED);
    }

    /**
     * The id is resolved here rather than by route model binding, so the
     * permission middleware answers before anything says whether the id
     * exists (AdminSurfaceTest).
     */
    public function destroy(Request $request, string $profile, WithdrawOsInstallProfile $withdraw): JsonResponse
    {
        /** @var OsInstallProfile $found */
        $found = OsInstallProfile::query()->findOrFail($profile);

        /** @var User $operator */
        $operator = $request->user();

        return response()->json(['data' => self::present($withdraw->execute($found, $operator))]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(OsInstallProfile $profile): array
    {
        return [
            'id' => (string) $profile->getKey(),
            'slug' => $profile->slug,
            'name' => $profile->name,
            'os_family' => $profile->os_family,
            'os_version' => $profile->os_version,
            'installer' => $profile->installer->value,
            'template_sha256' => hash('sha256', $profile->template),
            // The keys only: a default may legitimately be a password hash, and
            // this listing is read by everyone holding infrastructure.view.
            'default_keys' => array_keys($profile->defaults ?? []),
            'is_active' => $profile->is_active,
        ];
    }
}
