<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A node the reconcile sweep discovers can be put into service by an operator (F-02).
 *
 * SyncClusterInventory records a newly discovered node in `maintenance`, on
 * purpose: discovery is not authorisation. The other half — a person saying
 * the node is cabled and monitored and may take customers — had no route,
 * command or action. `node.maintenance` was a permission two roles held and
 * nothing asked for, and on an estate an operator built every node stayed in
 * maintenance for good, so no VPS could ever be placed on it.
 */
final class AnOperatorPutsADiscoveredNodeIntoServiceTest extends TestCase
{
    use RefreshDatabase;

    private ComputeNode $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $cluster = ComputeCluster::factory()->create(['status' => 'active']);

        $this->app->singleton(ComputeProviderFactory::class);
        app(ComputeProviderFactory::class)->swap($cluster, new FakeComputeProvider);

        $this->artisan('infrastructure:reconcile')->assertSuccessful();

        $this->node = ComputeNode::query()->where('cluster_id', $cluster->getKey())->firstOrFail();
    }

    #[Test]
    public function a_discovered_node_starts_in_maintenance_and_an_operator_puts_it_into_service(): void
    {
        $this->assertSame(NodeStatus::Maintenance, $this->node->status);
        $this->assertFalse($this->node->isSchedulable());

        $this->actingAs($this->operatorWith(Role::Noc))
            ->putJson($this->uri(), ['status' => 'active', 'reason' => 'Cabled, patched and in monitoring; ticket 4411.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertTrue($this->node->refresh()->isSchedulable());

        $entry = AuditEntry::query()->where('action', AuditAction::ComputeNodeStatusChanged)->sole();

        $this->assertSame('maintenance', $entry->context['from'] ?? null);
        $this->assertSame('active', $entry->context['to'] ?? null);
        $this->assertStringContainsString('4411', (string) ($entry->context['reason'] ?? ''));
    }

    #[Test]
    public function offline_is_what_the_hypervisor_says_and_is_not_set_by_hand(): void
    {
        $this->actingAs($this->operatorWith(Role::Noc))
            ->putJson($this->uri(), ['status' => 'offline', 'reason' => 'Trying.'])
            ->assertStatus(422);

        $this->assertSame(NodeStatus::Maintenance, $this->node->refresh()->status);
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $this->actingAs($this->operatorWith(Role::Noc))
            ->putJson($this->uri(), ['status' => 'active'])
            ->assertStatus(422);

        $this->assertSame(NodeStatus::Maintenance, $this->node->refresh()->status);
    }

    #[Test]
    public function an_operator_without_node_maintenance_is_refused(): void
    {
        $this->actingAs($this->operatorWith(Role::BillingAdmin))
            ->putJson($this->uri(), ['status' => 'active', 'reason' => 'Not mine to say.'])
            ->assertForbidden();

        $this->assertSame(NodeStatus::Maintenance, $this->node->refresh()->status);
    }

    private function uri(): string
    {
        return '/api/admin/infrastructure/nodes/'.$this->node->getKey().'/status';
    }

    private function operatorWith(Role $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
