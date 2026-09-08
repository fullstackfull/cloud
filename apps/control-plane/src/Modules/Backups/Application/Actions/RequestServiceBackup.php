<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\Enums\BackupMode;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\BackupTrigger;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupNotConfiguredException;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Ask a provider to back up one machine, and record honestly what happened.
 *
 * ---------------------------------------------------------------------------
 * The three outcomes, and why the third exists
 * ---------------------------------------------------------------------------
 *
 * The row is written first, in `Requested`, before the provider is called.
 * That ordering is the point: if the process dies between the call and the
 * write, the platform still has a row saying it asked, and an operator can
 * find the orphan. The other order loses the request entirely and leaves a
 * backup running that nothing knows about.
 *
 * Then one of three things happens.
 *
 *  - **Accepted.** The provider returns a task identifier and the row becomes
 *    `Running`. Not `Succeeded`: nothing has been backed up yet, and a
 *    customer told otherwise finds out on the day they need it.
 *  - **Refused.** The provider answered and the answer was no. `Failed`, with
 *    the reason, and nothing is retried automatically — a refusal repeated is
 *    a refusal.
 *  - **No answer.** The call timed out. This is the one that needs its own
 *    state: Proxmox accepts a vzdump in milliseconds and runs it for an hour,
 *    so the backup may well be running right now, writing to the datastore and
 *    holding the machine's disks. Recording `Failed` invites a retry that runs
 *    a second backup over the same disks; recording `Running` invites a poller
 *    to chase a task id that was never issued. The row goes to `NeedsReview`
 *    and a person looks at the datastore, which is the only thing that
 *    actually resolves it.
 */
final readonly class RequestServiceBackup
{
    public function __construct(
        private BackupProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @throws BackupNotConfiguredException
     */
    public function execute(
        VirtualMachine $machine,
        BackupTrigger $trigger = BackupTrigger::Manual,
        BackupMode $mode = BackupMode::Snapshot,
        ?User $requestedBy = null,
        ?string $notes = null,
        ?int $retentionDays = null,
    ): Backup {
        $service = $machine->service()->firstOrFail();
        $cluster = $machine->cluster()->first();

        if ($cluster === null) {
            throw BackupNotConfiguredException::notBackable(
                (string) $service->getKey(),
                'the machine is not attached to a cluster',
            );
        }

        $providerId = trim((string) $machine->provider_id);

        if ($providerId === '') {
            // A machine the platform has a row for and the hypervisor has
            // never heard of. There is nothing to back up, and asking the
            // provider to back up an empty id would back up something else.
            throw BackupNotConfiguredException::notBackable(
                (string) $service->getKey(),
                'the machine has no provider identifier yet',
            );
        }

        $datastore = $this->providers->datastoreFor($cluster);
        $provider = $this->providers->for($cluster);

        // `provider_name` and not `name`: it is the node's name *in the
        // hypervisor*, which is what a vzdump path needs. The platform has no
        // separate display name for a node.
        $node = trim((string) ($machine->node()->first()->provider_name ?? ''));

        if ($node === '') {
            throw BackupNotConfiguredException::notBackable(
                (string) $service->getKey(),
                'the machine is not placed on a node',
            );
        }

        /*
         * Written before the call and outside any transaction that the call
         * sits inside. A transaction that wrapped the provider call would hold
         * a row lock for the length of an HTTP request to a hypervisor, and
         * roll the record away on a failure that the platform specifically
         * wants to keep.
         */
        $backup = DB::transaction(static fn (): Backup => Backup::query()->create([
            'customer_id' => $service->customer_id,
            'service_id' => $service->getKey(),
            'virtual_machine_id' => $machine->getKey(),
            'cluster_id' => $cluster->getKey(),
            'provider' => $provider->name(),
            'state' => BackupState::Requested,
            'trigger' => $trigger,
            'mode' => $mode,
            'node_name' => $node,
            'datastore' => $datastore,
            'retention_days' => $retentionDays,
            'requested_by_user_id' => $requestedBy?->getKey(),
        ]));

        try {
            $operation = $provider->startBackup(new BackupRequest(
                nodeName: $node,
                providerId: $providerId,
                datastore: $datastore,
                mode: $mode,
                notes: $notes,
                retentionDays: $retentionDays,
            ));
        } catch (BackupProviderException $e) {
            $backup->transitionTo(
                $e->isIndeterminate() ? BackupState::NeedsReview : BackupState::Failed,
                [
                    /*
                     * Redacted here as well as in the adapter. The adapter
                     * scrubs what it knows about — its own connection's token
                     * — and a fake or a future driver may not scrub at all;
                     * this is the last point before a provider's words are
                     * written to a row an operator will read. Kept rather than
                     * discarded because "no space left on device" and
                     * "permission denied" need completely different human
                     * responses.
                     */
                    'failure_reason' => $this->redactor->redactString($e->getMessage()),
                    'finished_at' => $e->isIndeterminate() ? null : now(),
                ],
            );

            return $backup->refresh();
        }

        $backup->transitionTo(BackupState::Running, [
            'provider_task_id' => $operation->taskId,
            'started_at' => now(),
        ]);

        return $backup->refresh();
    }
}
