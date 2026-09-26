<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Dedicated\Domain\Exceptions\InstallProfileNotRenderableException;
use Lynomia\Modules\Dedicated\Domain\Services\InstallProfileRenderer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The recipe a Dedicated build installs from can be written by an operator (F-02).
 *
 * ProvisionDedicatedHandler does `findOrFail` on an OsInstallProfile, and until
 * this route existed nothing but a factory wrote one: no route, command,
 * seeder or migration. That is F-02's own shape — a writer that needs a parent
 * nothing can create — one link further down the Dedicated build.
 *
 * Every row is written through the route and read back through the renderer
 * the build uses, because "the row exists" is not the claim; "a build could
 * install from it" is.
 */
final class AnOperatorRecordsAnOsInstallProfileTest extends TestCase
{
    use RefreshDatabase;

    private const string TEMPLATE = "#cloud-config\nautoinstall:\n  version: 1\n  identity:\n    hostname: {{ hostname }}\n"
        ."  network:\n    ethernets:\n      primary:\n        addresses: [{{ ipv4_address }}/{{ ipv4_prefix_length }}]\n"
        ."        gateway4: {{ ipv4_gateway }}\n  timezone: {{ timezone }}\n";

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::InfrastructureAdmin->value]);
    }

    #[Test]
    public function an_operator_records_a_profile_a_build_can_render(): void
    {
        $response = $this->record($this->operator, $this->profile())->assertCreated();

        $this->assertSame('ubuntu-2404-standard', $response->json('data.slug'));
        $this->assertTrue($response->json('data.is_active'));

        $profile = OsInstallProfile::query()->where('slug', 'ubuntu-2404-standard')->sole();

        // The values the build supplies, and nothing else: the profile renders.
        $rendered = app(InstallProfileRenderer::class)->render($profile, [
            'hostname' => 'srv-1',
            'ipv4_address' => '203.0.113.10',
            'ipv4_prefix_length' => 24,
            'ipv4_gateway' => '203.0.113.1',
        ]);

        $this->assertStringContainsString('hostname: srv-1', $rendered['template']);
        $this->assertStringContainsString('timezone: Asia/Kuwait', $rendered['template']);
    }

    #[Test]
    public function the_record_is_audited_with_a_digest_of_the_answer_file(): void
    {
        $this->record($this->operator, $this->profile())->assertCreated();

        $entry = AuditEntry::query()->where('action', AuditAction::OsInstallProfileRecorded)->sole();

        $this->assertSame('ubuntu-2404-standard', $entry->context['slug'] ?? null);
        // Of the text as stored. The framework trims request strings before
        // anything reads them, so the trailing newline sent is not kept.
        $stored = OsInstallProfile::query()->sole()->template;

        $this->assertSame(trim(self::TEMPLATE), $stored);
        $this->assertSame(hash('sha256', $stored), $entry->context['template_sha256'] ?? null);
        $this->assertSame((string) $this->operator->getKey(), (string) ($entry->context['operator'] ?? ''));
    }

    #[Test]
    public function a_template_asking_for_a_value_nothing_supplies_is_refused(): void
    {
        /*
         * A build passes the hostname and the IPv4 address, prefix and
         * gateway; the profile's own defaults supply the rest. A placeholder
         * neither covers is a profile every build would refuse at render time,
         * after a machine had been reserved for it.
         */
        $this->record($this->operator, $this->profile([
            'template' => self::TEMPLATE."  password: {{ root_password_hash }}\n",
        ]))->assertStatus(422);

        $this->assertSame(0, OsInstallProfile::query()->count());
    }

    #[Test]
    public function a_slug_already_recorded_is_refused_rather_than_rewritten(): void
    {
        // An answer file decides how a customer's disks are partitioned; a
        // change is a new, attributable profile, not an edit under an old name.
        $this->record($this->operator, $this->profile())->assertCreated();
        $this->record($this->operator, $this->profile(['os_version' => '26.04']))->assertStatus(422);

        $this->assertSame('24.04', OsInstallProfile::query()->sole()->os_version);
    }

    #[Test]
    public function defaults_must_be_flat_scalars(): void
    {
        $this->record($this->operator, $this->profile(['defaults' => ['timezone' => ['Asia/Kuwait']]]))
            ->assertStatus(422);

        $this->assertSame(0, OsInstallProfile::query()->count());
    }

    #[Test]
    public function an_operator_without_dedicated_manage_is_refused_and_nothing_is_written(): void
    {
        $noc = User::factory()->create();
        $noc->syncRoles([Role::Noc->value]);

        $this->record($noc, $this->profile())->assertForbidden();

        $this->assertSame(0, OsInstallProfile::query()->count());
    }

    #[Test]
    public function a_withdrawn_profile_is_kept_and_no_longer_renders(): void
    {
        $id = (string) $this->record($this->operator, $this->profile())->assertCreated()->json('data.id');

        $this->actingAs($this->operator)
            ->deleteJson('/api/admin/infrastructure/os-install-profiles/'.$id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue(
            AuditEntry::query()->where('action', AuditAction::OsInstallProfileWithdrawn)->exists(),
        );

        $this->actingAs($this->operator)
            ->getJson('/api/admin/infrastructure/os-install-profiles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.is_active', false);

        $this->expectException(InstallProfileNotRenderableException::class);

        app(InstallProfileRenderer::class)->render(OsInstallProfile::query()->findOrFail($id), [
            'hostname' => 'srv-1',
            'ipv4_address' => '203.0.113.10',
            'ipv4_prefix_length' => 24,
            'ipv4_gateway' => '203.0.113.1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profile(array $overrides = []): array
    {
        return [
            'slug' => 'ubuntu-2404-standard',
            'name' => ['en' => 'Ubuntu 24.04 LTS', 'ar' => 'أوبنتو 24.04'],
            'os_family' => 'ubuntu',
            'os_version' => '24.04',
            'installer' => 'autoinstall',
            'template' => self::TEMPLATE,
            'defaults' => ['timezone' => 'Asia/Kuwait'],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function record(User $operator, array $body): TestResponse
    {
        return $this->actingAs($operator)->postJson('/api/admin/infrastructure/os-install-profiles', $body);
    }
}
