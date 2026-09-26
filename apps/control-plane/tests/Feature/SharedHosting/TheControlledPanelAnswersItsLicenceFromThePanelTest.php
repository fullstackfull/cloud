<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterHostingNode;
use Lynomia\Modules\SharedHosting\Application\Actions\SyncHostingNodeHealth;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The controlled panel's licence is the panel's answer, not the platform's
 * own row read back to it.
 *
 * Found by round three's estate work (F-02): the simulator answered
 * `licenceStatus()` from `hosting_nodes.panel_licensed`. A node an operator
 * has just registered carries `false` there, because nothing has asked the
 * panel yet (RegisterHostingNode) — so the simulator said "unlicensed", the
 * sync wrote "unlicensed" back, and a registered node standing in for a
 * licensed panel could never become licensed. The estate test needed a
 * wrapper (`tests/Support/LicensedPanel.php` on round3/03) to get past it.
 *
 * A real panel answers from its vendor, whatever the platform's row says, and
 * the simulator now keeps that shape: its answer is a property of the panel —
 * valid unless the node's hostname carries
 * {@see FakeHostingProvider::LICENCE_LAPSED_MARKER} — and the row is what the
 * sync WRITES from it, never what the panel reads. The lapsed path is still
 * reachable, now by a marker as every other simulated fault is.
 *
 * WHAT THIS DOES NOT CLAIM: nothing about a real vendor's licence server. The
 * controlled panel names no product and holds no licence; "valid" here means
 * the simulated panel serves.
 */
final class TheControlledPanelAnswersItsLicenceFromThePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HostingProviderFactory::class);
    }

    #[Test]
    public function a_node_an_operator_has_just_registered_becomes_licensed_once_the_panel_is_asked(): void
    {
        $node = $this->register('kw-panel-1.estate.example');

        $this->assertFalse($node->panel_licensed, 'Registration claimed a licence nobody had asked about.');

        $this->assertTrue(app(SyncHostingNodeHealth::class)->execute($node));

        $node->refresh();
        $this->assertTrue($node->panel_licensed, 'The panel said its licence serves and the row still says it does not.');
        $this->assertNull($node->last_sync_error);
    }

    #[Test]
    public function a_panel_whose_licence_lapsed_says_so_whatever_the_row_says(): void
    {
        $node = $this->register('kw-panel-2-'.FakeHostingProvider::LICENCE_LAPSED_MARKER.'.estate.example');

        // The row claims a licence; the panel is what gets believed.
        $node->forceFill(['panel_licensed' => true, 'licence_status' => 'active'])->save();

        app(SyncHostingNodeHealth::class)->execute($node);

        $node->refresh();
        $this->assertFalse($node->panel_licensed);
        $this->assertSame('expired', $node->licence_status);
        $this->assertNotNull($node->last_sync_error, 'The reason the licence was not confirmed is not on the row.');
    }

    private function register(string $hostname): HostingNode
    {
        return app(RegisterHostingNode::class)->execute(
            datacenter: Datacenter::factory()->create(),
            slug: strtok($hostname, '.') ?: 'kw-panel',
            hostname: $hostname,
            panel: HostingPanel::Fake,
            apiEndpoint: null,
            verifyTls: true,
            credentialsReference: null,
            maxAccounts: 200,
            operator: User::factory()->create(),
        );
    }
}
