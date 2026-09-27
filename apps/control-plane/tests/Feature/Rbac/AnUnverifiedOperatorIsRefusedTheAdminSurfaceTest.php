<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A staff member whose address is unproved is refused the real admin surface
 * (B2, re-audit after round five, unnumbered).
 *
 * EmailVerificationEnforcementTest proves the `verified` middleware on a
 * route of its own; this proves it on real /api/admin routes — both route
 * groups in routes/api_admin.php — with the code the portal reads to show the
 * verification step rather than a dead end. The route-table half is
 * tests/Architecture/EveryAdminRouteRequiresAVerifiedAddressTest.php.
 */
final class AnUnverifiedOperatorIsRefusedTheAdminSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function an_unverified_super_admin_is_refused_and_a_verified_one_is_not(): void
    {
        $unverified = User::factory()->unverified()->create();
        $unverified->syncRoles([Role::SuperAdmin->value]);

        // One route from each group in routes/api_admin.php.
        foreach (['/api/admin/customers', '/api/admin/operators', '/api/admin/support/tickets'] as $uri) {
            $this->actingAs($unverified)
                ->getJson($uri)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'auth.email_unverified');
        }

        // Positive control: the same role on a verified address opens them.
        $verified = User::factory()->create(['email_verified_at' => now()]);
        $verified->syncRoles([Role::SuperAdmin->value]);

        foreach (['/api/admin/customers', '/api/admin/operators', '/api/admin/support/tickets'] as $uri) {
            $this->actingAs($verified)->getJson($uri)->assertOk();
        }
    }
}
