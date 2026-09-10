<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Vps\Application\Actions\IssueConsoleSession;
use Lynomia\Modules\Vps\Application\Actions\RequestVpsPowerChange;
use Lynomia\Modules\Vps\Application\Actions\RequestVpsReinstall;
use Lynomia\Modules\Vps\Http\Requests\ListVirtualMachinesRequest;
use Lynomia\Modules\Vps\Http\Requests\PowerActionRequest;
use Lynomia\Modules\Vps\Http\Requests\ReinstallRequest;
use Lynomia\Modules\Vps\Http\Resources\ConsoleSessionResource;
use Lynomia\Modules\Vps\Http\Resources\ProvisioningOperationResource;
use Lynomia\Modules\Vps\Http\Resources\VirtualMachineResource;
use Lynomia\Modules\Vps\Infrastructure\Queries\CustomerVirtualMachines;
use Lynomia\Modules\Vps\Infrastructure\Queries\LatestMachineReinstalls;
use Lynomia\Modules\Vps\Infrastructure\Queries\UnresolvedServiceWork;
use Lynomia\Modules\Vps\Infrastructure\Queries\VirtualMachineAddresses;

/**
 * The customer-facing VPS surface.
 *
 * Three rules hold across every method, and none of them is checked twice.
 *
 * **Scoping, not checking.** Every machine this controller touches is fetched
 * through `CustomerVirtualMachines::of($acting)`, which starts from the
 * customer and joins down through their services. Another tenant's id matches
 * no row and the request 404s. There is no `where('customer_id')` at a call
 * site to forget, and no `abort_unless($vm->service->customer_id === ...)`
 * afterwards — a check that runs after an unscoped fetch has already had the
 * row, its hostname and its address in hand.
 *
 * **404, never 403, for another tenant's id.** Ids here are ULIDs and a 403
 * confirms the row exists. Answering identically for "no such machine" and
 * "not your machine" is what stops this being an enumeration oracle over the
 * whole fleet. The within-account permission check therefore runs *before* any
 * lookup, so its 403 depends on the caller's role and never on whether the id
 * was real.
 *
 * **No transaction, no provider call, no retry.** Power, reinstall and console
 * are actions; the two that touch a hypervisor go through the provisioning
 * engine, which owns claiming, attempts, classification and the rule this
 * controller would be the worst possible place to implement — that a timed-out
 * operation is escalated to a person and never retried automatically. Nothing
 * here catches a failure and tries again, and nothing here releases a
 * reservation.
 */
final class VpsController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly RequestVpsPowerChange $powerChange,
        private readonly RequestVpsReinstall $reinstall,
        private readonly IssueConsoleSession $issueConsole,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->acting;
    }

    /**
     * The acting customer's machines, newest first.
     */
    public function index(ListVirtualMachinesRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $powerState = $request->powerState();

        /** @var LengthAwarePaginator<int, VirtualMachine> $machines */
        $machines = CustomerVirtualMachines::of($this->acting->get())
            ->with('service')
            ->when($powerState !== null, fn ($query) => $query->where('power_state', $powerState->value))
            // The ULID tie-breaks two machines created in the same
            // millisecond, so paging is stable and a row cannot appear on two
            // pages while the customer walks the list.
            ->orderByDesc('virtual_machines.created_at')
            ->orderByDesc('virtual_machines.id')
            ->paginate($request->perPage());

        $machineIds = $machines->getCollection()
            ->map(static fn (VirtualMachine $vm): string => (string) $vm->getKey())
            ->all();

        $addresses = VirtualMachineAddresses::forMachines($machineIds);
        $reinstalls = LatestMachineReinstalls::forMachines($machineIds);
        $unresolved = UnresolvedServiceWork::forServices(
            $machines->getCollection()->map(static fn (VirtualMachine $vm): string => (string) $vm->service_id)->all(),
        );

        return response()->json([
            'data' => $machines->getCollection()
                ->map(fn (VirtualMachine $vm): VirtualMachineResource => new VirtualMachineResource(
                    $vm,
                    $addresses[(string) $vm->getKey()] ?? [],
                    $reinstalls[(string) $vm->getKey()] ?? null,
                    $unresolved[(string) $vm->service_id] ?? null,
                ))
                ->all(),
            'meta' => [
                'page' => $machines->currentPage(),
                'per_page' => $machines->perPage(),
                'total' => $machines->total(),
                'last_page' => $machines->lastPage(),
                'max_per_page' => ListVirtualMachinesRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * One machine.
     */
    public function show(Request $request, string $vm): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $machine = $this->machine($vm);

        $machineId = (string) $machine->getKey();

        return (new VirtualMachineResource(
            $machine,
            VirtualMachineAddresses::forMachines([$machineId])[$machineId] ?? [],
            LatestMachineReinstalls::forMachines([$machineId])[$machineId] ?? null,
            UnresolvedServiceWork::forServices([$machine->service_id])[(string) $machine->service_id] ?? null,
        ))->response();
    }

    /**
     * Start, stop, reboot or shut down.
     *
     * 202, not 200: the hypervisor has been asked, not obeyed. A machine takes
     * seconds to stop and the engine reports the outcome on the job, so
     * answering 200 with the machine's old power state would be telling the
     * customer the operation is finished when it has not started.
     *
     * A repeated Idempotency-Key returns the operation that already exists,
     * with the same 202 — a client retrying a dropped response gets its own
     * job back rather than a second reboot.
     */
    public function power(PowerActionRequest $request, string $vm): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $job = $this->powerChange->execute(
            $this->machine($vm),
            $request->action(),
            $request->idempotencyKey(),
        );

        return (new ProvisioningOperationResource($job))->response()->setStatusCode(202);
    }

    /**
     * Wipe the machine and lay it down again.
     *
     * The confirmation must repeat the machine's hostname; see
     * {@see ReinstallRequest} for why it is not a boolean. The comparison
     * itself lives in the action, so the same proof is demanded of a support
     * script that never goes near HTTP.
     */
    public function reinstall(ReinstallRequest $request, string $vm): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $machine = $this->machine($vm);

        $job = $this->reinstall->execute(
            machine: $machine,
            confirmation: $request->confirmation(),
            idempotencyKey: $request->idempotencyKey(),
            template: $this->template($machine, $request->templateId()),
            sshKeys: $request->sshKeys(),
        );

        return (new ProvisioningOperationResource($job))->response()->setStatusCode(202);
    }

    /**
     * A permit to open one console, once, within the next minute.
     *
     * A GET that mints a credential is unusual and is what the surface
     * specifies; it is safe here only because the permit is single-use and
     * expires in a minute, so a repeated GET — a refresh, a prefetch, a
     * retried request — costs a wasted session rather than a lingering one.
     * It carries a tighter rate limit than the rest of the group for exactly
     * that reason.
     */
    public function console(Request $request, string $vm): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $user = $request->user();

        $session = $this->issueConsole->execute(
            $this->machine($vm),
            $this->acting->get(),
            $user instanceof User ? (string) $user->getKey() : null,
        );

        return (new ConsoleSessionResource($session))->response()->setStatusCode(201);
    }

    /**
     * One of the acting customer's machines, or a 404.
     *
     * The only way a machine enters this class. Started from the customer, so
     * an id belonging to another tenant is not fetched-and-rejected, it is
     * simply not found.
     */
    private function machine(string $id): VirtualMachine
    {
        /** @var VirtualMachine $machine */
        $machine = CustomerVirtualMachines::of($this->acting->get())
            ->with('service')
            ->whereKey($id)
            ->firstOrFail();

        return $machine;
    }

    /**
     * The image to reinstall with, resolved against this machine's own cluster.
     *
     * A template id arrives in a request body, so it is treated as a request
     * to install something rather than as a fact. Three constraints, and each
     * of them rules out a real failure: `installable` excludes retired
     * catalogue entries and ones never staged at a provider; the cluster match
     * excludes an image that exists but not on the hardware this machine runs
     * on; and a null cluster_id is allowed through because a template staged
     * fleet-wide is legitimately usable anywhere.
     *
     * A miss is a 404 rather than a 422, and the same 404 the caller would get
     * for an id that does not exist at all — template ids are ULIDs too.
     */
    private function template(VirtualMachine $machine, ?string $templateId): ?VmTemplate
    {
        if ($templateId === null) {
            return null;
        }

        /** @var VmTemplate $template */
        $template = VmTemplate::query()
            ->installable()
            ->where(fn ($query) => $query
                ->whereNull('cluster_id')
                ->orWhere('cluster_id', $machine->cluster_id))
            ->whereKey($templateId)
            ->firstOrFail();

        return $template;
    }
}
