<?php

declare(strict_types=1);

namespace Tests\Unit\Compute;

use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The controlled hypervisor keeps the occupied-id contract.
 *
 * {@see ACreateAtAnOccupiedIdContractTestCase} for the contract and the probe
 * it answers. The simulator's extra case is the one a node-by-node store
 * would get wrong: a VMID is the cluster's, so a machine at 12345 on pve-02
 * occupies 12345 on pve-01 as well.
 */
final class TheComputeSimulatorRefusesAnOccupiedIdTest extends ACreateAtAnOccupiedIdContractTestCase
{
    private ?FakeComputeProvider $provider = null;

    protected function provider(): ComputeProvider
    {
        return $this->provider ??= new FakeComputeProvider;
    }

    protected function occupy(string $nodeName, int $vmId, string $name, int $vcpu): void
    {
        $this->provider()->createVirtualMachine($this->request($nodeName, $vmId, $name, $vcpu));
    }

    #[Test]
    public function an_id_held_on_another_node_of_the_cluster_is_occupied_too(): void
    {
        $this->occupy('pve-02', 12345, 'strangers-box', 2);

        try {
            $this->provider()->createVirtualMachine($this->request('pve-01', 12345, 'our-box', 1));

            $this->fail('A create at an id held elsewhere in the cluster was accepted.');
        } catch (ComputeProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
        }

        $this->assertNull($this->provider()->getVm('pve-01', '12345'), 'A second machine was built under the id.');
        $this->assertSame('strangers-box', $this->provider()->getVm('pve-02', '12345')?->name);
    }

    #[Test]
    public function an_id_is_free_again_once_its_machine_is_destroyed(): void
    {
        // The refusal is about a machine being there, not about the id ever
        // having been used: a rebuild after a destroy is ordinary.
        $this->occupy('pve-01', 12345, 'strangers-box', 2);
        $this->provider()->destroyVm('pve-01', '12345');

        $this->provider()->createVirtualMachine($this->request('pve-01', 12345, 'our-box', 1));

        $this->assertSame('our-box', $this->provider()->getVm('pve-01', '12345')?->name);
    }
}
