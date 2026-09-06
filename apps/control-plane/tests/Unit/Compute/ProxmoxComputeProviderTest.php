<?php

declare(strict_types=1);

namespace Tests\Unit\Compute;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Domain\DTOs\CloudInitConfig;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adapter that talks to real infrastructure.
 *
 * Two properties are worth more than all the rest put together and are
 * asserted here against the recorded requests rather than trusted:
 *
 *  - the credential travels in the Authorization header as an API token, and
 *    appears nowhere else — not in a URL, not in a body, not in an exception
 *    message, not in a log. A token in a query string is a token in the
 *    reverse proxy's access log, and a token in an exception message is a
 *    token in the error tracker;
 *
 *  - a mutation returns Proxmox's UPID. Without it the platform has no handle
 *    on an operation that is already under way.
 */
final class ProxmoxComputeProviderTest extends TestCase
{
    private const string TOKEN_ID = 'lynomia@pve!control-plane';

    private const string TOKEN_SECRET = 'b7f3c1de-4a2e-4f0c-9f77-0c1d2e3f4a5b';

    private const string UPID = 'UPID:pve-01:0000A1B2:00C3D4E5:65F0A1B2:qmcreate:101:lynomia@pve!control-plane:';

    #[Test]
    public function every_request_authenticates_with_an_api_token_header(): void
    {
        Http::fake(['*' => Http::response(['data' => self::UPID])]);

        $this->provider()->createVirtualMachine($this->createRequest());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                'PVEAPIToken='.self::TOKEN_ID.'='.self::TOKEN_SECRET,
                $request->header('Authorization')[0] ?? '',
                'The adapter must authenticate with an API token, never a ticket or a password.',
            );

            // The credential is in the header and nowhere else. A token in the
            // URL is a token in every proxy log between here and the cluster.
            $this->assertStringNotContainsString(self::TOKEN_SECRET, $request->url());
            $this->assertStringNotContainsString(self::TOKEN_SECRET, $request->body());

            return true;
        });
    }

    #[Test]
    public function no_login_endpoint_is_ever_called(): void
    {
        Http::fake(['*' => Http::response(['data' => self::UPID])]);

        $provider = $this->provider();
        $provider->createVirtualMachine($this->createRequest());
        $provider->startVm('pve-01', '101');

        // Ticket authentication would show up here as a POST to
        // /access/ticket; its absence is the assertion.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/access/ticket'));
    }

    #[Test]
    public function the_upid_is_returned_as_the_operations_remote_job_id(): void
    {
        Http::fake(['*' => Http::response(['data' => self::UPID])]);

        $operation = $this->provider()->createVirtualMachine($this->createRequest());

        $this->assertSame(self::UPID, $operation->taskId);
        $this->assertSame('101', $operation->providerId);
        $this->assertTrue($operation->isInFlight());
    }

    #[Test]
    public function every_power_operation_returns_its_own_upid(): void
    {
        Http::fake(['*' => Http::response(['data' => self::UPID])]);

        $provider = $this->provider();

        foreach (['startVm', 'stopVm', 'shutdownVm', 'rebootVm', 'resetVm'] as $method) {
            $this->assertSame(self::UPID, $provider->{$method}('pve-01', '101')->taskId);
        }

        foreach (['start', 'stop', 'shutdown', 'reboot', 'reset'] as $action) {
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
                && str_ends_with($request->url(), '/nodes/pve-01/qemu/101/status/'.$action));
        }
    }

    #[Test]
    public function an_accepted_request_that_returns_no_upid_is_treated_as_a_failure(): void
    {
        // A 200 with no task handle is worse than an error: the cluster may
        // well be building the machine, and the platform would have no way to
        // find out.
        Http::fake(['*' => Http::response(['data' => null])]);

        $this->expectException(ComputeProviderException::class);

        $this->provider()->createVirtualMachine($this->createRequest());
    }

    #[Test]
    public function a_proxmox_error_becomes_a_domain_exception_with_no_token_in_it(): void
    {
        /*
         * A cluster genuinely does echo the request back in some error bodies.
         * This one is written to be as hostile as possible: the token and the
         * whole Authorization header appear in the message the adapter is
         * about to translate.
         */
        Http::fake(['*' => Http::response([
            'data' => null,
            'errors' => [
                'storage' => "storage 'nvme-01' does not exist (auth: PVEAPIToken=".self::TOKEN_ID.'='.self::TOKEN_SECRET.')',
            ],
        ], 400)]);

        try {
            $this->provider()->createVirtualMachine($this->createRequest());

            $this->fail('A 400 from the cluster was not translated into a domain exception.');
        } catch (ComputeProviderException $e) {
            $this->assertSame('compute.provider_request_failed', $e->errorCode());
            $this->assertSame(502, $e->httpStatus());

            $this->assertStringNotContainsString(self::TOKEN_SECRET, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN_ID, $e->getMessage());

            $context = $e->context();
            $this->assertSame(400, $context['status']);

            // The cluster's own words survive, because "storage does not
            // exist" and "permission denied" need different human responses —
            // but the credential does not.
            $message = (string) $context['provider_message'];
            $this->assertStringContainsString("storage 'nvme-01' does not exist", $message);
            $this->assertStringNotContainsString(self::TOKEN_SECRET, $message);
            $this->assertStringNotContainsString(self::TOKEN_ID, $message);
            $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $message);

            // Nothing anywhere in the exception, including a serialised copy
            // of it, carries the credential.
            $this->assertStringNotContainsString(self::TOKEN_SECRET, json_encode($context, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function a_ticket_in_an_error_message_is_redacted_too(): void
    {
        // Proxmox tickets look like PVE:user@realm:HEXTIME::signature and turn
        // up in messages from the API when something else in the cluster
        // authenticated with one.
        Http::fake(['*' => Http::response([
            'data' => null,
            'message' => 'permission denied - invalid PVE:root@pam:65F0A1B2::aGVsbG93b3JsZHNpZ25hdHVyZQ==',
        ], 401)]);

        try {
            $this->provider()->startVm('pve-01', '101');

            $this->fail('A 401 was not translated.');
        } catch (ComputeProviderException $e) {
            $message = (string) $e->context()['provider_message'];

            $this->assertStringNotContainsString('aGVsbG93b3JsZHNpZ25hdHVyZQ==', $message);
            $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $message);
        }
    }

    #[Test]
    public function a_connection_failure_is_translated_without_leaking_the_request(): void
    {
        Http::fake(fn (): never => throw new ConnectionException(
            'cURL error 7: Failed to connect (Authorization: PVEAPIToken='.self::TOKEN_ID.'='.self::TOKEN_SECRET.')',
        ));

        try {
            $this->provider()->listNodes();

            $this->fail('A connection failure escaped untranslated.');
        } catch (ComputeProviderException $e) {
            $message = (string) $e->context()['provider_message'];

            $this->assertStringNotContainsString(self::TOKEN_SECRET, $message);
            $this->assertStringNotContainsString(self::TOKEN_SECRET, $e->getMessage());
        }
    }

    #[Test]
    public function cloud_init_keys_are_url_encoded_the_way_the_api_requires(): void
    {
        Http::fake(['*' => Http::response(['data' => self::UPID])]);

        $this->provider()->createVirtualMachine($this->createRequest(new CloudInitConfig(
            user: 'lynomia',
            sshKeys: ['ssh-ed25519 AAAAC3Nza first@key', 'ssh-rsa AAAAB3Nza second@key'],
            ipConfig: 'ip=192.0.2.10/24,gw=192.0.2.1',
            nameservers: ['1.1.1.1', '9.9.9.9'],
            searchDomain: 'lynomia.cloud',
        )));

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            $this->assertSame('lynomia', $data['ciuser']);
            $this->assertSame('ip=192.0.2.10/24,gw=192.0.2.1', $data['ipconfig0']);
            $this->assertSame('1.1.1.1 9.9.9.9', $data['nameserver']);
            $this->assertSame('lynomia.cloud', $data['searchdomain']);

            /*
             * Both keys survive, encoded. An unencoded block is accepted by
             * the API and silently truncated at the first newline, which on a
             * two-key account is a server the customer cannot log into.
             */
            $this->assertSame(
                rawurlencode("ssh-ed25519 AAAAC3Nza first@key\nssh-rsa AAAAB3Nza second@key"),
                $data['sshkeys'],
            );
            $this->assertStringContainsString('%0A', $data['sshkeys']);

            // A cloud-init drive has to exist for any of it to be delivered.
            $this->assertSame('local-nvme:cloudinit', $data['ide2']);

            return true;
        });
    }

    #[Test]
    public function a_machine_the_cluster_does_not_have_is_reported_as_absent(): void
    {
        // Proxmox reports a missing machine as a 500 about a missing config
        // file rather than as a 404, so the status alone cannot answer this.
        Http::fake(['*' => Http::response([
            'data' => null,
            'message' => "Configuration file 'nodes/pve-01/qemu-server/999.conf' does not exist",
        ], 500)]);

        $this->assertNull($this->provider()->getVm('pve-01', '999'));
    }

    #[Test]
    public function a_machines_state_is_read_back_in_the_platforms_own_units(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            'name' => 'web-01',
            'status' => 'running',
            'cpus' => 4,
            'maxmem' => 8589934592,
            'maxdisk' => 85899345920,
            'uptime' => 3600,
        ]])]);

        $machine = $this->provider()->getVm('pve-01', '101');

        $this->assertSame(PowerState::Running, $machine?->powerState);
        $this->assertSame(4, $machine?->vcpu);
        $this->assertSame(8192, $machine?->memoryMib);
        $this->assertSame(80, $machine?->diskGib);
        $this->assertSame(3600, $machine?->uptimeSeconds);
    }

    #[Test]
    public function a_task_that_has_not_finished_is_never_reported_as_finished(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'running', 'starttime' => 1710000000]])]);

        $task = $this->provider()->getTask('pve-01', self::UPID);

        $this->assertSame(RemoteTaskStatus::Running, $task->status);
        $this->assertFalse($task->isFinished());
        $this->assertSame(1710000000, $task->startedAt);
    }

    #[Test]
    public function a_stopped_task_without_an_exit_status_is_still_running(): void
    {
        // The pair is what is meaningful; treating a stopped-but-unfinished
        // task as complete marks a machine ready before its disk is written.
        Http::fake(['*' => Http::response(['data' => ['status' => 'stopped']])]);

        $this->assertSame(RemoteTaskStatus::Running, $this->provider()->getTask('pve-01', self::UPID)->status);
    }

    #[Test]
    public function a_task_reports_success_only_on_an_ok_exit_status(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']])]);

        $this->assertSame(RemoteTaskStatus::Succeeded, $this->provider()->getTask('pve-01', self::UPID)->status);
    }

    #[Test]
    public function a_task_that_ended_badly_keeps_the_clusters_own_words(): void
    {
        Http::fake(['*' => Http::response(['data' => [
            'status' => 'stopped',
            'exitstatus' => 'unable to parse directory volume name',
        ]])]);

        $failed = $this->provider()->getTask('pve-01', self::UPID);

        $this->assertSame(RemoteTaskStatus::Failed, $failed->status);
        // Kept verbatim rather than collapsed into the status: "OK" and this
        // are both terminal, and only one is worth waking somebody for.
        $this->assertSame('unable to parse directory volume name', $failed->exitStatus);
    }

    #[Test]
    public function the_node_inventory_is_read_in_bytes_and_returned_in_the_platforms_units(): void
    {
        $this->fakeCluster();

        $nodes = $this->provider()->listNodes();

        $this->assertCount(2, $nodes);

        $this->assertSame('pve-01', $nodes[0]->name);
        $this->assertTrue($nodes[0]->online);
        $this->assertSame(32, $nodes[0]->cpuCores);
        $this->assertSame(262144, $nodes[0]->memoryTotalMib);
        $this->assertSame(65536, $nodes[0]->memoryUsedMib);
        $this->assertSame(0.12, $nodes[0]->cpuUsage);

        $this->assertSame(StorageClass::Nvme, $nodes[0]->storages[0]->storageClass);
        $this->assertSame(4096, $nodes[0]->storages[0]->totalGib);
        $this->assertSame(3072, $nodes[0]->storages[0]->availableGib);

        // An offline node is not interrogated: it would answer every storage
        // request with a timeout, and a sync of a cluster with one dead node
        // would take as long as the timeout times the fleet.
        $this->assertFalse($nodes[1]->online);
        $this->assertSame([], $nodes[1]->storages);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/nodes/pve-02/storage'));
    }

    #[Test]
    public function reading_the_inventory_issues_only_get_requests(): void
    {
        $this->fakeCluster();

        $this->provider()->listNodes();

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method(), 'Discovery must never mutate anything at the cluster.');
        }
    }

    #[Test]
    public function a_disk_is_grown_through_the_resize_endpoint_and_never_the_config_endpoint(): void
    {
        Http::fake(['*' => Http::response(['data' => self::UPID])]);

        $this->provider()->resizeVm('pve-01', '101', new ResizeVmRequest(vcpu: 8, memoryMib: 16384, diskGib: 40));

        // Cores and memory go through the config endpoint...
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/nodes/pve-01/qemu/101/config')
            && $request->data()['cores'] === 8
            && $request->data()['memory'] === 16384);

        // ...and the disk does not, because rewriting scsi0 in the config
        // detaches the volume the customer's data is on. The "+" makes it a
        // growth rather than an absolute size.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/nodes/pve-01/qemu/101/resize')
            && $request->data()['size'] === '+40G');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/config')
            && array_key_exists('scsi0', $request->data()));
    }

    #[Test]
    public function destroying_a_machine_purges_everything_that_refers_to_its_id(): void
    {
        Http::fake(['*' => Http::response(['data' => self::UPID])]);

        $operation = $this->provider()->destroyVm('pve-01', '101');

        $this->assertSame(self::UPID, $operation->taskId);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('DELETE', $request->method());
            // Without the purge the id keeps appearing in backup jobs and HA
            // groups, and the next customer issued that id inherits both.
            $this->assertStringContainsString('purge=1', urldecode($request->url()));

            return true;
        });
    }

    #[Test]
    public function tls_verification_is_on_unless_a_cluster_explicitly_turns_it_off(): void
    {
        $default = new ProxmoxConnection('https://pve.test:8006', self::TOKEN_ID, self::TOKEN_SECRET);
        $this->assertTrue($default->verifyTls);

        $fromConfig = ProxmoxConnection::fromCredentials(
            'https://pve.test:8006',
            ['token_id' => self::TOKEN_ID, 'token_secret' => self::TOKEN_SECRET],
        );
        $this->assertTrue($fromConfig->verifyTls);

        // Only a cluster that asks for it gets it off, and only for itself.
        $lab = ProxmoxConnection::fromCredentials(
            'https://lab.test:8006',
            ['token_id' => self::TOKEN_ID, 'token_secret' => self::TOKEN_SECRET],
            verifyTls: false,
        );
        $this->assertFalse($lab->verifyTls);
    }

    #[Test]
    public function a_global_config_switch_cannot_waive_verification_for_a_cluster_that_asks_for_it(): void
    {
        /*
         * The regression this exists for: verify_tls was ANDed with the config
         * key, so one PROXMOX_VERIFY_TLS=false — set by somebody working
         * around a lab certificate — switched certificate verification off for
         * every production cluster at once. An unverified connection hands the
         * API token, and with it every customer's machine on the cluster, to
         * whoever answers the TCP connection.
         */
        config()->set('compute.proxmox.verify_tls', false);

        $production = ProxmoxConnection::fromCredentials(
            'https://pve.test:8006',
            ['token_id' => self::TOKEN_ID, 'token_secret' => self::TOKEN_SECRET],
            verifyTls: true,
        );

        $this->assertTrue(
            $production->verifyTls,
            'A global environment variable disabled certificate verification for a cluster that demanded it.',
        );

        // The row is still the one thing that can waive it, for itself only.
        $lab = ProxmoxConnection::fromCredentials(
            'https://lab.test:8006',
            ['token_id' => self::TOKEN_ID, 'token_secret' => self::TOKEN_SECRET],
            verifyTls: false,
        );

        $this->assertFalse($lab->verifyTls);
    }

    #[Test]
    public function a_timeout_is_reported_as_an_unknown_outcome_rather_than_a_failure(): void
    {
        /*
         * The most expensive mistake this adapter can make. A timeout means
         * the platform stopped waiting; Proxmox answers a create in
         * milliseconds and builds for minutes, so the machine may be booting
         * right now. A caller that cannot tell this from a 400 either retries
         * every failure — and gives the customer a second machine nobody bills
         * for or deletes — or fails every failure, and leaks the first one.
         */
        Http::fake(fn (): never => throw new ConnectionException(
            'cURL error 28: Operation timed out after 30001 milliseconds',
        ));

        try {
            $this->provider()->createVirtualMachine($this->createRequest());

            $this->fail('A timed-out create was not reported at all.');
        } catch (ComputeProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
            $this->assertTrue($e->context()['indeterminate']);
        }
    }

    #[Test]
    public function a_refusal_the_cluster_spoke_out_loud_is_not_treated_as_unknown(): void
    {
        // The cluster read the request and declined it, so nothing was built
        // and a caller is free to release the capacity it was holding.
        Http::fake(['*' => Http::response(['data' => null, 'errors' => ['storage' => 'no such storage']], 400)]);

        try {
            $this->provider()->createVirtualMachine($this->createRequest());

            $this->fail('A 400 was not translated.');
        } catch (ComputeProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
            $this->assertFalse($e->context()['indeterminate']);
        }
    }

    #[Test]
    public function a_gateway_giving_up_in_front_of_the_cluster_is_an_unknown_outcome(): void
    {
        // 504 comes from the reverse proxy, not from Proxmox, and says nothing
        // about whether the request reached the API or what it did there.
        Http::fake(['*' => Http::response('<html>Gateway Time-out</html>', 504)]);

        try {
            $this->provider()->destroyVm('pve-01', '101');

            $this->fail('A 504 was not translated.');
        } catch (ComputeProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function an_accepted_mutation_with_no_handle_on_it_is_an_unknown_outcome(): void
    {
        // The cluster took the create and gave back no UPID: whatever it
        // started is under way with nothing to track it by, which is precisely
        // the state a retry turns into two machines.
        Http::fake(['*' => Http::response(['data' => null])]);

        try {
            $this->provider()->createVirtualMachine($this->createRequest());

            $this->fail('A create with no UPID was reported as success.');
        } catch (ComputeProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function reading_a_machine_that_the_cluster_refuses_is_not_confused_with_absence(): void
    {
        // A 403 is the cluster saying "you may not ask", not "there is no such
        // machine". Reporting it as absence would let reconciliation conclude
        // a customer's running server had been deleted.
        Http::fake(['*' => Http::response(['data' => null, 'message' => 'permission denied'], 403)]);

        $this->expectException(ComputeProviderException::class);

        $this->provider()->getVm('pve-01', '101');
    }

    #[Test]
    public function a_connection_cannot_be_built_from_a_username_and_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // No "!" means this is a username, not a token id — which is what a
        // password-based configuration would look like.
        new ProxmoxConnection('https://pve.test:8006', 'root@pam', 'hunter2');
    }

    private function provider(): ProxmoxComputeProvider
    {
        return new ProxmoxComputeProvider(
            new ProxmoxConnection('https://pve.test:8006', self::TOKEN_ID, self::TOKEN_SECRET),
            new SecretRedactor,
        );
    }

    private function createRequest(?CloudInitConfig $cloudInit = null): CreateVmRequest
    {
        return new CreateVmRequest(
            nodeName: 'pve-01',
            vmId: 101,
            hostname: 'web-01',
            vcpu: 4,
            memoryMib: 8192,
            diskGib: 80,
            storageName: 'local-nvme',
            templateReference: 'local:import/debian-13.qcow2',
            cloudInit: $cloudInit ?? new CloudInitConfig(sshKeys: ['ssh-ed25519 AAAA test@lynomia']),
        );
    }

    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_ends_with($request->url(), '/api2/json/nodes') => Http::response(['data' => [
                    [
                        'node' => 'pve-01',
                        'status' => 'online',
                        'maxcpu' => 32,
                        'maxmem' => 274877906944,
                        'mem' => 68719476736,
                        'cpu' => 0.12,
                        'pveversion' => 'pve-manager/8.2.4',
                    ],
                    ['node' => 'pve-02', 'status' => 'offline', 'maxcpu' => 32, 'maxmem' => 274877906944],
                ]]),
                str_contains($request->url(), '/nodes/pve-01/storage') => Http::response(['data' => [
                    [
                        'storage' => 'local-nvme',
                        'type' => 'lvmthin',
                        'shared' => 0,
                        'total' => 4398046511104,
                        'avail' => 3298534883328,
                        'active' => 1,
                    ],
                ]]),
                default => Http::response(['data' => []]),
            };
        });
    }
}
