<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupTrigger;
use Lynomia\Modules\Backups\Http\Requests\CreateBackupRequest;
use Lynomia\Modules\Backups\Http\Requests\ListBackupsRequest;
use Lynomia\Modules\Backups\Http\Requests\RestoreBackupRequest;
use Lynomia\Modules\Backups\Http\Resources\BackupResource;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Vps\Infrastructure\Queries\CustomerVirtualMachines;

/**
 * A customer's backups.
 *
 * Scoped the same way the VPS surface is, and for the same reason: every row
 * this controller can reach is fetched through a query that starts at the
 * acting customer, so another tenant's id matches nothing and 404s. There is
 * no ownership check after an unscoped fetch — a check that runs after the row
 * is already in hand has already had it.
 *
 * Taking a backup is `service.manage` and not `service.view`. It costs
 * datastore space and, in stop mode, an outage: a read-only member of an
 * account should not be able to interrupt the machine their colleagues are
 * using.
 */
final class BackupController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly RequestServiceBackup $request,
        private readonly RestoreServiceBackup $restore,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->acting;
    }

    public function index(ListBackupsRequest $request, string $vm): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $machine = $this->machine($vm);
        $state = $request->state();

        /** @var LengthAwarePaginator<int, Backup> $backups */
        $backups = Backup::query()
            ->where('virtual_machine_id', $machine->getKey())
            ->when($state !== null, fn ($query) => $query->where('state', $state->value))
            // The ULID tie-breaks two rows created in the same millisecond, so
            // paging is stable and a backup cannot appear on two pages while
            // the customer walks the list.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => BackupResource::collection($backups->getCollection()),
            'meta' => [
                'page' => $backups->currentPage(),
                'per_page' => $backups->perPage(),
                'total' => $backups->total(),
                'last_page' => $backups->lastPage(),
                'max_per_page' => ListBackupsRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * Ask for a backup now.
     *
     * Answers 202 and not 201. The row exists, and the backup does not: the
     * provider has been asked and will be running for minutes to hours. A 201
     * would say the thing was created, which is the claim this whole module
     * is arranged not to make early.
     */
    public function store(CreateBackupRequest $request, string $vm): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        /** @var User $user */
        $user = $request->user();

        $backup = $this->request->execute(
            machine: $this->machine($vm),
            trigger: BackupTrigger::Manual,
            mode: $request->mode(),
            requestedBy: $user,
            notes: $request->notes(),
        );

        return (new BackupResource($backup))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $vm, string $backup): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $machine = $this->machine($vm);

        /** @var Backup $row */
        $row = Backup::query()
            ->where('virtual_machine_id', $machine->getKey())
            ->whereKey($backup)
            ->firstOrFail();

        return (new BackupResource($row))->response();
    }

    /**
     * Put a backup back over the machine it came from.
     *
     * Behind service.manage rather than service.view, and behind a typed
     * confirmation the action checks itself. Everything this calls existed
     * already — the provider's startRestore, the Restoring state, the poller
     * that watches the task — and had no caller, because the confirmation
     * flow it needs did not exist and shipping the endpoint without one would
     * have shipped the dangerous half first.
     */
    public function restore(RestoreBackupRequest $request, string $vm, string $backup): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $machine = $this->machine($vm);

        /*
         * Scoped to the machine from the path, which is itself scoped to the
         * acting customer. A backup id belonging to another account cannot be
         * reached here at all, and the answer is the same 404 as an id that
         * does not exist — the API does not confirm the existence of other
         * people's data.
         */
        /** @var Backup $row */
        $row = Backup::query()
            ->where('virtual_machine_id', $machine->getKey())
            ->whereKey($backup)
            ->firstOrFail();

        $restored = $this->restore->execute(
            backup: $row,
            machine: $machine,
            confirmation: $request->confirmation(),
            restoredByUserId: (string) $request->user()?->getAuthIdentifier(),
        );

        /*
         * Recorded after the fact, like every other audited act. A restore is
         * the most destructive thing this API offers a customer and the one
         * most likely to be disputed afterwards.
         */
        app(RecordAuditEntry::class)->execute(
            action: AuditAction::BackupRestored,
            subject: $restored,
            customerId: $restored->customer_id,
            context: [
                'service_id' => $restored->service_id,
                'virtual_machine_id' => $restored->virtual_machine_id,
                'hostname' => $machine->hostname,
                'archive_id' => $restored->archive_id,
                'backup_taken_at' => $restored->finished_at?->toIso8601String(),
            ],
        );

        // 202: the provider has accepted the work and it is running. Answering
        // 200 would tell a customer their machine is back when the disks are
        // still being written.
        return (new BackupResource($restored))->response()->setStatusCode(202);
    }

    private function machine(string $id): VirtualMachine
    {
        /** @var VirtualMachine $machine */
        $machine = CustomerVirtualMachines::of($this->acting->get())
            ->with('service')
            ->whereKey($id)
            ->firstOrFail();

        return $machine;
    }
}
