<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Actions\RecordVmTemplate;
use Lynomia\Modules\Infrastructure\Application\Actions\WithdrawVmTemplate;
use Lynomia\Modules\Infrastructure\Http\Requests\RecordVmTemplateRequest;
use Lynomia\Modules\Infrastructure\Http\Resources\VmTemplateResource;
use Symfony\Component\HttpFoundation\Response;

/**
 * The catalogue of images this platform may install, as operator data.
 *
 * Onboarding a real cluster has to be a person filling in the Control Center,
 * not a developer editing a seeder — so the images a cluster offers are
 * recorded here for the same reason racks and datacenters are. Everything
 * downstream already reads the table: placement resolves a plan's declared
 * slug against it, the reinstall list is drawn from it, and an image with no
 * provider reference is refused by both.
 */
final class VmTemplateController
{
    public function index(Request $request): JsonResponse
    {
        $templates = VmTemplate::query()
            ->with('cluster')
            ->when(
                $request->filled('cluster'),
                fn ($query) => $query->where('cluster_id', $request->string('cluster')->value()),
            )
            /*
             * Withdrawn images are included by default, because an operator
             * looking for "why is Debian 12 not being offered" needs to see
             * the row that says it was withdrawn. `?active=1` narrows it.
             */
            ->when(
                $request->boolean('active'),
                fn ($query) => $query->where('is_active', true),
            )
            ->orderBy('cluster_id')
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $templates->map(
                fn (VmTemplate $template): array => (new VmTemplateResource($template))->toArray($request),
            )->all(),
            'meta' => [
                'total' => $templates->count(),
                // The number that decides whether a VPS can be built at all.
                'installable' => $templates
                    ->filter(static fn (VmTemplate $template): bool => $template->is_active
                        && $template->provider_reference !== null
                        && $template->provider_reference !== '')
                    ->count(),
            ],
        ]);
    }

    public function store(RecordVmTemplateRequest $request, RecordVmTemplate $record): JsonResponse
    {
        /** @var ComputeCluster $cluster */
        $cluster = ComputeCluster::query()->findOrFail($request->string('cluster_id')->value());

        /** @var array<string, string> $name */
        $name = array_map(
            static fn (mixed $value): string => trim((string) $value),
            $request->array('name'),
        );

        $reference = $request->input('provider_reference');
        $reference = is_string($reference) ? trim($reference) : null;

        /** @var User $operator */
        $operator = $request->user();

        $template = $record->execute(
            cluster: $cluster,
            slug: $request->string('slug')->value(),
            name: $name,
            osFamily: OsFamily::from($request->string('os_family')->value()),
            osVersion: $request->string('os_version')->value(),
            architecture: CpuArchitecture::from($request->string('architecture')->value()),
            providerReference: $reference === '' ? null : $reference,
            cloudInit: $request->boolean('cloud_init'),
            guestAgent: $request->boolean('guest_agent'),
            requiresLicence: $request->boolean('requires_licence'),
            licenceNote: $request->input('licence_note'),
            operator: $operator,
        );

        return response()->json(
            ['data' => (new VmTemplateResource($template->load('cluster')))->toArray($request)],
            /*
             * 200 rather than 201 when the row already existed, because a
             * second call for the same (cluster, slug) is a correction and an
             * operator fixing a provider reference should not be told they
             * created something.
             */
            $template->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function destroy(Request $request, VmTemplate $template, WithdrawVmTemplate $withdraw): JsonResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        return response()->json([
            'data' => (new VmTemplateResource($withdraw->execute($template, $operator)->load('cluster')))->toArray($request),
        ]);
    }
}
