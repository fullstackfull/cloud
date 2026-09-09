<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\GpuDevice;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssessProduct;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The GPU registry: an operator records a card they can see; the platform
 * counts it as capacity only where it may be used.
 *
 * Recording is not touching, so a card can be recorded on a do_not_touch
 * machine — and it is not capacity there. Raising the classification is a
 * separate act with its own permission, and nothing here performs it.
 */
final class AGpuIsRecordedAndCountedOnlyWhereItMayBeUsedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $pci = '0000:41:00.0'): array
    {
        return [
            'vendor' => 'NVIDIA',
            'model' => 'L40S',
            'vram_mib' => 49152,
            'pci_address' => $pci,
            'passthrough_mode' => 'pci_passthrough',
            'notes' => 'Slot 3.',
        ];
    }

    #[Test]
    public function a_card_is_recorded_audited_listed_and_refused_twice_at_the_same_address(): void
    {
        $server = ManagedServer::factory()->create();

        $created = $this->actingAs($this->operator())->postJson('/api/admin/infrastructure/servers/'.$server->getKey().'/gpus', $this->card());

        $created->assertCreated()
            ->assertJsonPath('data.vendor', 'NVIDIA')
            ->assertJsonPath('data.vram_mib', 49152)
            ->assertJsonPath('data.pci_address', '0000:41:00.0')
            ->assertJsonPath('data.dedicated', true)
            ->assertJsonPath('data.allocation_state', 'available');
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::GpuDeviceRegistered->value]);

        $this->actingAs($this->operator())->postJson('/api/admin/infrastructure/servers/'.$server->getKey().'/gpus', $this->card())
            ->assertConflict()
            ->assertJsonPath('error.details.reason', 'gpu_exists');

        $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/infrastructure/servers/'.$server->getKey().'/gpus')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // The classification is what it was.
        $this->assertSame(SafetyClass::DoNotTouch, $server->fresh()->safety_class);
    }

    #[Test]
    public function a_card_counts_as_capacity_only_on_a_machine_classified_to_allow_configuration(): void
    {
        $untouchable = ManagedServer::factory()->create();
        $configurable = ManagedServer::factory()->classified(SafetyClass::ConfigurationAllowed)->create();

        $this->actingAs($this->operator())->postJson('/api/admin/infrastructure/servers/'.$untouchable->getKey().'/gpus', $this->card())->assertCreated();
        $this->assertSame(0, GpuDevice::query()->capacity()->count());
        $this->assertSame('blocked_hardware', app(AssessProduct::class)->execute(Product::GpuCompute)->blocker?->value);

        $this->actingAs($this->operator())->postJson('/api/admin/infrastructure/servers/'.$configurable->getKey().'/gpus', $this->card())->assertCreated();
        $this->assertSame(1, GpuDevice::query()->capacity()->count());

        // A card the host cannot hand to a guest is recorded and not counted.
        $this->actingAs($this->operator())->postJson('/api/admin/infrastructure/servers/'.$configurable->getKey().'/gpus', [...$this->card('0000:61:00.0'), 'passthrough_mode' => 'none'])->assertCreated();
        $this->assertSame(1, GpuDevice::query()->capacity()->count());

        // With a GPU in the estate the product's blocker moves off hardware —
        // to the compute provider it still lacks.
        $verdict = app(AssessProduct::class)->execute(Product::GpuCompute);
        $this->assertNotSame('blocked_hardware', $verdict->blocker?->value);
    }

    #[Test]
    public function the_request_is_validated_and_recording_needs_the_manage_permission(): void
    {
        $server = ManagedServer::factory()->create();

        $this->actingAs($this->operator())->postJson('/api/admin/infrastructure/servers/'.$server->getKey().'/gpus', [...$this->card(), 'pci_address' => '41:00'])
            ->assertUnprocessable();
        $this->actingAs($this->operator())->postJson('/api/admin/infrastructure/servers/'.$server->getKey().'/gpus', [...$this->card(), 'passthrough_mode' => 'magic'])
            ->assertUnprocessable();
        $this->actingAs($this->operator(Role::Noc))->postJson('/api/admin/infrastructure/servers/'.$server->getKey().'/gpus', $this->card())
            ->assertForbidden();
    }
}
