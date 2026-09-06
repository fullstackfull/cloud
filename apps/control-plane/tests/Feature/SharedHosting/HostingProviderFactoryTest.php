<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingNodeNotConfiguredException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\CpanelHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\WhmConnection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One fleet, two panels, resolved per row.
 *
 * Resolution is per hosting_nodes.panel rather than from a single configured
 * driver, because a fleet of any age runs both at once: customers buy one by
 * name, migrations run for months, and acquisitions arrive with somebody
 * else's nodes.
 */
final class HostingProviderFactoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function each_node_is_driven_by_the_adapter_its_row_names(): void
    {
        $factory = app(HostingProviderFactory::class);

        $cpanel = HostingNode::factory()->create(['panel' => HostingPanel::Cpanel]);
        $directadmin = HostingNode::factory()->create(['panel' => HostingPanel::DirectAdmin]);
        $fake = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        $this->assertInstanceOf(CpanelHostingProvider::class, $factory->for($cpanel));
        $this->assertInstanceOf(DirectAdminHostingProvider::class, $factory->for($directadmin));
        $this->assertInstanceOf(FakeHostingProvider::class, $factory->for($fake));
    }

    #[Test]
    public function the_adapter_reports_the_panel_it_speaks(): void
    {
        $factory = app(HostingProviderFactory::class);

        $this->assertSame(HostingPanel::Cpanel, $factory->forPanel(HostingPanel::Cpanel)->panel());
        $this->assertSame(HostingPanel::DirectAdmin, $factory->forPanel(HostingPanel::DirectAdmin)->panel());
    }

    #[Test]
    public function adapters_are_memoised_per_panel_not_per_node(): void
    {
        // A usage sweep across forty nodes constructs two objects, not forty:
        // the adapter is stateless about which node it is talking to and
        // builds a connection per call.
        $factory = app(HostingProviderFactory::class);

        $one = HostingNode::factory()->create(['panel' => HostingPanel::Cpanel]);
        $two = HostingNode::factory()->create(['panel' => HostingPanel::Cpanel]);

        $this->assertSame($factory->for($one), $factory->for($two));
    }

    #[Test]
    public function one_node_can_be_swapped_without_affecting_the_rest_of_the_fleet(): void
    {
        // Which is the situation the scheduler and the handler actually have to
        // survive: one node misbehaving while the others keep working.
        $factory = app(HostingProviderFactory::class);

        $broken = HostingNode::factory()->create(['panel' => HostingPanel::Cpanel]);
        $healthy = HostingNode::factory()->create(['panel' => HostingPanel::Cpanel]);

        $factory->swap($broken, new FakeHostingProvider);

        $this->assertInstanceOf(FakeHostingProvider::class, $factory->for($broken));
        $this->assertInstanceOf(CpanelHostingProvider::class, $factory->for($healthy));
    }

    #[Test]
    public function a_node_with_no_configured_credentials_is_refused_before_any_request_is_made(): void
    {
        /*
         * Credentials live in configuration and never on the node row. A WHM
         * root API token in the database is that token in every backup, every
         * replica and every support export — and it is root on a machine
         * holding several hundred customers' sites, databases and mail.
         */
        $node = HostingNode::factory()->create([
            'panel' => HostingPanel::Cpanel,
            'credentials_reference' => 'nothing-configured-here',
        ]);

        try {
            WhmConnection::forNode($node);

            $this->fail('A node with no credentials produced a usable connection.');
        } catch (HostingNodeNotConfiguredException $e) {
            $this->assertSame('hosting.node_credentials_missing', $e->errorCode());
            // The reference is a config key name, not a secret.
            $this->assertSame('nothing-configured-here', $e->context()['credentials_reference']);
        }
    }
}
