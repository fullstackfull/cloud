<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Infrastructure\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\DTOs\RemoteBackup;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * Backups through Proxmox VE, onto a Proxmox Backup Server datastore.
 *
 * ---------------------------------------------------------------------------
 * The division of labour with the PBS Ansible role
 * ---------------------------------------------------------------------------
 *
 * The datastore, its prune schedule, its verification jobs and its restore
 * test are infrastructure. They are declared in inventory and applied by the
 * `pbs` role, which refuses a datastore that shares storage with the root
 * filesystem and refuses one with no verification or no restore test
 * configured. None of that is this class's business, and this class cannot
 * create, reconfigure or prune a datastore.
 *
 * What this does is per-customer and per-machine: start a backup of one VM
 * onto a datastore an operator has already declared, follow the task, read
 * back what the datastore holds, restore one, delete one.
 *
 * ---------------------------------------------------------------------------
 * Verification
 * ---------------------------------------------------------------------------
 *
 * {@see self::supportsVerification()} answers false, and that is a statement
 * about Proxmox rather than about this adapter. A PBS verification runs on the
 * backup server on its own schedule; the hypervisor API has no endpoint that
 * starts one. What the hypervisor does expose is the verdict of the last one,
 * per archive, in the storage listing — so the platform can tell a customer
 * whether their backup verified, and cannot offer them a button to verify it
 * now. Offering the button anyway would be the more comfortable lie.
 */
final class ProxmoxBackupProvider implements BackupProvider
{
    public const string NAME = 'proxmox';

    /**
     * Statuses where the request may or may not have taken effect.
     *
     * A 5xx from a hypervisor is not "no". Proxmox accepts a vzdump in
     * milliseconds and runs it for minutes, so a gateway timeout on the way
     * back says nothing about whether the job started.
     */
    private const array INDETERMINATE_STATUSES = [408, 502, 503, 504];

    public function __construct(
        private readonly ProxmoxConnection $connection,
        private readonly SecretRedactor $redactor,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function supportsVerification(): bool
    {
        return false;
    }

    public function startBackup(BackupRequest $request): BackupOperation
    {
        $parameters = [
            'vmid' => $request->providerId,
            'storage' => $request->datastore,
            'mode' => $request->mode->value,
            /*
             * Never let vzdump prune. `remove=0` says "keep what is already
             * there": retention on a PBS datastore is the prune job's
             * business, declared in inventory, and a backup call that also
             * deletes is a backup call that can delete the wrong thing.
             */
            'remove' => 0,
        ];

        if ($request->notes !== null && trim($request->notes) !== '') {
            $parameters['notes-template'] = trim($request->notes);
        }

        $upid = $this->post(sprintf('/nodes/%s/vzdump', $request->nodeName), $parameters, 'start_backup');

        return new BackupOperation(
            taskId: is_string($upid) ? $upid : '',
            nodeName: $request->nodeName,
        );
    }

    public function taskState(string $nodeName, string $taskId): BackupTaskState
    {
        /** @var array<string, mixed> $data */
        $data = $this->get(
            sprintf('/nodes/%s/tasks/%s/status', $nodeName, rawurlencode($taskId)),
            [],
            'backup_task_status',
        );

        $exitStatus = isset($data['exitstatus']) && is_string($data['exitstatus'])
            ? $this->scrub($data['exitstatus'])
            : null;

        /*
         * Proxmox reports lifecycle in "status" and outcome in "exitstatus",
         * and only the pair is meaningful. A stopped task with no exit status
         * yet has not finished — treating it as finished is how a backup is
         * marked complete before its last chunk is written.
         */
        $finished = ($data['status'] ?? null) !== 'running' && $exitStatus !== null;

        return new BackupTaskState(
            taskId: $taskId,
            finished: $finished,
            successful: $finished && $exitStatus === 'OK',
            exitStatus: $exitStatus,
        );
    }

    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        // Not reachable through a supported path: callers ask
        // supportsVerification() first, and the platform's own action does.
        // Kept as a refusal rather than as a silent no-op, because a no-op
        // here would leave a row in Verifying that nothing ever moves.
        throw BackupProviderException::refused(
            self::NAME,
            'start_verification',
            'Proxmox cannot start a PBS verification; it is a datastore job scheduled on the backup server.',
            ['datastore' => $datastore, 'archive_id' => $archiveId],
        );
    }

    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        $upid = $this->post(sprintf('/nodes/%s/qemu', $nodeName), [
            'vmid' => $providerId,
            'archive' => sprintf('%s:%s', $datastore, $archiveId),
            /*
             * The machine being restored over must already be stopped, and the
             * caller establishes that. `force` replaces an existing VM's disks
             * rather than refusing because the id is taken — which is the
             * whole point of a restore, and is also why nothing but an
             * explicit customer action reaches this method.
             */
            'force' => 1,
        ], 'start_restore');

        return new BackupOperation(taskId: is_string($upid) ? $upid : '', nodeName: $nodeName);
    }

    public function listBackups(string $nodeName, string $datastore, string $providerId): array
    {
        // Not annotated as a list of arrays: this is whatever the cluster
        // sent, and the per-row check below is what makes each element one.
        $rows = (array) $this->get(
            sprintf('/nodes/%s/storage/%s/content', $nodeName, rawurlencode($datastore)),
            ['content' => 'backup', 'vmid' => $providerId],
            'list_backups',
        );

        $backups = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $backups[] = new RemoteBackup(
                archiveId: (string) ($row['volid'] ?? ''),
                datastore: $datastore,
                sizeBytes: isset($row['size']) && is_numeric($row['size']) ? (int) $row['size'] : null,
                createdAt: isset($row['ctime']) && is_numeric($row['ctime']) ? (int) $row['ctime'] : null,
                verified: self::verificationVerdict($row),
                notes: isset($row['notes']) && is_string($row['notes']) ? $this->scrub($row['notes']) : null,
            );
        }

        return $backups;
    }

    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void
    {
        $this->send(
            'DELETE',
            sprintf('/nodes/%s/storage/%s/content/%s', $nodeName, rawurlencode($datastore), rawurlencode($archiveId)),
            [],
            'delete_backup',
        );
    }

    /**
     * PBS's verdict for one archive, as the storage listing reports it.
     *
     * Three answers, not two. Null means the datastore has never verified this
     * archive, which is not the same as having verified it and failed — and a
     * screen that renders both as a red cross tells a customer their backup is
     * broken when nobody has looked at it yet.
     *
     * @param  array<string, mixed>  $row
     */
    private static function verificationVerdict(array $row): ?bool
    {
        $verification = $row['verification'] ?? null;

        if (! is_array($verification)) {
            return null;
        }

        $state = $verification['state'] ?? null;

        return is_string($state) ? $state === 'ok' : null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function post(string $path, array $parameters, string $operation): mixed
    {
        return $this->dataFrom($this->send('POST', $path, $parameters, $operation), $operation, $path);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function get(string $path, array $parameters, string $operation): mixed
    {
        return $this->dataFrom($this->send('GET', $path, $parameters, $operation), $operation, $path);
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws BackupProviderException
     */
    private function send(string $method, string $path, array $parameters, string $operation): Response
    {
        try {
            $request = $this->connection->request();

            $response = match ($method) {
                'GET' => $request->get($path, $parameters),
                'POST' => $request->post($path, $parameters),
                // Proxmox reads a DELETE's parameters from the query string.
                'DELETE' => $request->delete($parameters === [] ? $path : $path.'?'.http_build_query($parameters)),
                default => throw BackupProviderException::refused(
                    self::NAME,
                    $operation,
                    sprintf('unsupported HTTP method "%s"', $method),
                ),
            };

            if ($response->redirect()) {
                // A Location header is chosen by the cluster. The client is
                // configured not to follow one; this is what turns that into a
                // reported failure rather than an empty body.
                throw BackupProviderException::refused(self::NAME, $operation, 'the cluster answered with a redirect', [
                    'path' => $path,
                    'status' => $response->status(),
                ]);
            }

            return $response;
        } catch (ConnectionException $e) {
            /*
             * Caught by type and re-thrown as our own: a connection exception
             * carries the full request in its message, and every request here
             * has the API token in its Authorization header.
             *
             * Indeterminate, which is the part that matters. Proxmox accepts a
             * vzdump in milliseconds and runs it for an hour, so a call that
             * timed out has very possibly started a backup that is now writing
             * to the datastore and holding the machine's disks. Retrying would
             * run a second one over the same disks.
             */
            throw BackupProviderException::timedOut(self::NAME, $operation, [
                'path' => $path,
                'provider_message' => $this->scrub($e->getMessage()),
            ]);
        } catch (BackupProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Anything else from the transport is unknown by definition, and
            // gets the same caution as a timeout.
            throw BackupProviderException::timedOut(self::NAME, $operation, [
                'path' => $path,
                'provider_message' => $this->scrub($e->getMessage()),
            ]);
        }
    }

    /**
     * @throws BackupProviderException
     */
    private function dataFrom(Response $response, string $operation, string $path): mixed
    {
        if ($response->failed()) {
            $context = ['path' => $path, 'status' => $response->status(), 'provider_message' => $this->errorMessage($response)];

            throw in_array($response->status(), self::INDETERMINATE_STATUSES, true)
                ? BackupProviderException::timedOut(self::NAME, $operation, $context)
                : BackupProviderException::refused(self::NAME, $operation, $this->errorMessage($response), $context);
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ! array_key_exists('data', $decoded)) {
            throw BackupProviderException::refused(
                self::NAME,
                $operation,
                'the body is not a Proxmox API envelope',
                ['path' => $path, 'status' => $response->status()],
            );
        }

        return $decoded['data'];
    }

    private function errorMessage(Response $response): string
    {
        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        $message = is_array($body) && isset($body['errors']) && is_array($body['errors'])
            ? implode('; ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $body['errors']))
            : trim($response->body());

        return $this->scrub($message === '' ? sprintf('HTTP %d', $response->status()) : $message);
    }

    private function scrub(string $message): string
    {
        return $this->connection->scrub($message, $this->redactor);
    }
}
