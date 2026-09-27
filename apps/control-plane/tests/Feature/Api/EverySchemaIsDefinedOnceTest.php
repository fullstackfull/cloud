<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
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
 *
 * Once is also once among the names the generator writes beside it.
 * GenerateOpenApiSpec starts from the schemas in
 * resources/openapi/components.php, writes each schemas.php entry over it by
 * name, and after each entry X writes the envelopes X.'Page' and
 * X.'Response' when a path references them - each assignment replacing
 * whatever held the name, again without a word. So a schemas.php key must
 * not be a components.php schema or any schemas.php entry's envelope name,
 * and a components.php schema must not be an envelope name either. The
 * envelope suffixes are read from the generator's source (every
 * `$allSchemas[$schemaName.'Suffix']` it assigns), not listed here; the
 * schema names are the keys of the two arrays as PHP loads them. The
 * envelopes are counted whether or not a path references them today.
 *
 * The behavioural half calls two operator lists whose row schema in
 * schemas.php has additionalProperties false - hosting nodes
 * (AdminHostingNode) and address pools (AdminIpPool) - and holds that every
 * field a row returns is one its published schema declares. Other lists with
 * such a schema are not called here.
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
    public function no_schema_name_is_one_the_generator_also_writes_from_elsewhere(): void
    {
        $this->assertSame([], self::collisions(), 'A schema name the generator writes twice; the last assignment wins without a word.');
    }

    /**
     * The collision check on names written here, and the suffix reading on
     * the generator as it is.
     */
    #[Test]
    public function the_collision_check_refuses_each_kind_of_collision(): void
    {
        $this->assertSame(['Page', 'Response'], self::envelopeSuffixes());

        $this->assertSame([], self::collisions(['Money' => []], ['Invoice' => [], 'Invoices' => []]), 'The positive control: distinct names collide with nothing.');
        $this->assertSame(
            ['Money - a schemas.php key that is also a components.php schema'],
            self::collisions(['Money' => []], ['Money' => []]),
        );
        $this->assertSame(
            ['InvoicePage - a schemas.php key that is also the envelope the generator writes for Invoice'],
            self::collisions([], ['Invoice' => [], 'InvoicePage' => []]),
        );
        $this->assertSame(
            ['InvoiceResponse - a components.php schema that is also the envelope the generator writes for Invoice'],
            self::collisions(['InvoiceResponse' => []], ['Invoice' => []]),
        );
    }

    #[Test]
    public function the_ip_pool_list_returns_only_what_its_published_schema_declares(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);
        IpPool::factory()->create();

        $row = $this->actingAs($operator)->getJson('/api/admin/infrastructure/ip-pools')->assertOk()->json('data.0');
        $this->assertIsArray($row);

        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = require base_path('resources/openapi/schemas.php');
        $this->assertFalse($schemas['AdminIpPool']['additionalProperties'] ?? true, 'The pool row schema no longer refuses undeclared fields; this test is about one that does.');
        $declared = array_keys($schemas['AdminIpPool']['properties'] ?? []);

        $this->assertSame([], array_values(array_diff(array_keys($row), $declared)), 'The pool list returns fields its published schema does not declare.');
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

    /**
     * Every name the generator would write twice, with why. With no
     * arguments, the two files as PHP loads them.
     *
     * @param  array<string, mixed>|null  $components  components.php's schemas
     * @param  array<string, mixed>|null  $schemas  schemas.php
     * @return list<string>
     */
    private static function collisions(?array $components = null, ?array $schemas = null): array
    {
        if ($components === null) {
            /** @var array{schemas: array<string, mixed>} $file */
            $file = require base_path('resources/openapi/components.php');
            $components = $file['schemas'];
        }

        /** @var array<string, mixed> $schemas */
        $schemas ??= require base_path('resources/openapi/schemas.php');

        $envelopes = [];

        foreach (array_keys($schemas) as $name) {
            foreach (self::envelopeSuffixes() as $suffix) {
                $envelopes[$name.$suffix] = $name;
            }
        }

        $collisions = [];

        foreach (array_keys($schemas) as $name) {
            if (array_key_exists($name, $components)) {
                $collisions[] = "{$name} - a schemas.php key that is also a components.php schema";
            }

            if (isset($envelopes[$name])) {
                $collisions[] = "{$name} - a schemas.php key that is also the envelope the generator writes for {$envelopes[$name]}";
            }
        }

        foreach (array_keys($components) as $name) {
            if (isset($envelopes[$name])) {
                $collisions[] = "{$name} - a components.php schema that is also the envelope the generator writes for {$envelopes[$name]}";
            }
        }

        return $collisions;
    }

    /**
     * The suffixes of the envelopes the generator writes: every
     * `$allSchemas[$schemaName.'Suffix'] =` in its source.
     *
     * @return list<string>
     */
    private static function envelopeSuffixes(): array
    {
        $source = (string) file_get_contents(base_path('app/Console/Commands/GenerateOpenApiSpec.php'));
        preg_match_all('/\$allSchemas\[\$schemaName\.\'([A-Za-z0-9_]+)\'\]\s*=/', $source, $matches);

        return array_values(array_unique($matches[1]));
    }
}
