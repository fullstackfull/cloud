<?php

declare(strict_types=1);

namespace Tests\Feature\Naming;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingConcept;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingScope;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where each name has to be unique, proved against the database that decides.
 *
 * ===========================================================================
 * WHY THIS TEST EXISTS AT ALL
 * ===========================================================================
 *
 * Because "unique" is the assumption a naming standard is most likely to get
 * wrong, in the direction that hurts: demand global uniqueness and two
 * datacenters cannot both have a rack `A1`, which every datacenter in the
 * world does. The operator's answer to that is a prefix nobody agreed on, and
 * the second naming scheme is born.
 *
 * So the scopes in {@see NamingScope} were read off the unique indexes rather
 * than chosen, and this test holds them to the indexes: the same code in two
 * scopes is accepted where the schema allows it, and refused where it does
 * not. If somebody widens an index later, the assertion here fails and the
 * standard is corrected rather than silently wrong.
 */
final class UniquenessIsScopedTheWayTheSchemaSaysTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function two_datacenters_may_each_have_a_rack_with_the_same_code(): void
    {
        $first = Datacenter::factory()->create(['slug' => 'ref-dc-alpha-1']);
        $second = Datacenter::factory()->create(['slug' => 'ref-dc-alpha-2']);

        Rack::query()->create(['datacenter_id' => $first->getKey(), 'name' => 'A1', 'units' => 42]);
        Rack::query()->create(['datacenter_id' => $second->getKey(), 'name' => 'A1', 'units' => 42]);

        $this->assertSame(2, Rack::query()->where('name', 'A1')->count());
        $this->assertSame(NamingScope::Datacenter, NamingConcept::RackCode->scope());
    }

    #[Test]
    public function one_datacenter_may_not_have_two_racks_with_the_same_code(): void
    {
        $datacenter = Datacenter::factory()->create();

        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'A1', 'units' => 42]);

        $this->expectException(QueryException::class);

        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'A1', 'units' => 42]);
    }

    #[Test]
    public function two_clusters_may_each_stage_a_template_with_the_same_logical_key(): void
    {
        // The whole point of §17's separation: `debian-stable` is one logical
        // image staged on two clusters, and each cluster's copy has its own
        // provider reference. Global uniqueness here would make the second
        // cluster's copy need a different name for the same thing.
        $first = ComputeCluster::factory()->create(['slug' => 'ref-cluster-alpha-1']);
        $second = ComputeCluster::factory()->create(['slug' => 'ref-cluster-alpha-2']);

        VmTemplate::factory()->create(['cluster_id' => $first->getKey(), 'slug' => 'debian-stable']);
        VmTemplate::factory()->create(['cluster_id' => $second->getKey(), 'slug' => 'debian-stable']);

        $this->assertSame(2, VmTemplate::query()->where('slug', 'debian-stable')->count());
        $this->assertSame(NamingScope::Cluster, NamingConcept::TemplateSlug->scope());
    }

    #[Test]
    public function a_cluster_logical_key_is_unique_across_the_whole_platform(): void
    {
        ComputeCluster::factory()->create(['slug' => 'ref-cluster-alpha-1']);

        $this->expectException(QueryException::class);

        ComputeCluster::factory()->create(['slug' => 'ref-cluster-alpha-1']);
    }

    #[Test]
    public function one_cluster_may_not_have_two_nodes_the_provider_calls_the_same_thing(): void
    {
        $cluster = ComputeCluster::factory()->create();

        ComputeNode::factory()->create(['cluster_id' => $cluster->getKey(), 'provider_name' => 'ref-node-alpha-1-a']);

        $this->expectException(QueryException::class);

        ComputeNode::factory()->create(['cluster_id' => $cluster->getKey(), 'provider_name' => 'ref-node-alpha-1-a']);
    }

    #[Test]
    public function two_clusters_may_each_have_a_node_the_provider_calls_the_same_thing(): void
    {
        // Two Proxmox clusters each with a node called `pve-01` is ordinary:
        // the name is Proxmox's, scoped to the cluster it belongs to, and the
        // index says `cluster_id, provider_name`.
        $first = ComputeCluster::factory()->create(['slug' => 'ref-cluster-alpha-1']);
        $second = ComputeCluster::factory()->create(['slug' => 'ref-cluster-alpha-2']);

        ComputeNode::factory()->create(['cluster_id' => $first->getKey(), 'provider_name' => 'pve-01']);
        ComputeNode::factory()->create(['cluster_id' => $second->getKey(), 'provider_name' => 'pve-01']);

        $this->assertSame(2, ComputeNode::query()->where('provider_name', 'pve-01')->count());
        $this->assertSame(NamingScope::Cluster, NamingConcept::NodeProviderName->scope());
    }

    #[Test]
    public function two_different_things_may_share_a_display_name(): void
    {
        // Nothing references a display name, so two datacenters called
        // "Reference site" are a naming inconvenience and not a conflict. The
        // standard says so rather than inventing a uniqueness rule the schema
        // does not have.
        Datacenter::factory()->create(['slug' => 'ref-dc-alpha-1', 'name' => 'Reference site']);
        Datacenter::factory()->create(['slug' => 'ref-dc-alpha-2', 'name' => 'Reference site']);

        $this->assertSame(2, Datacenter::query()->where('name', 'Reference site')->count());
        $this->assertSame(NamingScope::NotUnique, NamingConcept::DatacenterDisplayName->scope());
    }

    #[Test]
    public function every_scope_the_standard_states_is_the_scope_the_database_enforces(): void
    {
        /*
         * Read from PostgreSQL rather than from a list in this file. A test
         * that restated the standard's own answer would pass while the index
         * said something else — which is the failure mode this whole gap is
         * about.
         */
        $indexes = collect(DB::select(
            "select tablename, indexdef from pg_indexes where schemaname = current_schema() and indexdef like '%UNIQUE%'"
        ));

        foreach (NamingConcept::persisted() as $concept) {
            $expected = $concept->scope()->keyColumns($concept->column());

            if ($expected === []) {
                continue;
            }

            $matching = $indexes->first(function (object $index) use ($concept, $expected): bool {
                if ($index->tablename !== $concept->table()) {
                    return false;
                }

                $definition = (string) $index->indexdef;
                $columns = substr($definition, (int) strrpos($definition, '(') + 1);
                $columns = array_map('trim', explode(',', rtrim($columns, ')')));

                return $columns === $expected;
            });

            $this->assertNotNull($matching, sprintf(
                '%s is stated as %s, which needs a unique index on (%s) and %s has none.',
                $concept->value,
                $concept->scope()->explanation(),
                implode(', ', $expected),
                $concept->table(),
            ));
        }
    }
}
