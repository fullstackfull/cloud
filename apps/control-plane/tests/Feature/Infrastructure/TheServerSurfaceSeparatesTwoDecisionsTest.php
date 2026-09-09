<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reclassifying a machine and clearing it for a wipe are two permissions.
 *
 * The infrastructure-admin role holds the first and not the second, on purpose:
 * an operator who reclassifies machines as part of ordinary work should not be
 * able to authorise a wipe without somebody deliberately granting it. A
 * permission held by default is one nobody notices being used.
 */
final class TheServerSurfaceSeparatesTwoDecisionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The roles and their grants are seeded rather than fabricated, so this
        // test asserts against the permissions the platform actually ships —
        // which is the point of a test about who may do what.
        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    #[Test]
    public function a_registered_server_arrives_untouchable_whatever_the_request_says(): void
    {
        $response = $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/servers', [
                'name' => 'pve-http-1',
                'environment' => 'staging',
                'management_address' => 'fake://connected',
                // Both ignored: the request has no such fields, and a field
                // that does not exist is a rule nobody can forget to check.
                'safety_class' => 'reimage_allowed',
                'allow_reimage' => true,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.safety.classification', SafetyClass::DoNotTouch->value);
        $response->assertJsonPath('data.safety.allow_reimage', false);
        $response->assertJsonPath('data.safety.permits.read', false);
    }

    #[Test]
    public function an_infrastructure_admin_may_reclassify_and_may_not_make_a_machine_wipeable(): void
    {
        $operator = $this->operator(Role::InfrastructureAdmin);
        $server = ManagedServer::factory()->classified(SafetyClass::ConfigurationAllowed)->create(['name' => 'pve-rbac']);

        // Lowering and raising within the safe range: allowed.
        $this->actingAs($operator)
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/classify", [
                'safety_class' => 'discovery_only',
                'reason' => 'taking it out of service while we investigate',
            ])
            ->assertOk()
            ->assertJsonPath('data.safety.classification', 'discovery_only');

        // The destructive rung: refused at the route, before the controller.
        $this->actingAs($operator)
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/clear-for-reimage", [
                'confirm_name' => 'pve-rbac',
                'reason' => 'rebuild',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_support_agent_cannot_see_the_machines_at_all(): void
    {
        $server = ManagedServer::factory()->create();

        $this->actingAs($this->operator(Role::Support))
            ->getJson('/api/admin/infrastructure/servers')
            ->assertForbidden();

        $this->actingAs($this->operator(Role::Support))
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/classify", [
                'safety_class' => 'discovery_only',
                'reason' => 'curiosity',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function the_noc_may_read_the_machines_and_change_nothing(): void
    {
        $server = ManagedServer::factory()->create();

        $this->actingAs($this->operator(Role::Noc))
            ->getJson('/api/admin/infrastructure/servers')
            ->assertOk();

        $this->actingAs($this->operator(Role::Noc))
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/classify", [
                'safety_class' => 'discovery_only',
                'reason' => 'during an incident',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function climbing_two_rungs_at_once_is_refused_as_a_conflict_not_a_validation_error(): void
    {
        $server = ManagedServer::factory()->create(['name' => 'pve-jump']);

        // 409, not 422: the request was well-formed, and the machine is not in
        // a state where that transition is allowed. The distinction tells an
        // operator whether to fix their request or go and change something.
        $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/classify", [
                'safety_class' => 'reimage_allowed',
                'reason' => 'rebuilding it',
                'confirm_name' => 'pve-jump',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'refused');
    }

    #[Test]
    public function testing_a_connection_to_an_untouchable_machine_is_refused(): void
    {
        $server = ManagedServer::factory()->create();

        // 409 rather than 403: the operator's permissions were fine, and the
        // machine is not one anybody has agreed we may open a socket to. Those
        // are different problems with different fixes, and collapsing them
        // sends somebody to the RBAC screen when they should be talking to
        // whoever owns the hardware.
        $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/connection-test", ['driver' => 'fake'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'safety_refused')
            ->assertJsonPath('error.details.classification', 'do_not_touch')
            ->assertJsonPath('error.details.attempted', 'read')
            // And it says which classification would have permitted it, so the
            // next step is on the screen rather than in somebody's head.
            ->assertJsonPath('error.details.would_permit', 'discovery_only');
    }

    #[Test]
    public function a_connection_test_reports_the_blocker_and_the_next_action(): void
    {
        $server = ManagedServer::factory()
            ->classified(SafetyClass::DiscoveryOnly)
            ->reachableAs('network-failed')
            ->create();

        $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/connection-test", ['driver' => 'fake'])
            ->assertOk()
            ->assertJsonPath('data.result', 'network_failed')
            ->assertJsonPath('data.reached', false)
            ->assertJsonPath('data.blocker', 'blocked_network')
            // The screen never decides what a blocker means; the enum knows,
            // one place, and the API and the UI both read it from there.
            ->assertJsonPath('data.next_action', 'estate.guidance.network');
    }

    #[Test]
    public function the_response_never_carries_a_path_into_the_secret_store(): void
    {
        $server = ManagedServer::factory()->create();

        $body = $this->actingAs($this->operator())
            ->getJson("/api/admin/infrastructure/servers/{$server->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('backend_reference', $body);
    }
}
