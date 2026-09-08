<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Providers;

use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ReinstallVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteConsoleEndpoint;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteNodeState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteStorageState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteTaskState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\VmOperation;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\Services\FakeComputeProviderGuard;

/**
 * A hypervisor that builds nothing and reaches no network.
 *
 * Its behaviour is a pure function of the request, which is the property that
 * makes the failure paths testable: a hostname carrying a marker selects an
 * outcome, so a test asks for "web-01-provider-fail" to exercise the refusal
 * branch and "web-01-task-fail" to exercise the one where the cluster accepts
 * the job and then fails it minutes later. No fixtures, no HTTP stub, and no
 * random.
 *
 * Three decisions are worth stating because they look like over-engineering
 * until the alternative bites:
 *
 *  - the task id is a real UPID with the start time encoded in it, exactly as
 *    Proxmox emits. getTask() reads that timestamp back, so the configured
 *    delay produces a task that is genuinely still running for a while — which
 *    is the only way the polling path in provisioning is ever executed by a
 *    test. A fake that reported every task complete on the first poll would
 *    leave the whole asynchronous branch of the engine uncovered;
 *
 *  - the outcome is encoded into the UPID rather than held in a property, so
 *    getTask() still answers correctly in a worker that never saw the create;
 *
 *  - it refuses to exist in production. The fake reports machines as created
 *    without creating them, so in production it would mark services active and
 *    send credentials for servers that do not exist.
 */
final class FakeComputeProvider implements ComputeProvider
{
    public const string NAME = 'fake';

    /** A hostname carrying this is refused outright, as a cluster with no capacity would. */
    /** The same lock value the real adapter writes, so tests assert on one string. */
    public const string SUSPENSION_LOCK = ProxmoxComputeProvider::SUSPENSION_LOCK;

    public const string PROVIDER_FAILURE_MARKER = 'provider-fail';

    /** A hostname carrying this is accepted, and the task fails later. */
    public const string TASK_FAILURE_MARKER = 'task-fail';

    /**
     * A hostname carrying this times out: the call fails with the outcome at
     * the cluster unknown, which is the state the platform must never resolve
     * by retrying. Nothing is recorded as created, deliberately — that is what
     * makes the marker useful, because the caller cannot tell and must behave
     * correctly anyway.
     */
    public const string TIMEOUT_MARKER = 'timeout';

    /**
     * A machine carrying this cannot be destroyed conclusively.
     *
     * Its own word rather than a reuse of the timeout marker, because a
     * hostname is checked for every marker it contains: a name that meant
     * "time out on destroy" would also mean "time out on create", and the
     * machine could never be built in the first place.
     */
    public const string UNDESTROYABLE_MARKER = 'undestroyable';

    /** Appended to a UPID's id segment to mark a task that will report failure. */
    private const string FAILED_TASK_SUFFIX = '-failed';

    private const string TASK_USER = 'fake@pve!lynomia';

    /**
     * Machines this instance has created, keyed by node and provider id.
     *
     * @var array<string, array<string, RemoteVmState>>
     */
    private array $machines = [];

    /**
     * Ids this instance has destroyed, so that absence can be asserted.
     *
     * @var array<string, true>
     */
    private array $destroyed = [];

    private int $taskDelaySeconds;

    /**
     * A file the fleet is kept in, or null to keep it in memory.
     *
     * @see config('compute.fake.state_path')
     */
    private ?string $statePath;

    public function __construct()
    {
        // Constructed, not resolved, is the moment worth guarding: a container
        // binding overridden at runtime or a cluster row whose driver column
        // says "fake" never passes through config, but neither can avoid this
        // constructor.
        FakeComputeProviderGuard::assertNotProduction(self::NAME);

        // Clamped at zero rather than trusted: a negative delay would put a
        // task's completion in the past and make the "still running" state
        // unreachable, quietly disabling the behaviour this exists to model.
        $this->taskDelaySeconds = max(0, (int) config('compute.fake.task_delay_seconds', 0));

        $path = config('compute.fake.state_path');

        $this->statePath = is_string($path) && $path !== '' ? $path : null;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function createVirtualMachine(CreateVmRequest $request): VmOperation
    {
        $this->readSharedFleet();

        if (self::hostnameCarries($request->hostname, self::PROVIDER_FAILURE_MARKER)) {
            throw ComputeProviderException::requestFailed(self::NAME, 'create_vm', [
                'node' => $request->nodeName,
                'vmid' => $request->vmId,
                'provider_message' => 'the fake provider refused this hostname by design',
            ]);
        }

        if (self::hostnameCarries($request->hostname, self::TIMEOUT_MARKER)) {
            throw ComputeProviderException::requestFailed(self::NAME, 'create_vm', [
                'node' => $request->nodeName,
                'vmid' => $request->vmId,
                'provider_message' => 'the fake provider timed out on this hostname by design',
            ], indeterminate: true);
        }

        $providerId = (string) $request->vmId;

        $this->machines[$request->nodeName][$providerId] = new RemoteVmState(
            providerId: $providerId,
            nodeName: $request->nodeName,
            name: $request->hostname,
            // Whether it starts is a property of the request, so a test that
            // asks for a machine to be left off gets one that is off.
            powerState: $request->startAfterCreate ? PowerState::Running : PowerState::Stopped,
            vcpu: $request->vcpu,
            memoryMib: $request->memoryMib,
            diskGib: $request->diskGib,
            uptimeSeconds: $request->startAfterCreate ? 0 : null,
            raw: ['fake' => true, 'storage' => $request->storageName],
        );

        unset($this->destroyed[$this->tombstoneKey($request->nodeName, $providerId)]);

        $this->writeSharedFleet();

        return new VmOperation(
            taskId: $this->upid(
                $request->nodeName,
                'qmcreate',
                $providerId,
                self::hostnameCarries($request->hostname, self::TASK_FAILURE_MARKER),
            ),
            nodeName: $request->nodeName,
            providerId: $providerId,
            operation: 'create_vm',
            metadata: ['fake' => true, 'hostname' => $request->hostname],
        );
    }

    public function startVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'start', PowerState::Running);
    }

    public function stopVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'stop', PowerState::Stopped);
    }

    public function shutdownVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'shutdown', PowerState::Stopped);
    }

    public function rebootVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'reboot', PowerState::Running);
    }

    public function resetVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->changePowerState($nodeName, $providerId, 'reset', PowerState::Running);
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

        $machine = $this->machine($nodeName, $providerId);

        if ($machine === null) {
            throw $this->noSuchMachine($nodeName, $providerId, 'resize_vm');
        }

        $this->machines[$nodeName][$providerId] = $machine->withShape(
            vcpu: $request->vcpu ?? $machine->vcpu,
            memoryMib: $request->memoryMib ?? $machine->memoryMib,
            // A disk request is a growth, never an absolute size, which is the
            // same rule the real adapter enforces.
            diskGib: $request->diskGib === null ? $machine->diskGib : ($machine->diskGib ?? 0) + $request->diskGib,
        );

        $this->writeSharedFleet();

        return new VmOperation(
            taskId: $this->upid($nodeName, 'qmconfig', $providerId, false),
            nodeName: $nodeName,
            providerId: $providerId,
            operation: 'resize_vm',
        );
    }

    /**
     * Replaces the machine's image while keeping the machine.
     *
     * The fake models the two properties the real sequence has to have and
     * that a test could otherwise not observe: the machine keeps its provider
     * id — no new entry appears and the old one is not removed — and a locked
     * machine refuses, exactly as Proxmox does. Without the second, a
     * suspended customer could rebuild their way out of a suspension and the
     * suite would prove they could not.
     *
     * The image is recorded in `raw` so a test can assert that the disk was
     * actually replaced rather than that a call was made.
     */
    public function reinstallVm(string $nodeName, string $providerId, ReinstallVmRequest $request): VmOperation
    {
        $machine = $this->machine($nodeName, $providerId);

        if ($machine === null) {
            throw $this->noSuchMachine($nodeName, $providerId, 'reinstall_vm');
        }

        if ($machine->isLockedAtProvider()) {
            throw ComputeProviderException::requestFailed(self::NAME, 'reinstall_vm', [
                'node' => $nodeName,
                'vmid' => $providerId,
                'provider_message' => sprintf('VM is locked (%s)', $machine->lock),
            ]);
        }

        if (self::hostnameCarries($request->hostname, self::PROVIDER_FAILURE_MARKER)) {
            /*
             * Refused after the machine was found, which is what makes this
             * marker useful: the caller has to decide what to do about a
             * machine whose disk may be half replaced.
             */
            throw ComputeProviderException::requestFailed(self::NAME, 'reinstall_vm', [
                'node' => $nodeName,
                'vmid' => $providerId,
                'provider_message' => 'the fake provider refused this reinstall by design',
            ]);
        }

        if (self::hostnameCarries($request->hostname, self::TIMEOUT_MARKER)) {
            throw ComputeProviderException::requestFailed(self::NAME, 'reinstall_vm', [
                'node' => $nodeName,
                'vmid' => $providerId,
                'provider_message' => 'the fake provider timed out on this reinstall by design',
            ], indeterminate: true);
        }

        $this->machines[$nodeName][$providerId] = new RemoteVmState(
            providerId: $machine->providerId,
            nodeName: $machine->nodeName,
            // The hostname is rewritten because cloud-init writes it into the
            // new guest; everything else about the machine's identity is the
            // machine's, not the reinstall's.
            name: $request->hostname,
            powerState: $request->startAfterInstall ? PowerState::Running : PowerState::Stopped,
            vcpu: $machine->vcpu,
            memoryMib: $machine->memoryMib,
            diskGib: $machine->diskGib,
            uptimeSeconds: $request->startAfterInstall ? 0 : null,
            lock: $machine->lock,
            startsOnBoot: true,
            raw: [
                ...$machine->raw,
                'fake' => true,
                'installed_template' => $request->templateReference,
                'storage' => $request->storageName,
            ],
        );

        $this->writeSharedFleet();

        return new VmOperation(
            taskId: $this->upid(
                $nodeName,
                'qmreinstall',
                $providerId,
                self::hostnameCarries($request->hostname, self::TASK_FAILURE_MARKER),
            ),
            nodeName: $nodeName,
            providerId: $providerId,
            operation: 'reinstall_vm',
            metadata: ['fake' => true, 'template_reference' => $request->templateReference],
        );
    }

    public function destroyVm(string $nodeName, string $providerId, bool $purge = true): VmOperation
    {
        $machine = $this->machine($nodeName, $providerId);

        if ($machine === null) {
            throw $this->noSuchMachine($nodeName, $providerId, 'destroy_vm');
        }

        /*
         * A machine whose name carries the timeout marker cannot be destroyed
         * conclusively: the call fails with the outcome unknown, which is the
         * one state a termination must never resolve by releasing the
         * machine's address. Keyed on the name the machine was created with,
         * because a destroy takes no hostname of its own.
         */
        if (self::hostnameCarries($machine->name, self::UNDESTROYABLE_MARKER)) {
            throw ComputeProviderException::requestFailed(self::NAME, 'destroy_vm', [
                'node' => $nodeName,
                'vmid' => $providerId,
                'provider_message' => 'the fake provider timed out on this destroy by design',
            ], indeterminate: true);
        }

        unset($this->machines[$nodeName][$providerId]);

        /*
         * The tombstone is written now even though the task reports running
         * for the configured delay. Modelling the removal as delayed too would
         * force every test that asserts a machine is gone to sleep, and the
         * behaviour worth exercising — polling a task that has not finished —
         * is already covered by the task id.
         */
        $this->destroyed[$this->tombstoneKey($nodeName, $providerId)] = true;

        $this->writeSharedFleet();

        return new VmOperation(
            taskId: $this->upid($nodeName, 'qmdestroy', $providerId, false),
            nodeName: $nodeName,
            providerId: $providerId,
            operation: 'destroy_vm',
            metadata: ['purge' => $purge],
        );
    }

    public function getVm(string $nodeName, string $providerId): ?RemoteVmState
    {
        return $this->machine($nodeName, $providerId);
    }

    public function suspendVm(string $nodeName, string $providerId, SuspensionPolicy $policy): VmOperation
    {
        $machine = $this->machine($nodeName, $providerId);

        if ($machine === null) {
            throw $this->noSuchMachine($nodeName, $providerId, 'suspend_vm');
        }

        if ($policy === SuspensionPolicy::RecordOnly) {
            // Nothing at the provider, which is the whole meaning of the value.
            return new VmOperation(
                taskId: $this->upid($nodeName, 'qmsuspend', $providerId, false),
                nodeName: $nodeName,
                providerId: $providerId,
                operation: 'suspend_vm',
                status: RemoteTaskStatus::Succeeded,
            );
        }

        $this->machines[$nodeName][$providerId] = $machine
            ->withPowerState($policy->powersOff() ? PowerState::Stopped : $machine->powerState)
            ->withSuspension(
                lock: $policy->locksAtProvider() ? self::SUSPENSION_LOCK : $machine->lock,
                // Cleared whatever the policy: a suspended machine that came
                // back on the next node reboot would be suspended only until
                // the next maintenance window.
                startsOnBoot: false,
            );

        $this->writeSharedFleet();

        return new VmOperation(
            taskId: $this->upid($nodeName, 'qmsuspend', $providerId, false),
            nodeName: $nodeName,
            providerId: $providerId,
            operation: 'suspend_vm',
            status: RemoteTaskStatus::Succeeded,
            metadata: ['policy' => $policy->value],
        );
    }

    public function liftSuspension(string $nodeName, string $providerId): VmOperation
    {
        $machine = $this->machine($nodeName, $providerId);

        if ($machine === null) {
            throw $this->noSuchMachine($nodeName, $providerId, 'lift_suspension');
        }

        /*
         * Only the platform's own lock is cleared. A machine locked by a
         * backup is left alone, because clearing that would have the platform
         * quietly interfering with an operation it did not start.
         */
        $lock = $machine->lock === self::SUSPENSION_LOCK ? null : $machine->lock;

        /*
         * Deliberately not started. Returning a customer's server to a running
         * state is the platform's decision, made in the reactivation flow
         * where it can be verified.
         */
        $this->machines[$nodeName][$providerId] = $machine->withSuspension($lock, startsOnBoot: true);

        $this->writeSharedFleet();

        return new VmOperation(
            taskId: $this->upid($nodeName, 'qmunsuspend', $providerId, false),
            nodeName: $nodeName,
            providerId: $providerId,
            operation: 'lift_suspension',
            status: RemoteTaskStatus::Succeeded,
        );
    }

    /**
     * A console endpoint pointing at whatever the deployment has told it to.
     *
     * The address comes from configuration rather than being invented, because
     * the point of the fake here is to let the console gateway be proved
     * end to end against an upstream a test controls — a real socket, a real
     * handshake, real frames — without a hypervisor. A fake that returned a
     * plausible-looking Proxmox URL would make the gateway's tests pass
     * against something that does not exist.
     */
    public function consoleEndpoint(string $nodeName, string $providerId): RemoteConsoleEndpoint
    {
        if ($this->machine($nodeName, $providerId) === null) {
            throw $this->noSuchMachine($nodeName, $providerId, 'console_endpoint');
        }

        $host = config('compute.fake.console_host');
        $port = config('compute.fake.console_port');

        if (! is_string($host) || $host === '' || ! is_numeric($port)) {
            throw ComputeProviderException::requestFailed(self::NAME, 'console_endpoint', [
                'node' => $nodeName,
                'vmid' => $providerId,
                'provider_message' => 'no fake console upstream is configured',
            ]);
        }

        return new RemoteConsoleEndpoint(
            host: $host,
            port: (int) $port,
            path: sprintf('/console/%s/%s', rawurlencode($nodeName), rawurlencode($providerId)),
            tls: false,
            headers: ['X-Fake-Console' => $providerId],
            verifyTls: false,
        );
    }

    public function listVms(string $nodeName): array
    {
        $this->readSharedFleet();

        $machines = $this->machines[$nodeName] ?? [];

        // Sorted so that a test asserting on the second machine is asserting
        // on the same machine every run.
        ksort($machines);

        return array_values($machines);
    }

    public function getTask(string $nodeName, string $taskId): RemoteTaskState
    {
        $parts = explode(':', $taskId);

        // UPID:node:pid:pstart:starttime:type:id:user: — eight fields and a
        // trailing empty one.
        if (count($parts) < 8 || $parts[0] !== 'UPID') {
            throw ComputeProviderException::unexpectedResponse(
                self::NAME,
                'get_task',
                'the task id is not a UPID',
                ['node' => $nodeName],
            );
        }

        $startedAt = (int) hexdec($parts[4]);
        $willFail = str_ends_with($parts[6], self::FAILED_TASK_SUFFIX);

        if (time() < $startedAt + $this->taskDelaySeconds) {
            return new RemoteTaskState($taskId, $nodeName, RemoteTaskStatus::Running, null, $startedAt);
        }

        return new RemoteTaskState(
            taskId: $taskId,
            nodeName: $nodeName,
            status: $willFail ? RemoteTaskStatus::Failed : RemoteTaskStatus::Succeeded,
            exitStatus: $willFail ? 'the fake provider failed this task by design' : 'OK',
            startedAt: $startedAt,
        );
    }

    public function listNodes(): array
    {
        /** @var list<array<string, mixed>> $configured */
        $configured = config('compute.fake.nodes', self::defaultNodes());

        $nodes = [];

        foreach ($configured as $node) {
            $name = (string) ($node['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $nodes[] = new RemoteNodeState(
                name: $name,
                online: (bool) ($node['online'] ?? true),
                cpuCores: (int) ($node['cpu_cores'] ?? 0),
                memoryTotalMib: (int) ($node['memory_total_mib'] ?? 0),
                memoryUsedMib: isset($node['memory_used_mib']) ? (int) $node['memory_used_mib'] : null,
                cpuUsage: isset($node['cpu_usage']) ? (float) $node['cpu_usage'] : null,
                storageTotalGib: isset($node['storage_total_gib']) ? (int) $node['storage_total_gib'] : null,
                storageAvailableGib: isset($node['storage_available_gib']) ? (int) $node['storage_available_gib'] : null,
                storages: self::storagesFrom(is_array($node['storages'] ?? null) ? $node['storages'] : []),
                capabilities: ['fake' => true],
            );
        }

        return $nodes;
    }

    /**
     * The hostname suffix that makes this provider refuse, exposed so that
     * tests state their intent instead of embedding a magic string.
     */
    public static function failingHostname(string $base, string $marker = self::PROVIDER_FAILURE_MARKER): string
    {
        return $base.'-'.$marker;
    }

    private function changePowerState(
        string $nodeName,
        string $providerId,
        string $action,
        PowerState $resulting,
    ): VmOperation {
        $machine = $this->machine($nodeName, $providerId);

        if ($machine === null) {
            throw $this->noSuchMachine($nodeName, $providerId, $action.'_vm');
        }

        /*
         * A locked machine refuses every power operation, exactly as Proxmox
         * does. This is the behaviour that makes suspension enforcement rather
         * than bookkeeping, so the fake has to model it — a fake that let a
         * suspended machine start would let the test suite prove a property
         * the real hypervisor does not have.
         */
        if ($machine->isLockedAtProvider()) {
            throw ComputeProviderException::requestFailed(self::NAME, $action.'_vm', [
                'node' => $nodeName,
                'vmid' => $providerId,
                'provider_message' => sprintf('VM is locked (%s)', $machine->lock),
            ]);
        }

        $this->machines[$nodeName][$providerId] = $machine->withPowerState($resulting);

        $this->writeSharedFleet();

        return new VmOperation(
            taskId: $this->upid($nodeName, 'qm'.$action, $providerId, false),
            nodeName: $nodeName,
            providerId: $providerId,
            operation: $action.'_vm',
        );
    }

    private function machine(string $nodeName, string $providerId): ?RemoteVmState
    {
        $this->readSharedFleet();

        return $this->machines[$nodeName][$providerId] ?? null;
    }

    /**
     * Reads the fleet another process may have changed.
     *
     * Does nothing at all unless a state path is configured, which is the
     * normal case: a fake that went to disk on every call in every test would
     * be slower and would let one test see another's machines. When a path is
     * set, the file is the truth and this instance's memory is a cache of it
     * that lives for exactly one call.
     */
    private function readSharedFleet(): void
    {
        if ($this->statePath === null || ! is_file($this->statePath)) {
            return;
        }

        $contents = @file_get_contents($this->statePath);

        if ($contents === false || $contents === '') {
            return;
        }

        /** @var array{machines?: array<string, array<string, RemoteVmState>>, destroyed?: array<string, true>}|false $state */
        $state = @unserialize($contents, ['allowed_classes' => true]);

        if (! is_array($state)) {
            return;
        }

        $this->machines = $state['machines'] ?? [];
        $this->destroyed = $state['destroyed'] ?? [];
    }

    /**
     * Publishes the fleet for other processes.
     *
     * Written to a neighbouring file and renamed, so a worker reading while
     * this writes sees either the old fleet or the new one and never half of
     * either.
     */
    private function writeSharedFleet(): void
    {
        if ($this->statePath === null) {
            return;
        }

        $directory = dirname($this->statePath);

        if (! is_dir($directory)) {
            @mkdir($directory, 0o755, recursive: true);
        }

        $temporary = $this->statePath.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, serialize([
            'machines' => $this->machines,
            'destroyed' => $this->destroyed,
        ])) === false) {
            return;
        }

        @rename($temporary, $this->statePath);
    }

    private function noSuchMachine(string $nodeName, string $providerId, string $operation): ComputeProviderException
    {
        return ComputeProviderException::requestFailed(self::NAME, $operation, [
            'node' => $nodeName,
            'vmid' => $providerId,
            'provider_message' => sprintf('no machine %s exists on node %s', $providerId, $nodeName),
        ]);
    }

    /**
     * A UPID in Proxmox's own format, with the start time in hex where Proxmox
     * puts it and the outcome encoded in the id segment.
     */
    private function upid(string $nodeName, string $type, string $id, bool $willFail): string
    {
        return sprintf(
            'UPID:%s:%08X:%08X:%08X:%s:%s:%s:',
            $nodeName,
            // Derived from the request rather than random, so that the same
            // request produces the same task id twice — which is what makes a
            // retried create recognisable as the same job.
            crc32($nodeName.$type.$id) & 0xFFFFFF,
            crc32($nodeName) & 0xFFFFFF,
            time(),
            $type,
            $id.($willFail ? self::FAILED_TASK_SUFFIX : ''),
            self::TASK_USER,
        );
    }

    private function tombstoneKey(string $nodeName, string $providerId): string
    {
        return $nodeName.'/'.$providerId;
    }

    private static function hostnameCarries(string $hostname, string $marker): bool
    {
        return str_contains(strtolower($hostname), $marker);
    }

    /**
     * @param  list<array<string, mixed>>  $configured
     * @return list<RemoteStorageState>
     */
    private static function storagesFrom(array $configured): array
    {
        $storages = [];

        foreach ($configured as $storage) {
            $name = (string) ($storage['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $storages[] = new RemoteStorageState(
                name: $name,
                shared: (bool) ($storage['shared'] ?? false),
                storageClass: is_string($storage['class'] ?? null)
                    ? StorageClass::tryFrom($storage['class'])
                    : null,
                totalGib: isset($storage['total_gib']) ? (int) $storage['total_gib'] : null,
                availableGib: isset($storage['available_gib']) ? (int) $storage['available_gib'] : null,
                active: (bool) ($storage['active'] ?? true),
            );
        }

        return $storages;
    }

    /**
     * Three nodes of the same shape, so that a fleet built from the fake
     * exercises spreading rather than being decided by capacity differences
     * nobody chose.
     *
     * @return list<array<string, mixed>>
     */
    private static function defaultNodes(): array
    {
        $nodes = [];

        foreach (['pve-01', 'pve-02', 'pve-03'] as $index => $name) {
            $nodes[] = [
                'name' => $name,
                'online' => true,
                'cpu_cores' => 32,
                'memory_total_mib' => 262144,
                // Slightly different reported usage per node, because a fake
                // where every node is identical hides an ordering bug behind a
                // tie.
                'memory_used_mib' => 16384 + ($index * 4096),
                'cpu_usage' => 0.1 + ($index / 100),
                'storages' => [
                    ['name' => 'local-nvme', 'class' => StorageClass::Nvme->value, 'total_gib' => 4096, 'available_gib' => 3584],
                    ['name' => 'ceph-pool', 'class' => StorageClass::Ceph->value, 'shared' => true, 'total_gib' => 65536, 'available_gib' => 40960],
                ],
            ];
        }

        return $nodes;
    }
}
