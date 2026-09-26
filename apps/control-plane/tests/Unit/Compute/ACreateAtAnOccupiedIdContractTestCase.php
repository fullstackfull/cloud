<?php

declare(strict_types=1);

namespace Tests\Unit\Compute;

use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CloudInitConfig;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a compute provider does with a create at an id that already holds a
 * machine, run against the simulator and the Proxmox adapter alike.
 *
 * The re-audit's probe: create 12345 as 'strangers-box' (2 vCPU), then create
 * 12345 as 'our-box' (1 vCPU). The simulator ACCEPTED the second create and
 * `getVm` then answered name=our-box, vcpu=1 — the stranger's machine was
 * replaced in place. `CreateVpsHandler::vmIdFor()` derives the id from the
 * idempotency key, and its own docblock puts some two of 100 machines at one
 * id about 5% of the time, so the case the simulator waved through is one the
 * platform meets at scale; F-15's test had to count creates at the double's
 * door because the fleet could not show a second one.
 *
 * The contract, as the platform needs it: the create is REFUSED, the refusal
 * is DETERMINATE (the cluster answered, and nothing was built), and the
 * machine already at the id is untouched. Both arms state what they rest on:
 *
 *  - the simulator refuses because Proxmox keeps one VMID namespace for the
 *    whole cluster (a VMID names one guest config, wherever it lives);
 *  - the adapter arm rests on an ASSUMED wire answer. What a real cluster
 *    answers for an occupied VMID — its status and its words — has not been
 *    established in this repository (the re-audit's "could not establish",
 *    band D). The arm pins only the adapter's half: a refusal outside the
 *    statuses it reads as "no answer" is surfaced as a determinate refusal
 *    and never as an operation.
 */
abstract class ACreateAtAnOccupiedIdContractTestCase extends TestCase
{
    abstract protected function provider(): ComputeProvider;

    /**
     * Put a machine at the id before the platform asks for it — somebody
     * else's, as far as the platform can tell.
     */
    abstract protected function occupy(string $nodeName, int $vmId, string $name, int $vcpu): void;

    protected function request(string $nodeName, int $vmId, string $hostname, int $vcpu): CreateVmRequest
    {
        return new CreateVmRequest(
            nodeName: $nodeName,
            vmId: $vmId,
            hostname: $hostname,
            vcpu: $vcpu,
            memoryMib: 2048,
            diskGib: 20,
            storageName: 'local-nvme',
            cloudInit: new CloudInitConfig(sshKeys: ['ssh-ed25519 AAAA test@lynomia']),
        );
    }

    #[Test]
    public function a_create_at_an_occupied_id_is_refused_and_the_machine_there_is_untouched(): void
    {
        $this->occupy('pve-01', 12345, 'strangers-box', 2);

        try {
            $this->provider()->createVirtualMachine($this->request('pve-01', 12345, 'our-box', 1));

            $this->fail('A second create at an occupied id was accepted.');
        } catch (ComputeProviderException $e) {
            // The cluster answered, so the outcome is known: nothing was
            // built. An indeterminate refusal would send the platform looking
            // for a machine of its own that does not exist.
            $this->assertFalse($e->isIndeterminate(), 'An occupied id was reported as an unknown outcome.');
        }

        $there = $this->provider()->getVm('pve-01', '12345');

        $this->assertNotNull($there, 'The machine at the id disappeared.');
        $this->assertSame('strangers-box', $there->name, 'The machine at the id was replaced.');
        $this->assertSame(2, $there->vcpu, 'The machine at the id was reshaped.');
    }
}
