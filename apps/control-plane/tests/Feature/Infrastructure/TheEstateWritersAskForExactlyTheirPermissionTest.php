<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StaffHoldingExactly;
use Tests\TestCase;

/**
 * Each route round three added to the estate asks for one permission, and
 * that one only.
 *
 * Two operators per route: one holding every permission in the enum except
 * the intended one, who must be refused, and one holding the intended one
 * alone, who must get past the gate. Swapping the route to any other
 * permission — a weaker `infrastructure.view`, a neighbouring
 * `infrastructure.manage` — reddens the first; adding a second requirement
 * reddens the second.
 */
final class TheEstateWritersAskForExactlyTheirPermissionTest extends TestCase
{
    use RefreshDatabase;
    use StaffHoldingExactly;

    /**
     * @return array<string, array{string, string, Permission}>
     */
    public static function routes(): array
    {
        return [
            'list install profiles' => ['GET', '/api/admin/infrastructure/os-install-profiles', Permission::InfrastructureView],
            'record an install profile' => ['POST', '/api/admin/infrastructure/os-install-profiles', Permission::DedicatedManage],
            'withdraw an install profile' => ['DELETE', '/api/admin/infrastructure/os-install-profiles/{profile}', Permission::DedicatedManage],
            'put a node into service' => ['PUT', '/api/admin/infrastructure/nodes/{node}/status', Permission::NodeMaintenance],
        ];
    }

    #[Test]
    #[DataProvider('routes')]
    public function everything_but_the_intended_permission_is_refused(string $method, string $uri, Permission $intended): void
    {
        $this->seed(RolePermissionSeeder::class);

        // A staff login (StaffHoldingExactly), so the refusal is the
        // permission's and not the /api/admin staff gate's.
        $user = $this->staffHoldingExactly(array_values(
            array_filter(Permission::cases(), static fn (Permission $permission): bool => $permission !== $intended),
        ));

        $this->actingAs($user)->json($method, $this->resolved($uri), $this->body($uri))->assertForbidden();
    }

    #[Test]
    #[DataProvider('routes')]
    public function the_intended_permission_alone_gets_past_the_gate(string $method, string $uri, Permission $intended): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = $this->staffHoldingExactly([$intended]);

        $status = $this->actingAs($user)->json($method, $this->resolved($uri), $this->body($uri))->status();

        $this->assertContains($status, [200, 201], sprintf('%s %s answered %d to its own permission.', $method, $uri, $status));
    }

    private function resolved(string $uri): string
    {
        return str_replace(
            ['{profile}', '{node}'],
            [
                str_contains($uri, '{profile}') ? (string) OsInstallProfile::factory()->create()->getKey() : '',
                str_contains($uri, '{node}') ? (string) ComputeNode::factory()->create()->getKey() : '',
            ],
            $uri,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $uri): array
    {
        if (str_contains($uri, '/nodes/')) {
            return ['status' => 'active', 'reason' => 'Cabled and in monitoring.'];
        }

        return [
            'slug' => 'ubuntu-2404-standard',
            'name' => ['en' => 'Ubuntu 24.04 LTS', 'ar' => 'أوبنتو 24.04'],
            'os_family' => 'ubuntu',
            'os_version' => '24.04',
            'installer' => 'autoinstall',
            'template' => "#cloud-config\nautoinstall:\n  version: 1\n  identity:\n    hostname: {{ hostname }}",
        ];
    }
}
