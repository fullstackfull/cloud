<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\E2ESeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SoftwareCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceKind;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceTopology;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The browser suite's fixtures sit on top of the reference estate, and the
 * seam between them is a contract rather than a coincidence.
 *
 * ===========================================================================
 * THE FAILURE THIS EXISTS TO PREVENT, WHICH ALREADY HAPPENED
 * ===========================================================================
 *
 * Gap 4 moved the estate out of the development seeder and into
 * resources/reference-topology/topology.php. `E2ESeeder` had been reaching for
 * `where('slug', 'debian-13')` — the slug the old seeder happened to write —
 * and the reference estate publishes `debian-stable`, because §17 separates a
 * template's logical key from the provider's own identifier on purpose.
 *
 * Nothing in the backend suite noticed. The browser job did, in CI, on the
 * pushed commit, as:
 *
 *     E2ESeeder.php:390  Builder::firstOrFail()
 *     No query results for model VmTemplate.
 *
 * That message names a model and a line. It does not say that an estate stopped
 * offering something a suite depends on, which is the actual fact, so whoever
 * reads it has to rediscover the contract before they can fix it.
 *
 * ===========================================================================
 * WHAT THE TWO TESTS HERE DO DIFFERENTLY
 * ===========================================================================
 *
 * The first reads the canonical topology and asserts it still offers what the
 * browser suite builds on — it fails in the backend suite, in seconds, naming
 * the file to edit, before anybody starts a browser.
 *
 * The second runs the real thing: the seeders CI runs, in the order CI runs
 * them, against a fresh database. It is the one that cannot be satisfied by a
 * gate agreeing with itself.
 */
final class TheBrowserSuiteBuildsOnTheReferenceTopologyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The contract, checked against the canonical source.
     *
     * Stated as a property rather than a name: for every architecture the
     * estate's nodes advertise, that node's cluster must publish at least one
     * INSTALLABLE image — active, with a provider reference. Renaming
     * `debian-stable` breaks nothing here, and that is correct; removing the
     * last installable x86_64 image breaks it, and that is the thing the
     * browser suite cannot survive.
     */
    #[Test]
    public function the_reference_topology_offers_an_installable_image_for_every_architecture_its_nodes_advertise(): void
    {
        $topology = ReferenceTopology::load();

        $wanted = [];

        foreach ($topology->of(ReferenceKind::Node) as $node) {
            $cluster = $node->ref('cluster');
            // A node that reports no architecture is placed as x86_64, which is
            // what ComputeNode::architecture() answers and therefore what the
            // seeder will go looking for. The gate asks the same question the
            // seeder asks, or it is not a gate on the seeder.
            $architecture = $node->stringOrNull('architecture') ?? CpuArchitecture::X86_64->value;
            $wanted[$cluster.'|'.$architecture] = [$cluster, $architecture, $node->id];
        }

        self::assertNotSame([], $wanted, 'The reference topology declares no compute nodes, so this gate would pass vacuously.');

        foreach ($wanted as [$cluster, $architecture, $nodeId]) {
            $offered = array_filter(
                $topology->of(ReferenceKind::Template),
                static fn ($template): bool => $template->ref('cluster') === $cluster
                    && $template->string('architecture') === $architecture
                    && $template->bool('is_active')
                    && $template->stringOrNull('provider_reference') !== null,
            );

            self::assertNotSame([], $offered, implode("\n", [
                sprintf('Node %s advertises %s and its cluster %s offers no installable image for it.', $nodeId, $architecture, $cluster),
                'An installable image is active and carries a provider reference.',
                'E2ESeeder::installableTemplateOn() builds the machine the browser suite reboots and rebuilds on one of these,',
                'so without it the browser job fails during seeding rather than in a spec.',
                'Add or re-enable one in resources/reference-topology/topology.php. Do not name a template in E2ESeeder.',
            ]));
        }
    }

    /**
     * The regression: the exact sequence the browser job runs.
     *
     * `migrate:fresh --seed` then `db:seed --class=E2ESeeder`, which under
     * APP_ENV=local means permissions, the software catalogue, the development
     * seed — which is now the reference estate — and then the browser
     * fixtures. Spelled out here because a test environment does not reach
     * DevelopmentSeeder through DatabaseSeeder's environment check, and
     * skipping it would test a path CI never takes.
     */
    #[Test]
    public function a_fresh_database_seeds_the_reference_estate_and_then_the_browser_fixtures(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SoftwareCatalogueSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        self::assertGreaterThan(0, ComputeNode::count(), 'The reference estate did not load, so the browser fixtures were never the thing under test.');
        self::assertGreaterThan(0, VmTemplate::query()->installable()->count());

        $this->seed(E2ESeeder::class);

        $operable = VirtualMachine::query()->whereNotNull('template_id')->first();

        self::assertNotNull($operable, 'No seeded machine carries a template, so nothing can be rebuilt.');

        $template = VmTemplate::query()->whereKey($operable->template_id)->sole();

        // It came from the estate, not from the seeder: active, carrying the
        // provider reference the canonical source published, and agreeing with
        // the machine about what the machine is running.
        self::assertTrue($template->is_active);
        self::assertNotNull($template->provider_reference);
        // The machine records its OS as a string and the template as an enum,
        // so the comparison is on the value either way.
        self::assertSame($template->os_family->value, (string) $operable->os_family);
        self::assertSame($template->os_version, $operable->os_version);

        $node = ComputeNode::query()->whereKey($operable->node_id)->sole();

        self::assertSame(
            $node->architecture()->value,
            $template->architecture->value,
            'The machine was built from an image its node cannot run.',
        );
    }

    /**
     * The guard is load-bearing: with no installable image, seeding fails with
     * a sentence about the estate rather than a ModelNotFoundException.
     *
     * This is the difference the CI failure taught. Both refuse; only one tells
     * the next person what broke and which file to open.
     */
    #[Test]
    public function without_an_installable_image_the_seeder_names_the_contract_rather_than_a_missing_model(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SoftwareCatalogueSeeder::class);
        $this->seed(DevelopmentSeeder::class);

        // Exactly what a topology change would do: the images stop being
        // installable. Nothing else about the estate moves.
        VmTemplate::query()->update(['provider_reference' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/installable .* image on cluster/');
        $this->expectExceptionMessageMatches('/reference-topology\/topology\.php/');

        $this->seed(E2ESeeder::class);
    }
}
