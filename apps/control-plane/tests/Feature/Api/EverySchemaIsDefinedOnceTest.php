<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every schema in resources/openapi/schemas.php is defined once.
 *
 * The file is one PHP array literal, and PHP keeps the last of two equal keys
 * without a word. `AdminHostingNode` was defined twice - the node list's row,
 * and later the create and update response - and only the second reached
 * docs/openapi.yaml: the published node list lacked panel_version,
 * licence_status, account_count, max_accounts, disk_used_mib, disk_total_mib,
 * load_average and last_synced_at, under additionalProperties false (a residue
 * the round-six verifiers recorded).
 *
 * What this reads: the keys written at the array's top level - a line that is
 * exactly four spaces, a quoted name, ` => [` - in that file. A schema key
 * written any other way (another indentation, built from a variable) is not
 * read.
 */
final class EverySchemaIsDefinedOnceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function no_schema_name_is_written_twice(): void
    {
        $source = (string) file_get_contents(base_path('resources/openapi/schemas.php'));
        preg_match_all("/^    '([A-Za-z0-9_]+)' => \\[/m", $source, $matches);

        $counts = array_count_values($matches[1]);
        $twice = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));

        $this->assertGreaterThan(100, count($counts), 'The top-level keys were not read, so nothing is measured.');
        $this->assertSame([], $twice, 'A schema is defined twice; PHP keeps the last and drops the first without a word.');
    }

    #[Test]
    public function the_hosting_node_list_returns_only_what_its_published_schema_declares(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);
        HostingNode::factory()->create();

        $row = $this->actingAs($operator)->getJson('/api/admin/infrastructure/hosting-nodes')->assertOk()->json('data.0');
        $this->assertIsArray($row);

        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = require base_path('resources/openapi/schemas.php');
        $declared = array_keys($schemas['AdminHostingNode']['properties'] ?? []);

        $this->assertSame([], array_values(array_diff(array_keys($row), $declared)), 'The node list returns fields its published schema does not declare.');
    }
}
