<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Onboarding a cluster has to include its images, through the API.
 *
 * ===========================================================================
 * THE GAP AN ONBOARDING DRY RUN FOUND
 * ===========================================================================
 *
 * Gap 8 made a VPS build name an OS image or be refused. Everything in that
 * chain reads `vm_templates`, and nothing could write it: no endpoint, no
 * discovery, no command — a factory and the browser seeder. So a real cluster
 * onboarded through the Control Center would have had no images at all, every
 * VPS order would have been refused with `vps.create_image_unavailable`, and
 * the ways out would have been raw SQL or a code change.
 *
 * The rule that makes that a blocker rather than a rough edge: onboarding real
 * infrastructure this platform already models must require zero code changes.
 * A product that cannot be delivered without one is not code-complete.
 *
 * ===========================================================================
 * WHAT THESE TESTS HOLD
 * ===========================================================================
 *
 * The write path, its authorisation, its refusals, and the two properties that
 * make it usable by a person rather than only by a script: recording the same
 * slug twice is a correction rather than a conflict, and withdrawing an image
 * is not a one-way door.
 *
 * Also the seam that matters downstream — a recorded image with no provider
 * reference is not installable, because the hypervisor has nothing to clone.
 * Placement already refuses that row; this asserts the operator can see why.
 */
final class RecordingTheImagesAClusterMayInstallTest extends TestCase
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
    private function payload(ComputeCluster $cluster, array $overrides = []): array
    {
        return [
            'cluster_id' => $cluster->getKey(),
            'slug' => 'debian-12',
            'name' => ['en' => 'Debian 12', 'ar' => 'دبيان 12'],
            'os_family' => OsFamily::Debian->value,
            'os_version' => '12',
            'architecture' => CpuArchitecture::X86_64->value,
            'provider_reference' => '9000',
            'cloud_init' => true,
            'guest_agent' => true,
            'requires_licence' => false,
            'licence_note' => null,
            ...$overrides,
        ];
    }

    #[Test]
    public function an_operator_records_an_image_and_the_platform_can_then_build_from_it(): void
    {
        $cluster = ComputeCluster::factory()->create();

        $response = $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/templates', $this->payload($cluster));

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'debian-12');
        $response->assertJsonPath('data.name.ar', 'دبيان 12');
        $response->assertJsonPath('data.installable', true);

        // The property the whole chain depends on: placement's own scope finds
        // it. Asserted through the scope rather than through a column, because
        // the scope is what the build path uses.
        $this->assertSame(
            1,
            VmTemplate::query()->installable()->where('cluster_id', $cluster->getKey())->count(),
        );

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::VmTemplateRecorded->value]);
    }

    #[Test]
    public function recording_the_same_slug_again_corrects_the_row_rather_than_refusing(): void
    {
        /*
         * An image rebuilt under a new reference, or a point release installed
         * in place. `(cluster_id, slug)` is unique, so an action that answered
         * 409 would leave "fix the reference" with no route through the API —
         * which is the same hole this endpoint exists to close.
         */
        $cluster = ComputeCluster::factory()->create();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson('/api/admin/infrastructure/templates', $this->payload($cluster))
            ->assertCreated();

        $again = $this->actingAs($operator)->postJson(
            '/api/admin/infrastructure/templates',
            $this->payload($cluster, ['provider_reference' => '9001', 'os_version' => '12.7']),
        );

        $again->assertOk();
        $again->assertJsonPath('data.provider_reference', '9001');
        $again->assertJsonPath('data.os_version', '12.7');

        $this->assertSame(1, VmTemplate::query()->where('slug', 'debian-12')->count());
    }

    #[Test]
    public function the_same_slug_on_a_second_cluster_is_a_different_image(): void
    {
        // Two clusters each staging their own copy of Debian is the normal
        // case, and the uniqueness scope is the pair rather than the slug.
        $operator = $this->operator();
        $first = ComputeCluster::factory()->create();
        $second = ComputeCluster::factory()->create();

        $this->actingAs($operator)->postJson('/api/admin/infrastructure/templates', $this->payload($first))->assertCreated();
        $this->actingAs($operator)->postJson('/api/admin/infrastructure/templates', $this->payload($second))->assertCreated();

        $this->assertSame(2, VmTemplate::query()->where('slug', 'debian-12')->count());
    }

    #[Test]
    public function an_image_that_is_not_staged_yet_is_recorded_and_not_offered(): void
    {
        $cluster = ComputeCluster::factory()->create();

        $response = $this->actingAs($this->operator())->postJson(
            '/api/admin/infrastructure/templates',
            $this->payload($cluster, ['provider_reference' => null]),
        );

        $response->assertCreated();
        $response->assertJsonPath('data.installable', false);

        // A commercial intention, not something a machine can be built from.
        $this->assertSame(0, VmTemplate::query()->installable()->count());
    }

    #[Test]
    public function withdrawing_an_image_stops_it_being_offered_without_forgetting_it(): void
    {
        $template = VmTemplate::factory()->create(['is_active' => true, 'provider_reference' => '9000']);

        $response = $this->actingAs($this->operator())
            ->deleteJson('/api/admin/infrastructure/templates/'.$template->getKey());

        $response->assertOk();
        $response->assertJsonPath('data.is_active', false);
        $response->assertJsonPath('data.installable', false);

        // Still there: machines built from it point at this row, and "which
        // image is this server running" must stay answerable.
        $this->assertDatabaseHas('vm_templates', ['id' => $template->getKey()]);
        $this->assertSame(0, VmTemplate::query()->installable()->count());
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::VmTemplateWithdrawn->value]);
    }

    #[Test]
    public function recording_a_withdrawn_image_again_offers_it_once_more(): void
    {
        /*
         * Not a separate endpoint. An operator who deactivated something by
         * mistake needs one way back, and "record it again" is the way they
         * already know.
         */
        $cluster = ComputeCluster::factory()->create();
        $operator = $this->operator();

        $template = VmTemplate::factory()->create([
            'cluster_id' => $cluster->getKey(),
            'slug' => 'debian-12',
            'is_active' => false,
        ]);

        $this->actingAs($operator)
            ->postJson('/api/admin/infrastructure/templates', $this->payload($cluster))
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.id', (string) $template->getKey());
    }

    #[Test]
    public function the_index_says_how_many_images_a_vps_could_actually_be_built_from(): void
    {
        $cluster = ComputeCluster::factory()->create();

        VmTemplate::factory()->create(['cluster_id' => $cluster->getKey(), 'slug' => 'a', 'is_active' => true, 'provider_reference' => '9000']);
        VmTemplate::factory()->create(['cluster_id' => $cluster->getKey(), 'slug' => 'b', 'is_active' => false, 'provider_reference' => '9001']);
        VmTemplate::factory()->create(['cluster_id' => $cluster->getKey(), 'slug' => 'c', 'is_active' => true, 'provider_reference' => null]);

        $response = $this->actingAs($this->operator(Role::Noc))
            ->getJson('/api/admin/infrastructure/templates?cluster='.$cluster->getKey());

        $response->assertOk();
        // Withdrawn rows are listed, because "why is this not offered?" is
        // answered by the row that says it was withdrawn.
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.installable', 1);

        $response = $this->actingAs($this->operator(Role::Noc))
            ->getJson('/api/admin/infrastructure/templates?active=1');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function bodiesTheApiRefuses(): iterable
    {
        yield 'a slug that is not a logical identifier' => [['slug' => 'Debian 12'], 'slug'];
        yield 'a slug with an underscore' => [['slug' => 'debian_12'], 'slug'];
        yield 'an unknown operating system' => [['os_family' => 'plan9'], 'os_family'];
        yield 'an unknown architecture' => [['architecture' => 'riscv64'], 'architecture'];
        yield 'one language only' => [['name' => ['en' => 'Debian 12']], 'name.ar'];
        yield 'a provider reference with a space' => [['provider_reference' => '90 00'], 'provider_reference'];
        yield 'a provider reference with a newline' => [['provider_reference' => "9000\nrm -rf /"], 'provider_reference'];
    }

    #[Test]
    #[DataProvider('bodiesTheApiRefuses')]
    public function the_api_refuses_a_body_it_cannot_store_truthfully(array $overrides, string $field): void
    {
        $cluster = ComputeCluster::factory()->create();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/templates', $this->payload($cluster, $overrides))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

        $this->assertSame(0, VmTemplate::query()->count());
    }

    #[Test]
    public function a_cluster_that_does_not_exist_is_refused_before_anything_is_written(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/templates', [
                ...$this->payload(ComputeCluster::factory()->create()),
                'cluster_id' => '01jq8m2v9k3d7f5h1n0p2r4s6t',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['cluster_id']]]]);

        $this->assertSame(0, VmTemplate::query()->count());
    }

    #[Test]
    public function reading_the_catalogue_is_not_permission_to_change_it(): void
    {
        /*
         * An image is what every VPS on the cluster is built from, so the
         * write is behind `infrastructure.manage` while the read is behind
         * `infrastructure.view`. A support role that can see the catalogue
         * must not be able to point every future build at a different image.
         */
        $cluster = ComputeCluster::factory()->create();
        $noc = $this->operator(Role::Noc);

        $this->actingAs($noc)->getJson('/api/admin/infrastructure/templates')->assertOk();

        $this->actingAs($noc)
            ->postJson('/api/admin/infrastructure/templates', $this->payload($cluster))
            ->assertForbidden();

        $template = VmTemplate::factory()->create();

        $this->actingAs($noc)
            ->deleteJson('/api/admin/infrastructure/templates/'.$template->getKey())
            ->assertForbidden();
    }

    #[Test]
    public function a_customer_cannot_reach_the_catalogue_at_all(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/infrastructure/templates')
            ->assertForbidden();
    }
}
