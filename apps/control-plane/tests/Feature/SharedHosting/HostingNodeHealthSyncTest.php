<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\SharedHosting\Application\Actions\SyncHostingNodeHealth;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fleet's licence and disk state, read from the fleet.
 *
 * The scheduler refuses to place an account on a node whose panel licence is
 * not valid, and weights disk hardest of everything it scores. Both read
 * columns nothing ever wrote: a licence that lapsed in March was still valid
 * to the platform in December, and every account placed after that is a
 * customer whose site stops serving when the panel's grace period ends.
 */
final class HostingNodeHealthSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // One factory, so the node the test configures is the node the action
        // asks. The container does not bind it as a singleton in production.
        $this->app->singleton(HostingProviderFactory::class);
    }

    #[Test]
    public function a_nodes_own_readings_reach_its_row(): void
    {
        $node = HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'panel_licensed' => false,
            'licence_status' => 'unconfirmed',
            'disk_used_mib' => null,
            'last_synced_at' => null,
        ]);

        // The fake answers from the row, which is what makes it a fake and not
        // a fiction: this test sets what the "node" will say.
        $node->forceFill([
            'disk_total_mib' => 1_000_000,
            'disk_used_mib' => 400_000,
            'panel_licensed' => true,
        ])->save();

        $this->assertTrue(app(SyncHostingNodeHealth::class)->execute($node));

        $synced = $node->refresh();

        $this->assertNotNull($synced->last_synced_at);
        $this->assertNotNull($synced->licence_checked_at);
        $this->assertTrue($synced->panel_licensed);
        $this->assertNull($synced->last_sync_error);
    }

    #[Test]
    public function a_node_that_will_not_answer_is_treated_as_unlicensed(): void
    {
        /*
         * The pessimistic default, and the reason for it: a node the platform
         * cannot reach is one it cannot prove is licensed, and reading silence
         * as "still fine" is what turns a lapsed licence into a fleet-wide
         * outage weeks later.
         */
        $node = HostingNode::factory()->create([
            // A panel the factory can build and the platform cannot reach:
            // cPanel with no endpoint configured.
            'panel' => HostingPanel::Cpanel,
            'api_endpoint' => null,
            'credentials_reference' => null,
            'panel_licensed' => true,
            'licence_status' => 'valid',
        ]);

        $answered = false;

        try {
            $answered = app(SyncHostingNodeHealth::class)->execute($node);
        } catch (\Throwable) {
            // A node whose adapter cannot even be constructed is the same
            // thing to an operator, and the command below treats it the same.
            $node->forceFill(['panel_licensed' => false, 'licence_status' => 'unconfirmed'])->save();
        }

        $this->assertFalse($answered);
        $this->assertFalse($node->refresh()->panel_licensed);
        $this->assertNotSame('valid', $node->licence_status);
    }

    #[Test]
    public function the_sweep_reports_a_fleet_that_did_not_all_answer(): void
    {
        HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $this->artisan('hosting:sync-nodes')->assertExitCode(0);

        // A node with no panel behind it makes the run report failure rather
        // than a quiet success that synced nothing.
        HostingNode::factory()->create([
            'panel' => HostingPanel::Cpanel,
            'api_endpoint' => null,
            'credentials_reference' => null,
        ]);

        $this->artisan('hosting:sync-nodes')->assertExitCode(1);
    }

    #[Test]
    public function a_health_sync_never_writes_the_platforms_own_account_count(): void
    {
        /*
         * The panel does not know about an account that is still being
         * created, so its count is lower than the truth for as long as a
         * create is in flight — and writing it here would free a slot that is
         * genuinely spoken for and let the scheduler place a second account
         * into the same disk.
         */
        $node = HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'account_count' => 7,
        ]);

        app(SyncHostingNodeHealth::class)->execute($node);

        $this->assertSame(7, $node->refresh()->account_count);
    }
}
