<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CloudInitConfig;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteNodeState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteStorageState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteTaskState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\VmOperation;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * The Proxmox VE API v2, spoken properly.
 *
 * Four decisions define this adapter, and each of them rules out something the
 * internet is full of examples of:
 *
 *  - authentication is an API token in an Authorization header, and only that.
 *    No root password, no login ticket to refresh, no CSRF token to carry, and
 *    certainly no scraping of the web UI. A token is scoped and revocable; a
 *    root password in a config file is a full compromise of every customer's
 *    machine on that cluster;
 *
 *  - TLS verification is on unless a specific cluster row turns it off, and it
 *    can only be turned off for that cluster. The usual shortcut — a global
 *    "verify: false" for the lab — is how a production cluster ends up
 *    accepting any certificate anybody presents;
 *
 *  - every mutation returns Proxmox's UPID as the operation's task id. Proxmox
 *    answers a create in milliseconds and builds the machine over the next few
 *    minutes; the UPID is the only handle that exists on the outcome, and the
 *    provisioning engine persists it before it treats the call as in flight.
 *    Without it, a worker that dies mid-build cannot tell "never started" from
 *    "already running", and the safe-looking assumption — retry — creates a
 *    second machine nobody will ever bill for or delete;
 *
 *  - no exception from the HTTP client is ever allowed to escape. A Guzzle
 *    exception stringifies the request that caused it, and every request this
 *    class makes carries the API token. Failures are translated here, with the
 *    token removed by name before the redactor's generic patterns get a look
 *    in — the redactor cannot be expected to recognise a secret whose shape
 *    only this object knows.
 */
final class ProxmoxComputeProvider implements ComputeProvider
{
    public const string NAME = 'proxmox';

    /**
     * Marks the task id of an operation Proxmox completed synchronously.
     *
     * Some endpoints — a config change, for one — do their work before they
     * answer and return no UPID. The contract still promises a task id, so one
     * is synthesised and getTask() recognises it without going back to the API,
     * which would reject a task id the cluster never issued.
     */
    private const string SYNCHRONOUS_TASK_PREFIX = 'sync:';

    private const int BYTES_PER_MIB = 1048576;

    private const int BYTES_PER_GIB = 1073741824;

    public function __construct(
        private readonly ProxmoxConnection $connection,
        private readonly SecretRedactor $redactor,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function createVirtualMachine(CreateVmRequest $request): VmOperation
    {
        $upid = $this->expectTaskId(
            $this->post(sprintf('/nodes/%s/qemu', $request->nodeName), $this->createParameters($request), 'create_vm'),
            'create_vm',
            $request->nodeName,
        );

        return new VmOperation(
            taskId: $upid,
            nodeName: $request->nodeName,
            providerId: (string) $request->vmId,
            operation: 'create_vm',
            metadata: ['hostname' => $request->hostname, 'storage' => $request->storageName],
        );
    }

    public function startVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'start');
    }

    public function stopVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'stop');
    }

    public function shutdownVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'shutdown');
    }

    public function rebootVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'reboot');
    }

    public function resetVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'reset');
    }

    public function resizeVm(string $nodeName, string $providerId, ResizeVmRequest $request): VmOperation
    {
        if ($request->isEmpty()) {
            throw ComputeProviderException::requestFailed(self::NAME, 'resize_vm', [
                'node' => $nodeName,
                'vmid' => $providerId,
                'provider_message' => 'a resize was requested with nothing to change',
            ]);
        }

        $config = array_filter([
            'cores' => $request->vcpu,
            'memory' => $request->memoryMib,
        ], static fn (?int $value): bool => $value !== null);

        if ($config !== []) {
            $this->put(sprintf('/nodes/%s/qemu/%s/config', $nodeName, $providerId), $config, 'resize_vm');
        }

        if ($request->diskGib === null) {
            return new VmOperation(
                taskId: $this->synchronousTaskId('resize_vm', $nodeName, $providerId),
                nodeName: $nodeName,
                providerId: $providerId,
                operation: 'resize_vm',
                status: RemoteTaskStatus::Succeeded,
                metadata: $config,
            );
        }

        /*
         * Disk growth goes through the resize endpoint rather than the config
         * PUT, because the config PUT would replace the disk's definition and
         * detach the existing volume — the machine would come back up with an
         * empty disk of the right size. The "+" prefix is also load bearing:
         * without it the value is an absolute size, and an absolute size
         * smaller than the current one silently truncates a customer's
         * filesystem.
         */
        $data = $this->put(
            sprintf('/nodes/%s/qemu/%s/resize', $nodeName, $providerId),
            ['disk' => 'scsi0', 'size' => sprintf('+%dG', $request->diskGib)],
            'resize_vm',
        );

        return new VmOperation(
            taskId: is_string($data) && $data !== ''
                ? $data
                : $this->synchronousTaskId('resize_vm', $nodeName, $providerId),
            nodeName: $nodeName,
            providerId: $providerId,
            operation: 'resize_vm',
            status: is_string($data) && $data !== '' ? RemoteTaskStatus::Running : RemoteTaskStatus::Succeeded,
            metadata: [...$config, 'disk_gib_added' => $request->diskGib],
        );
    }

    public function destroyVm(string $nodeName, string $providerId, bool $purge = true): VmOperation
    {
        $upid = $this->expectTaskId(
            $this->delete(sprintf('/nodes/%s/qemu/%s', $nodeName, $providerId), [
                // Without purge the machine's id keeps appearing in backup
                // jobs and HA groups after it is gone, and the next customer
                // to be issued that id inherits both.
                'purge' => $purge ? 1 : 0,
                'destroy-unreferenced-disks' => $purge ? 1 : 0,
            ], 'destroy_vm'),
            'destroy_vm',
            $nodeName,
        );

        return new VmOperation(
            taskId: $upid,
            nodeName: $nodeName,
            providerId: $providerId,
            operation: 'destroy_vm',
        );
    }

    public function getVm(string $nodeName, string $providerId): ?RemoteVmState
    {
        $response = $this->send('GET', sprintf('/nodes/%s/qemu/%s/status/current', $nodeName, $providerId), [], 'get_vm');

        // A machine that is not there is an answer, not a failure: a destroy
        // is confirmed by absence, and reconciliation has to be able to ask
        // about a machine an operator deleted by hand.
        if ($this->isMissingResource($response)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = $this->dataFrom($response, 'get_vm', ['node' => $nodeName, 'vmid' => $providerId]);

        return $this->toVmState($nodeName, $providerId, $data);
    }

    public function listVms(string $nodeName): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->get(sprintf('/nodes/%s/qemu', $nodeName), [], 'list_vms');

        $machines = [];

        foreach ($rows as $row) {
            $vmid = $row['vmid'] ?? null;

            if ($vmid === null) {
                continue;
            }

            $machines[] = $this->toVmState($nodeName, (string) $vmid, $row);
        }

        return $machines;
    }

    public function getTask(string $nodeName, string $taskId): RemoteTaskState
    {
        if (str_starts_with($taskId, self::SYNCHRONOUS_TASK_PREFIX)) {
            return new RemoteTaskState($taskId, $nodeName, RemoteTaskStatus::Succeeded, 'OK');
        }

        /** @var array<string, mixed> $data */
        $data = $this->get(
            sprintf('/nodes/%s/tasks/%s/status', $nodeName, rawurlencode($taskId)),
            [],
            'get_task',
        );

        $exitStatus = isset($data['exitstatus']) && is_string($data['exitstatus'])
            ? $this->scrub($data['exitstatus'])
            : null;

        /*
         * Proxmox reports a task's lifecycle in "status" (running/stopped) and
         * its outcome in "exitstatus", and only the pair is meaningful. A
         * stopped task with no exit status yet is still running as far as the
         * caller is concerned — treating it as finished would let provisioning
         * mark a machine ready before its disk had been written.
         */
        $status = match (true) {
            ($data['status'] ?? null) === 'running' => RemoteTaskStatus::Running,
            $exitStatus === null => RemoteTaskStatus::Running,
            $exitStatus === 'OK' => RemoteTaskStatus::Succeeded,
            default => RemoteTaskStatus::Failed,
        };

        return new RemoteTaskState(
            taskId: $taskId,
            nodeName: $nodeName,
            status: $status,
            exitStatus: $exitStatus,
            startedAt: isset($data['starttime']) && is_numeric($data['starttime']) ? (int) $data['starttime'] : null,
        );
    }

    public function listNodes(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->get('/nodes', [], 'list_nodes');

        $nodes = [];

        foreach ($rows as $row) {
            $name = $row['node'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $online = ($row['status'] ?? null) === 'online';

            $nodes[] = new RemoteNodeState(
                name: $name,
                online: $online,
                cpuCores: (int) ($row['maxcpu'] ?? 0),
                memoryTotalMib: $this->toMib($row['maxmem'] ?? null),
                memoryUsedMib: isset($row['mem']) ? $this->toMib($row['mem']) : null,
                cpuUsage: isset($row['cpu']) && is_numeric($row['cpu']) ? (float) $row['cpu'] : null,
                storageTotalGib: null,
                storageAvailableGib: null,
                // Only queried for nodes that are actually up: an offline node
                // answers every storage request with a timeout, and an
                // inventory sync of a cluster with one dead node would
                // otherwise take as long as the timeout times the fleet.
                storages: $online ? $this->listStorages($name) : [],
                capabilities: $this->redactor->redact([
                    'pve_version' => $row['pveversion'] ?? null,
                    'level' => $row['level'] ?? null,
                ]),
            );
        }

        return $this->withStorageTotals($nodes);
    }

    /**
     * Storage pools visible from one node.
     *
     * @return list<RemoteStorageState>
     */
    private function listStorages(string $nodeName): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->get(sprintf('/nodes/%s/storage', $nodeName), [], 'list_storages');

        $storages = [];

        foreach ($rows as $row) {
            $name = $row['storage'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $storages[] = new RemoteStorageState(
                name: $name,
                shared: (bool) ($row['shared'] ?? false),
                storageClass: self::inferStorageClass($name, is_string($row['type'] ?? null) ? $row['type'] : null),
                totalGib: isset($row['total']) ? $this->toGib($row['total']) : null,
                availableGib: isset($row['avail']) ? $this->toGib($row['avail']) : null,
                active: (bool) ($row['active'] ?? true),
            );
        }

        return $storages;
    }

    /**
     * Fill in each node's storage totals from the pools it can see.
     *
     * Shared pools are counted once per node rather than summed across the
     * cluster: a Ceph pool with 10 TiB free offers 10 TiB to every node, not
     * 10 TiB times the node count, and summing it would let the scheduler
     * commit the same space several times over.
     *
     * @param  list<RemoteNodeState>  $nodes
     * @return list<RemoteNodeState>
     */
    private function withStorageTotals(array $nodes): array
    {
        return array_map(static function (RemoteNodeState $node): RemoteNodeState {
            $total = 0;
            $available = 0;

            foreach ($node->storages as $storage) {
                if (! $storage->active) {
                    continue;
                }

                $total += $storage->totalGib ?? 0;
                $available += $storage->availableGib ?? 0;
            }

            return new RemoteNodeState(
                name: $node->name,
                online: $node->online,
                cpuCores: $node->cpuCores,
                memoryTotalMib: $node->memoryTotalMib,
                memoryUsedMib: $node->memoryUsedMib,
                cpuUsage: $node->cpuUsage,
                storageTotalGib: $node->storages === [] ? null : $total,
                storageAvailableGib: $node->storages === [] ? null : $available,
                storages: $node->storages,
                capabilities: $node->capabilities,
            );
        }, $nodes);
    }

    private function changePowerState(string $nodeName, string $providerId, string $action): VmOperation
    {
        $upid = $this->expectTaskId(
            $this->post(sprintf('/nodes/%s/qemu/%s/status/%s', $nodeName, $providerId, $action), [], $action.'_vm'),
            $action.'_vm',
            $nodeName,
        );

        return new VmOperation(
            taskId: $upid,
            nodeName: $nodeName,
            providerId: $providerId,
            operation: $action.'_vm',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createParameters(CreateVmRequest $request): array
    {
        $disk = sprintf('%s:%d', $request->storageName, $request->diskGib);

        if ($request->templateReference !== null) {
            // Importing rather than cloning keeps the platform's images
            // immutable: a clone source that an operator boots "just to patch
            // it" changes every machine created afterwards.
            $disk .= sprintf(',import-from=%s', $request->templateReference);
        }

        $network = sprintf('virtio,bridge=%s', $request->networkBridge);

        if ($request->vlanTag !== null) {
            $network .= sprintf(',tag=%d', $request->vlanTag);
        }

        if ($request->networkRateMbps !== null) {
            $network .= sprintf(',rate=%d', $request->networkRateMbps);
        }

        $parameters = [
            'vmid' => $request->vmId,
            'name' => $request->hostname,
            'cores' => $request->vcpu,
            'sockets' => 1,
            'memory' => $request->memoryMib,
            // Ballooning off: memory is never overcommitted by this platform,
            // and a balloon driver that reclaims memory under pressure would
            // give a guest less than the customer bought.
            'balloon' => 0,
            'ostype' => $request->osFamily->proxmoxOsType(),
            'scsihw' => 'virtio-scsi-single',
            'scsi0' => $disk,
            'net0' => $network,
            'agent' => 1,
            'onboot' => 1,
            'boot' => 'order=scsi0',
            'start' => $request->startAfterCreate ? 1 : 0,
        ];

        if ($request->description !== null) {
            $parameters['description'] = $request->description;
        }

        if ($request->tags !== []) {
            $parameters['tags'] = self::formatTags($request->tags);
        }

        if ($request->cloudInit !== null) {
            $parameters['ide2'] = sprintf('%s:cloudinit', $request->storageName);
            $parameters += $this->cloudInitParameters($request->cloudInit);
        }

        return $parameters;
    }

    /**
     * @return array<string, string>
     */
    private function cloudInitParameters(CloudInitConfig $cloudInit): array
    {
        $parameters = ['ciuser' => $cloudInit->user];

        if ($cloudInit->hasKeys()) {
            /*
             * URL-encoded, which is what the Proxmox API requires for this one
             * parameter: the keys arrive as a newline-separated block and the
             * API cannot otherwise tell a newline inside the value from the
             * end of the field. An unencoded block is accepted and silently
             * truncated to the first key, which on a multi-key account means a
             * server the customer cannot log into.
             */
            $parameters['sshkeys'] = rawurlencode($cloudInit->sshKeyBlock());
        }

        if ($cloudInit->ipConfig !== null) {
            $parameters['ipconfig0'] = $cloudInit->ipConfig;
        }

        $nameservers = $cloudInit->nameserverList();

        if ($nameservers !== null) {
            $parameters['nameserver'] = $nameservers;
        }

        if ($cloudInit->searchDomain !== null) {
            $parameters['searchdomain'] = $cloudInit->searchDomain;
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toVmState(string $nodeName, string $providerId, array $row): RemoteVmState
    {
        return new RemoteVmState(
            providerId: $providerId,
            nodeName: $nodeName,
            name: isset($row['name']) && is_string($row['name']) ? $row['name'] : null,
            powerState: PowerState::fromProxmox(is_string($row['status'] ?? null) ? $row['status'] : null),
            vcpu: isset($row['cpus']) && is_numeric($row['cpus']) ? (int) $row['cpus'] : null,
            memoryMib: isset($row['maxmem']) ? $this->toMib($row['maxmem']) : null,
            diskGib: isset($row['maxdisk']) ? $this->toGib($row['maxdisk']) : null,
            uptimeSeconds: isset($row['uptime']) && is_numeric($row['uptime']) ? (int) $row['uptime'] : null,
            raw: $this->redactor->redact($row),
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function get(string $path, array $parameters, string $operation): mixed
    {
        return $this->dataFrom($this->send('GET', $path, $parameters, $operation), $operation);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function post(string $path, array $parameters, string $operation): mixed
    {
        return $this->dataFrom($this->send('POST', $path, $parameters, $operation), $operation);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function put(string $path, array $parameters, string $operation): mixed
    {
        return $this->dataFrom($this->send('PUT', $path, $parameters, $operation), $operation);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function delete(string $path, array $parameters, string $operation): mixed
    {
        return $this->dataFrom($this->send('DELETE', $path, $parameters, $operation), $operation);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function send(string $method, string $path, array $parameters, string $operation): Response
    {
        try {
            $request = $this->request();

            return match ($method) {
                'GET' => $request->get($path, $parameters),
                'POST' => $request->post($path, $parameters),
                'PUT' => $request->put($path, $parameters),
                // Proxmox reads a DELETE's parameters from the query string;
                // the HTTP client would otherwise put them in a form-encoded
                // body, which the API ignores — and a destroy that silently
                // loses "purge" leaves the machine's id in every backup job
                // and HA group it was ever in.
                'DELETE' => $request->delete($parameters === [] ? $path : $path.'?'.http_build_query($parameters)),
                default => throw ComputeProviderException::requestFailed(self::NAME, $operation, [
                    'provider_message' => sprintf('unsupported HTTP method "%s"', $method),
                ]),
            };
        } catch (ConnectionException $e) {
            /*
             * Caught by type and re-thrown as our own. A connection exception
             * from the client carries the full request in its message, and
             * every request this class makes has the API token in its
             * Authorization header.
             *
             * Flagged indeterminate, which is the important part. This branch
             * is a timeout or a dropped connection: the platform stopped
             * waiting, the cluster did not stop working. Proxmox answers a
             * create in milliseconds and builds for minutes, so a create that
             * timed out has very possibly been accepted. Reported as an
             * ordinary failure it would be indistinguishable from a 400, and a
             * caller retrying on that would build the customer a second
             * machine nobody bills for or deletes.
             */
            throw ComputeProviderException::requestFailed(self::NAME, $operation, [
                'path' => $path,
                'provider_message' => $this->scrub($e->getMessage()),
            ], previous: $e, indeterminate: true);
        } catch (ComputeProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            /*
             * Anything else from the transport is unknown by definition — we
             * do not know whether the request reached the cluster — so it is
             * treated with the same caution as a timeout.
             */
            throw ComputeProviderException::requestFailed(self::NAME, $operation, [
                'path' => $path,
                'provider_message' => $this->scrub($e->getMessage()),
            ], previous: $e, indeterminate: true);
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->connection->baseUrl())
            ->withHeaders(['Authorization' => $this->connection->authorizationHeader()])
            // Explicit rather than left to the client default, so that a
            // future change to that default cannot silently disable
            // certificate verification for every cluster at once.
            ->withOptions(['verify' => $this->connection->verifyTls])
            ->timeout($this->connection->timeoutSeconds)
            ->acceptJson()
            // Proxmox takes form-encoded parameters, not JSON, on every
            // write endpoint.
            ->asForm();
    }

    /**
     * Unwrap Proxmox's {"data": ...} envelope, or translate the failure.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function dataFrom(Response $response, string $operation, array $context = []): mixed
    {
        if ($response->failed()) {
            throw ComputeProviderException::requestFailed(self::NAME, $operation, [
                ...$context,
                'status' => $response->status(),
                // The cluster's own words, with this connection's token
                // removed first. Kept because "storage 'nvme-01' does not
                // exist" and "permission denied" need completely different
                // human responses.
                'provider_message' => $this->errorMessage($response),
            ], indeterminate: self::isIndeterminateStatus($response->status()));
        }

        $decoded = $response->json();

        if (! is_array($decoded) || ! array_key_exists('data', $decoded)) {
            throw ComputeProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                'the body is not a Proxmox API envelope',
                [...$context, 'status' => $response->status()],
            );
        }

        return $decoded['data'];
    }

    /**
     * Proxmox answers a create, a power change or a destroy with a UPID and
     * nothing else. No UPID means the platform has no handle on an operation
     * that is already under way, which is worse than a failure and must not be
     * reported as success.
     */
    private function expectTaskId(mixed $data, string $operation, string $nodeName): string
    {
        if (is_string($data) && str_starts_with($data, 'UPID:')) {
            return $data;
        }

        throw ComputeProviderException::unexpectedResponse(
            self::NAME,
            $operation,
            'the cluster accepted the request but returned no UPID, so the operation cannot be tracked',
            ['node' => $nodeName],
            // The request was accepted. Whatever it started is under way with
            // no handle on it, which is precisely the state a retry turns into
            // two machines.
            indeterminate: true,
        );
    }

    /**
     * Statuses that mean "somebody in the path gave up", not "the cluster
     * refused".
     *
     * A 502, 503 or 504 comes from a reverse proxy in front of Proxmox, or
     * from Proxmox's own front end under load, and says nothing about whether
     * the request reached the API or what it did when it got there. A 4xx, by
     * contrast, is the cluster answering: it read the request and declined it.
     */
    private static function isIndeterminateStatus(int $status): bool
    {
        return in_array($status, [502, 503, 504, 408, 429], true);
    }

    private function synchronousTaskId(string $operation, string $nodeName, string $providerId): string
    {
        return sprintf('%s%s:%s:%s', self::SYNCHRONOUS_TASK_PREFIX, $operation, $nodeName, $providerId);
    }

    /**
     * Whether this failure means "no such machine" rather than "the request
     * went wrong".
     *
     * Proxmox reports a missing machine as a 500 with a message about a
     * missing configuration file, not as a 404, so the status alone cannot
     * answer this.
     */
    private function isMissingResource(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        return $response->serverError()
            && str_contains(strtolower($response->body()), 'does not exist');
    }

    private function errorMessage(Response $response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            $errors = $body['errors'] ?? null;

            if (is_array($errors) && $errors !== []) {
                return $this->scrub(implode('; ', array_map(
                    static fn (int|string $key, mixed $value): string => $key.': '.(is_scalar($value)
                        ? (string) $value
                        : (string) json_encode($value)),
                    array_keys($errors),
                    array_values($errors),
                )));
            }

            if (isset($body['message']) && is_string($body['message'])) {
                return $this->scrub($body['message']);
            }
        }

        // Truncated: an HTML error page from a reverse proxy in front of the
        // cluster is megabytes of no use in a log line.
        return $this->scrub(mb_substr(trim($response->body()), 0, 512));
    }

    /**
     * Remove this connection's credential, then everything else that looks
     * like one.
     *
     * The explicit replacement comes first and is not redundant: the shared
     * redactor recognises credential *shapes*, and a Proxmox token secret is a
     * bare UUID that appears in no pattern anybody could write without knowing
     * this deployment's configuration. Only this object knows the value, so
     * only this object can guarantee it is gone.
     */
    private function scrub(string $message): string
    {
        $withoutToken = str_replace(
            [$this->connection->tokenSecret, $this->connection->authorizationHeader(), $this->connection->tokenId],
            SecretRedactor::PLACEHOLDER,
            $message,
        );

        return $this->redactor->redactString($withoutToken);
    }

    private function toMib(mixed $bytes): int
    {
        return is_numeric($bytes) ? intdiv((int) $bytes, self::BYTES_PER_MIB) : 0;
    }

    private function toGib(mixed $bytes): int
    {
        return is_numeric($bytes) ? intdiv((int) $bytes, self::BYTES_PER_GIB) : 0;
    }

    /**
     * Infer what a pool is made of from what Proxmox says about it.
     *
     * Returns null rather than guessing when neither the type nor the name
     * settles it. A sync that guessed would overwrite the class an operator
     * set — the commercial decision about what the pool is sold as — with an
     * assumption, and machines would be placed on the wrong tier.
     */
    private static function inferStorageClass(string $name, ?string $type): ?StorageClass
    {
        if ($type === 'rbd' || $type === 'cephfs') {
            return StorageClass::Ceph;
        }

        $haystack = strtolower($name);

        return match (true) {
            str_contains($haystack, 'nvme') => StorageClass::Nvme,
            str_contains($haystack, 'ssd') => StorageClass::Ssd,
            str_contains($haystack, 'hdd'), str_contains($haystack, 'sata') => StorageClass::Hdd,
            str_contains($haystack, 'ceph') => StorageClass::Ceph,
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $tags
     */
    private static function formatTags(array $tags): string
    {
        $formatted = [];

        foreach ($tags as $key => $value) {
            // Proxmox rejects a tag containing anything outside this set, and
            // rejects the whole create with it — so a customer reference with
            // a slash in it would fail the build rather than lose a label.
            $formatted[] = preg_replace('/[^A-Za-z0-9_.\-+]/', '-', $key.'-'.$value) ?? '';
        }

        return implode(';', array_filter($formatted));
    }
}
