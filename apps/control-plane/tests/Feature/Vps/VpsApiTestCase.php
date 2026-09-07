<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Tests\TestCase;

/**
 * Shared scaffolding for the VPS endpoints.
 *
 * Two customers with two logins exist in almost every test here on purpose:
 * the interesting question about a server API is not whether it can show you
 * your own machine, it is whether it can be talked into rebooting somebody
 * else's.
 */
abstract class VpsApiTestCase extends TestCase
{
    use RefreshDatabase;

    private ?ComputeNode $node = null;

    /**
     * A customer account with an accepted membership, and the user who holds it.
     *
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(CustomerRole $role = CustomerRole::Owner): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        return [$customer, $this->memberOf($customer, $role)];
    }

    protected function memberOf(Customer $customer, CustomerRole $role = CustomerRole::Owner, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            // An invitation that was never accepted grants nothing, so every
            // membership these tests rely on is explicitly accepted.
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * A machine that has actually been built: an active service, a node, and a
     * provider id the hypervisor has confirmed.
     */
    protected function machineFor(
        Customer $customer,
        ServiceStatus $status = ServiceStatus::Active,
        PowerState $powerState = PowerState::Running,
        ?string $hostname = null,
    ): VirtualMachine {
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => $status,
        ]);

        $machine = VirtualMachine::factory()
            ->onNode($this->node())
            ->forService($service)
            ->create(array_filter([
                'power_state' => $powerState,
                'hostname' => $hostname,
            ], static fn (mixed $value): bool => $value !== null));

        return $machine->fresh() ?? $machine;
    }

    /**
     * A machine whose row exists but which the hypervisor has never confirmed
     * — the state a create leaves behind before the provider answers.
     */
    protected function unprovisionedMachineFor(Customer $customer): VirtualMachine
    {
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        return VirtualMachine::factory()->forService($service)->create([
            'provider_id' => null,
            'node_id' => null,
            'cluster_id' => null,
        ]);
    }

    /**
     * One node on one fake cluster, reused across a test so that every machine
     * lands somewhere real without each helper building a fleet.
     */
    protected function node(): ComputeNode
    {
        return $this->node ??= ComputeNode::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->id,
        ]);
    }
}
