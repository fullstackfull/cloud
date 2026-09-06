<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Database\Factories\HostingNodeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingPlacementRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\NoHostingCapacityException;
use Lynomia\Modules\SharedHosting\Domain\Services\HostingNodeScheduler;
use Lynomia\Modules\SharedHosting\Domain\ValueObjects\HostingPlacementRejection;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where an account goes, and — more importantly — where it does not.
 *
 * Eligibility and preference are separate concerns and the tests keep them
 * separate. A node past its disk threshold, in maintenance or unlicensed is
 * EXCLUDED, never merely scored low, because a low score still wins when it is
 * the only score — and "the only node left" is exactly the situation in which
 * placing onto a node at 95% disk does the most damage.
 */
final class HostingNodeSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private HostingPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hosting.scheduler.max_disk_used_percent' => 75,
            'hosting.scheduler.max_load_average' => 8.0,
            'hosting.scheduler.max_accounts_per_node' => 250,
        ]);

        $this->package = HostingPackage::factory()->diskQuotaMib(10_240)->create();
    }

    #[Test]
    public function it_skips_a_node_over_the_disk_threshold(): void
    {
        /*
         * The heaviest exclusion. A shared node that fills up does not degrade
         * — MySQL stops writing, Dovecot stops accepting mail, and every site
         * on the machine breaks at the same moment.
         */
        $full = $this->node(['slug' => 'full'])->diskUsedPercent(92)->create();
        $roomy = $this->node(['slug' => 'roomy'])->diskUsedPercent(20)->create();

        $decision = $this->scheduler()->place($this->request());

        $this->assertTrue($decision->node()->is($roomy));
        $this->assertSame(
            'disk_threshold_exceeded',
            $this->reasonFor($decision->rejections, (string) $full->getKey()),
        );
    }

    #[Test]
    public function it_skips_a_node_in_maintenance(): void
    {
        // The panel is being upgraded and will restart the web and mail stacks
        // under everybody on it. An account placed now has to be migrated
        // within the hour, and migrating a shared account means moving mail,
        // databases and DNS.
        $maintenance = $this->node(['slug' => 'maint'])->inMaintenance()->diskUsedPercent(5)->create();
        $healthy = $this->node(['slug' => 'healthy'])->diskUsedPercent(50)->create();

        $decision = $this->scheduler()->place($this->request());

        $this->assertTrue($decision->node()->is($healthy));
        $this->assertSame('in_maintenance', $this->reasonFor($decision->rejections, (string) $maintenance->getKey()));
    }

    #[Test]
    public function it_skips_an_unlicensed_node(): void
    {
        /*
         * cPanel and DirectAdmin are commercial products: an unlicensed node's
         * panel stops serving and the accounts on it stop working. It still
         * answers its API and still looks healthy in the meantime, which is
         * exactly why this is checked rather than inferred from health.
         */
        $unlicensed = $this->node(['slug' => 'unlicensed'])->unlicensed()->diskUsedPercent(1)->create();
        $licensed = $this->node(['slug' => 'licensed'])->diskUsedPercent(60)->create();

        $decision = $this->scheduler()->place($this->request());

        // The emptiest node in the fleet loses to a much fuller one, because
        // eligibility is not a score.
        $this->assertTrue($decision->node()->is($licensed));
        $this->assertSame('unlicensed', $this->reasonFor($decision->rejections, (string) $unlicensed->getKey()));
    }

    #[Test]
    public function it_skips_a_node_that_has_reached_its_account_ceiling(): void
    {
        $full = $this->node(['slug' => 'dense'])->withAccounts(120, 120)->diskUsedPercent(10)->create();
        $roomy = $this->node(['slug' => 'sparse'])->withAccounts(10, 120)->diskUsedPercent(40)->create();

        $decision = $this->scheduler()->place($this->request());

        $this->assertTrue($decision->node()->is($roomy));
        $this->assertSame('account_limit_reached', $this->reasonFor($decision->rejections, (string) $full->getKey()));
    }

    #[Test]
    public function it_skips_a_node_over_the_load_threshold(): void
    {
        $hot = $this->node(['slug' => 'hot'])->loadAverage(24.0)->diskUsedPercent(5)->create();
        $calm = $this->node(['slug' => 'calm'])->loadAverage(1.0)->diskUsedPercent(50)->create();

        $decision = $this->scheduler()->place($this->request());

        $this->assertTrue($decision->node()->is($calm));
        $this->assertSame('load_threshold_exceeded', $this->reasonFor($decision->rejections, (string) $hot->getKey()));
    }

    #[Test]
    public function it_skips_a_node_closed_to_new_accounts_by_an_operator(): void
    {
        $closed = $this->node(['slug' => 'closed', 'accepts_new_accounts' => false])->diskUsedPercent(2)->create();
        $open = $this->node(['slug' => 'open'])->diskUsedPercent(55)->create();

        $decision = $this->scheduler()->place($this->request());

        $this->assertTrue($decision->node()->is($open));
        $this->assertSame('node_not_accepting', $this->reasonFor($decision->rejections, (string) $closed->getKey()));
    }

    #[Test]
    public function it_skips_a_node_running_the_wrong_panel_when_the_order_names_one(): void
    {
        // Customers buy "cPanel hosting" by name, and their backups and
        // migration tooling assume one of the two. Delivering the other is
        // delivering something they cannot restore their site into.
        $directadmin = $this->node(['slug' => 'da', 'panel' => HostingPanel::DirectAdmin])->diskUsedPercent(1)->create();
        $cpanel = $this->node(['slug' => 'cp', 'panel' => HostingPanel::Cpanel])->diskUsedPercent(60)->create();

        $decision = $this->scheduler()->place($this->request(panel: HostingPanel::Cpanel));

        $this->assertTrue($decision->node()->is($cpanel));
        $this->assertSame('panel_mismatch', $this->reasonFor($decision->rejections, (string) $directadmin->getKey()));
    }

    #[Test]
    public function it_skips_a_node_in_another_region(): void
    {
        // A hosting account holds the customer's mail and database as well as
        // their files, so where it lives is a data-residency decision.
        $home = Datacenter::factory()->create();
        $away = Datacenter::factory()->create();

        $wrong = $this->node(['slug' => 'away', 'datacenter_id' => $away->getKey()])->diskUsedPercent(1)->create();
        $right = $this->node(['slug' => 'home', 'datacenter_id' => $home->getKey()])->diskUsedPercent(60)->create();

        $decision = $this->scheduler()->place($this->request(regionId: $home->region_id));

        $this->assertTrue($decision->node()->is($right));
        $this->assertSame('wrong_region', $this->reasonFor($decision->rejections, (string) $wrong->getKey()));
    }

    #[Test]
    public function it_skips_a_node_that_cannot_hold_the_packages_disk_quota(): void
    {
        // A 50 GiB package on a node with 5 GiB of headroom is a node that
        // hits its threshold as soon as the customer uses what they bought.
        $big = HostingPackage::factory()->diskQuotaMib(51_200)->create();

        // Half empty, so it is comfortably inside the disk threshold and the
        // only thing that excludes it is the size of the package itself.
        $tight = $this->node(['slug' => 'tight', 'disk_total_mib' => 20_480, 'disk_used_mib' => 10_240])->create();
        $roomy = $this->node(['slug' => 'roomy', 'disk_total_mib' => 1_048_576, 'disk_used_mib' => 104_857])->create();

        $decision = $this->scheduler()->place(new HostingPlacementRequest(packageId: (string) $big->getKey()));

        $this->assertTrue($decision->node()->is($roomy));
        $this->assertSame('package_incompatible', $this->reasonFor($decision->rejections, (string) $tight->getKey()));
    }

    #[Test]
    public function disk_outweighs_every_other_term(): void
    {
        /*
         * The policy in one assertion. The node that is worse on BOTH other
         * terms — more accounts and a higher load — wins because it has far
         * more free disk, since a full shared node breaks every site on it
         * simultaneously while a busy one merely runs slower.
         */
        $tightOnDisk = $this->node(['slug' => 'tight-disk'])
            ->diskUsedPercent(70)
            ->withAccounts(100, 200)
            ->loadAverage(4.0)
            ->create();

        $spacious = $this->node(['slug' => 'spacious'])
            ->diskUsedPercent(10)
            ->withAccounts(120, 200)
            ->loadAverage(5.0)
            ->create();

        $decision = $this->scheduler()->place($this->request());

        $this->assertTrue($decision->node()->is($spacious));

        // And it won on disk specifically, not by accident of the other terms.
        $this->assertGreaterThan(
            $decision->candidates[1]->component('account_headroom')?->contribution(),
            $decision->chosen->component('disk_headroom')?->contribution(),
        );
        $this->assertTrue($tightOnDisk->is($decision->candidates[1]->node));
    }

    #[Test]
    public function a_node_that_has_not_reported_its_disk_never_wins_on_the_heaviest_term(): void
    {
        // Missing data must not be the best answer: the node most likely to
        // have stopped reporting is the one that is struggling.
        $silent = $this->node(['slug' => 'silent', 'disk_total_mib' => null, 'disk_used_mib' => null])->create();
        $reporting = $this->node(['slug' => 'reporting'])->diskUsedPercent(60)->create();

        $decision = $this->scheduler()->place($this->request());

        $this->assertTrue($decision->node()->is($reporting));
        // Still eligible — an operator may simply not have run a sync yet.
        $this->assertCount(2, $decision->candidates);
        // Null, not 0.0: a node that has not reported must never look empty,
        // and "?? 0.0" in an assertion would have passed either way.
        $this->assertNull($silent->fresh()?->diskUsedPercent());
    }

    #[Test]
    public function an_exhausted_fleet_throws_with_a_tally_an_operator_can_act_on(): void
    {
        // The tally is the point: a fleet rejected for disk resolves itself as
        // accounts churn, and a fleet rejected for licensing needs somebody to
        // buy something. Telling an operator to wait for the second is wrong.
        $this->node(['slug' => 'a'])->unlicensed()->create();
        $this->node(['slug' => 'b'])->diskUsedPercent(99)->create();

        try {
            $this->scheduler()->place($this->request());

            $this->fail('An account was placed on a fleet with nothing eligible in it.');
        } catch (NoHostingCapacityException $e) {
            $this->assertSame('hosting.no_capacity_available', $e->errorCode());
            $this->assertStringContainsString('unlicensed=1', (string) $e->context()['rejections']);
            $this->assertStringContainsString('disk_threshold_exceeded=1', (string) $e->context()['rejections']);
        }
    }

    #[Test]
    public function the_disk_threshold_is_configurable_and_a_tighter_one_excludes_more(): void
    {
        $node = $this->node(['slug' => 'sixty'])->diskUsedPercent(60)->create();

        $this->assertTrue($this->scheduler()->place($this->request())->node()->is($node));

        config(['hosting.scheduler.max_disk_used_percent' => 50]);

        $this->expectException(NoHostingCapacityException::class);

        $this->scheduler()->place($this->request());
    }

    private function scheduler(): HostingNodeScheduler
    {
        return app(HostingNodeScheduler::class);
    }

    private function request(?string $regionId = null, ?HostingPanel $panel = null): HostingPlacementRequest
    {
        return new HostingPlacementRequest(
            packageId: (string) $this->package->getKey(),
            regionId: $regionId,
            panel: $panel,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function node(array $attributes = []): HostingNodeFactory
    {
        // cPanel rather than the factory's fake default, because the fake panel
        // is not a licensed product and the licensing rules — which several of
        // these tests are entirely about — correctly do not apply to it.
        return HostingNode::factory()->state(['panel' => HostingPanel::Cpanel, ...$attributes]);
    }

    /**
     * @param  list<HostingPlacementRejection>  $rejections
     */
    private function reasonFor(array $rejections, string $nodeId): ?string
    {
        foreach ($rejections as $rejection) {
            if ($rejection->nodeId === $nodeId) {
                return $rejection->reason->value;
            }
        }

        return null;
    }
}
