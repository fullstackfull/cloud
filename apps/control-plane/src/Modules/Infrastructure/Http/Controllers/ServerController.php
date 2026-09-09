<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Infrastructure\Application\Actions\ClassifyServer;
use Lynomia\Modules\Infrastructure\Application\Actions\ClearForReimage;
use Lynomia\Modules\Infrastructure\Application\Actions\DiscoverServer;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterGpuDevice;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterServer;
use Lynomia\Modules\Infrastructure\Application\Actions\RevokeReimageClearance;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuPassthroughMode;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\ClassificationRefused;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\SafetyRefusal;
use Lynomia\Modules\Infrastructure\Http\Requests\ClassifyServerRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\ClearForReimageRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterGpuDeviceRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterServerRequest;
use Lynomia\Modules\Infrastructure\Http\Resources\GpuDeviceResource;
use Lynomia\Modules\Infrastructure\Http\Resources\ServerFactResource;
use Lynomia\Modules\Infrastructure\Http\Resources\ServerResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Actions\AttachCredential;
use Lynomia\Modules\Providers\Domain\Exceptions\CredentialRefused;
use Lynomia\Modules\Providers\Domain\Exceptions\NoBmcProvider;
use Lynomia\Modules\Providers\Domain\Exceptions\NoSuchTester;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Symfony\Component\HttpFoundation\Response;

/**
 * The machines, and the small number of things an operator may do to one.
 *
 * ---------------------------------------------------------------------------
 * What this controller is careful about
 * ---------------------------------------------------------------------------
 *
 * Every write here is a call to an action, never a model save. The actions hold
 * the locking, the audit entry and the safety rules, and a controller that
 * wrote a column directly would be a second path to the same change with none
 * of them — which is how a classification ends up altered with nothing
 * recording who did it.
 *
 * The refusals come back as 409 rather than 422. A refusal is not "your input
 * was malformed"; it is "the machine is not in a state where that is allowed",
 * and the distinction matters to an operator deciding whether to fix their
 * request or go and change something.
 */
final class ServerController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $servers = ManagedServer::query()
            ->with('credential')
            ->when($request->filled('environment'), fn ($query) => $query->where('environment', $request->string('environment')->value()))
            ->when($request->filled('state'), fn ($query) => $query->where('state', $request->string('state')->value()))
            ->when($request->filled('safety_class'), fn ($query) => $query->where('safety_class', $request->string('safety_class')->value()))
            /*
             * The machines somebody has to decide about, first. A screen opened
             * during onboarding should lead with what is unclassified and
             * unreachable rather than with what is already working.
             */
            ->orderByRaw("case safety_class when 'do_not_touch' then 0 when 'discovery_only' then 1 else 2 end")
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return $this->paginated($servers, fn (ManagedServer $server): array => (new ServerResource($server))->toArray($request));
    }

    public function show(Request $request, string $server): JsonResponse
    {
        $found = ManagedServer::query()->with('credential')->findOrFail($server);

        return response()->json(['data' => (new ServerResource($found))->toArray($request)]);
    }

    public function store(RegisterServerRequest $request, RegisterServer $register): JsonResponse
    {
        $server = $register->execute($request->toRegistration());

        return response()->json(
            ['data' => (new ServerResource($server))->toArray($request)],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Raise or lower what may be done to a machine.
     *
     * Reaching the destructive rung takes a second permission on the route as
     * well as this one, so an operator who may reclassify machines day to day
     * still cannot make one wipeable.
     */
    public function classify(ClassifyServerRequest $request, string $server, ClassifyServer $classify): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);
        $to = SafetyClass::from($request->string('safety_class')->value());

        if ($to->isDestructive() && $request->user()?->cannot('safety.allow_reimage')) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'Making a machine reimageable needs the reimage permission, not only the safety one.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $updated = $classify->execute(
                $found,
                $to,
                $request->user(),
                $request->string('reason')->value(),
                $request->input('confirm_name'),
            );
        } catch (ClassificationRefused $refused) {
            return $this->refused($refused->getMessage());
        }

        return response()->json(['data' => (new ServerResource($updated))->toArray($request)]);
    }

    public function clearForReimage(ClearForReimageRequest $request, string $server, ClearForReimage $clear): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        try {
            $updated = $clear->execute(
                $found,
                $request->user(),
                $request->string('reason')->value(),
                $request->string('confirm_name')->value(),
            );
        } catch (ClassificationRefused $refused) {
            return $this->refused($refused->getMessage());
        }

        return response()->json(['data' => (new ServerResource($updated))->toArray($request)]);
    }

    public function revokeReimageClearance(Request $request, string $server, RevokeReimageClearance $revoke): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        $updated = $revoke->execute($found, $request->user());

        return response()->json(['data' => (new ServerResource($updated))->toArray($request)]);
    }

    /**
     * Point this machine at the credential it is reached with.
     *
     * Lives here rather than in the Providers controller because the response
     * is a machine, and a module rendering another module's resource is the
     * boundary LayeringTest holds. The act itself — environment check, refusal
     * of a revoked credential, the audit row — is Providers' AttachCredential,
     * so both surfaces attach by one rule.
     */
    public function attachCredential(Request $request, string $server, AttachCredential $attach): JsonResponse
    {
        $request->validate(['credential_id' => ['required', 'string', 'exists:credential_references,id']]);

        $found = ManagedServer::query()->findOrFail($server);
        $credential = CredentialReference::query()->findOrFail($request->string('credential_id')->value());

        try {
            $attached = $attach->toServer($found, $credential, $request->user());
        } catch (CredentialRefused $refusal) {
            return response()->json([
                'error' => ['code' => 'credential_refused', 'message' => $refusal->getMessage()],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => (new ServerResource($attached->load('credential')))->toArray($request)]);
    }

    public function detachCredential(Request $request, string $server, AttachCredential $attach): JsonResponse
    {
        $found = ManagedServer::query()->with('credential')->findOrFail($server);

        $detached = $attach->fromServer($found, $request->user());

        return response()->json(['data' => (new ServerResource($detached->load('credential')))->toArray($request)]);
    }

    /**
     * Look at a machine through its BMC and record what it says about itself.
     *
     * Read, and only read. Refused for a do_not_touch machine exactly as a
     * connection test is, because it is one — with an inventory attached.
     */
    public function discover(Request $request, string $server, DiscoverServer $discover): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        try {
            $discovery = $discover->execute($found, $request->user());
        } catch (SafetyRefusal $refused) {
            return response()->json([
                'error' => [
                    'code' => 'safety_refused',
                    'message' => $refused->getMessage(),
                    'details' => [
                        'classification' => $refused->classification->value,
                        'attempted' => $refused->attempted->value,
                        'would_permit' => $refused->wouldPermit?->value,
                    ],
                ],
            ], Response::HTTP_CONFLICT);
        } catch (NoBmcProvider $missing) {
            return response()->json([
                'error' => ['code' => 'bmc_missing', 'message' => $missing->getMessage()],
            ], Response::HTTP_CONFLICT);
        } catch (NoSuchTester $unknown) {
            return response()->json([
                'error' => ['code' => 'unknown_driver', 'message' => $unknown->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'result' => $discovery->test->result->value,
                'usable' => $discovery->test->result->usable(),
                'facts' => count($discovery->facts),
                'server' => (new ServerResource($found->refresh()->load('credential')))->toArray($request),
            ],
        ]);
    }

    /**
     * What is currently known about a machine. Current facts only; the
     * superseded history is on the row and not on this screen.
     */
    public function facts(Request $request, string $server): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        $facts = $found->facts()->current()->orderBy('key')->get();

        return response()->json([
            'data' => $facts->map(fn ($fact): array => (new ServerFactResource($fact))->toArray($request))->all(),
        ]);
    }

    public function gpus(Request $request, string $server): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        return response()->json([
            'data' => $found->gpuDevices()->orderBy('pci_address')->get()
                ->map(fn ($device): array => (new GpuDeviceResource($device))->toArray($request))
                ->all(),
        ]);
    }

    public function registerGpu(RegisterGpuDeviceRequest $request, string $server, RegisterGpuDevice $register): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        try {
            $device = $register->execute(
                $found,
                $request->string('vendor')->value(),
                $request->string('model')->value(),
                $request->integer('vram_mib'),
                $request->string('pci_address')->value(),
                GpuPassthroughMode::from($request->string('passthrough_mode')->value()),
                $request->input('notes'),
                $request->user(),
            );
        } catch (DeploymentRefused $refusal) {
            return Refusals::deployment($refusal);
        }

        return response()->json(['data' => (new GpuDeviceResource($device))->toArray($request)], Response::HTTP_CREATED);
    }

    private function refused(string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'refused', 'message' => $message],
        ], Response::HTTP_CONFLICT);
    }
}
