<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterDatacenter;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterRack;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterDatacenterRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterRackRequest;
use Lynomia\Modules\Infrastructure\Http\Resources\DatacenterResource;
use Lynomia\Modules\Infrastructure\Http\Resources\RackResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where machines are: regions (read), datacenters and racks.
 */
final class SiteController
{
    public function regions(): JsonResponse
    {
        return response()->json([
            'data' => Region::query()->orderBy('slug')->get()->map(static fn (Region $region): array => [
                'id' => $region->id,
                'slug' => $region->slug,
                'name' => $region->nameFor('en'),
            ])->all(),
        ]);
    }

    public function datacenters(Request $request): JsonResponse
    {
        $datacenters = Datacenter::query()
            ->with('region')
            ->orderBy('slug')
            ->get();

        $racks = Rack::query()->selectRaw('datacenter_id, count(*) as total')->groupBy('datacenter_id')->pluck('total', 'datacenter_id');

        $machines = ManagedServer::query()
            ->selectRaw('datacenter_id, count(*) as total')
            ->whereNotNull('datacenter_id')
            ->groupBy('datacenter_id')
            ->pluck('total', 'datacenter_id');

        return response()->json([
            'data' => $datacenters->map(function (Datacenter $datacenter) use ($request, $machines, $racks): array {
                $datacenter->setAttribute('machines_count', (int) ($machines[$datacenter->getKey()] ?? 0));
                $datacenter->setAttribute('racks_count', (int) ($racks[$datacenter->getKey()] ?? 0));

                return (new DatacenterResource($datacenter))->toArray($request);
            })->all(),
        ]);
    }

    public function storeDatacenter(RegisterDatacenterRequest $request, RegisterDatacenter $register): JsonResponse
    {
        $region = Region::query()->findOrFail($request->string('region_id')->value());

        $datacenter = $register->execute(
            $region,
            $request->string('slug')->value(),
            $request->string('name')->value(),
            $request->input('facility'),
            $request->user(),
        );

        return response()->json(['data' => (new DatacenterResource($datacenter->load('region')))->toArray($request)], Response::HTTP_CREATED);
    }

    public function racks(Request $request): JsonResponse
    {
        $racks = Rack::query()
            ->with('datacenter')
            ->when($request->filled('datacenter'), fn ($q) => $q->where('datacenter_id', $request->string('datacenter')->value()))
            ->orderBy('name')
            ->get();

        $machines = ManagedServer::query()
            ->selectRaw('rack_id, count(*) as total')
            ->whereNotNull('rack_id')
            ->groupBy('rack_id')
            ->pluck('total', 'rack_id');

        return response()->json([
            'data' => $racks->map(function (Rack $rack) use ($request, $machines): array {
                $rack->setAttribute('machines_count', (int) ($machines[$rack->getKey()] ?? 0));

                return (new RackResource($rack))->toArray($request);
            })->all(),
        ]);
    }

    public function storeRack(RegisterRackRequest $request, RegisterRack $register): JsonResponse
    {
        $datacenter = Datacenter::query()->findOrFail($request->string('datacenter_id')->value());

        try {
            $rack = $register->execute(
                $datacenter,
                $request->string('name')->value(),
                $request->input('row'),
                $request->integer('units'),
                $request->input('power_notes'),
                $request->input('network_notes'),
                $request->user(),
            );
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        }

        return response()->json(['data' => (new RackResource($rack->load('datacenter')))->toArray($request)], Response::HTTP_CREATED);
    }
}
