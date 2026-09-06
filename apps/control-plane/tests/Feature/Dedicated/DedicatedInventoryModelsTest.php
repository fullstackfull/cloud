<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The inventory rows, and the invariants that live on them rather than in an
 * action.
 */
final class DedicatedInventoryModelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_machine_hangs_off_a_rack_in_a_datacenter(): void
    {
        $datacenter = Datacenter::factory()->create();
        $rack = Rack::factory()->inDatacenter($datacenter)->create(['units' => 42]);

        $server = DedicatedServer::factory()->inRack($rack, unit: 12)->create(['height_units' => 2]);

        $this->assertSame($rack->id, $server->rack->id);
        $this->assertSame($datacenter->id, $server->datacenter->id);
        $this->assertSame(12, $server->rack_unit);

        // The rack is a unit of correlated failure smaller than the building —
        // one pair of PDUs, one pair of switches — which is what turns "four
        // customers went down at once" into a cabinet number.
        $this->assertSame(40, $rack->fresh()?->unoccupiedUnits());
    }

    #[Test]
    public function the_best_controller_wins_regardless_of_the_order_the_rows_were_written(): void
    {
        $server = DedicatedServer::factory()->create();

        // IPMI written first, deliberately: preference must not be insertion
        // order.
        BmcEndpoint::factory()->forServer($server)->protocol(BmcProtocol::Ipmi)->create();
        BmcEndpoint::factory()->forServer($server)->protocol(BmcProtocol::Ilo)->create();
        BmcEndpoint::factory()->forServer($server)->protocol(BmcProtocol::Redfish)->create();

        $this->assertSame(BmcProtocol::Redfish, $server->preferredBmcEndpoint()?->protocol);
    }

    #[Test]
    public function a_machine_with_only_ipmi_still_resolves_an_endpoint(): void
    {
        $server = DedicatedServer::factory()->create();

        BmcEndpoint::factory()->forServer($server)->protocol(BmcProtocol::Ipmi)->create();

        // Some machines have nothing better. Being unreachable would be worse
        // than being reachable dangerously.
        $this->assertSame(BmcProtocol::Ipmi, $server->preferredBmcEndpoint()?->protocol);
        $this->assertTrue(BmcProtocol::Ipmi->isLastResort());
    }

    #[Test]
    public function the_provisioning_nic_wins_over_the_first_one_listed(): void
    {
        $server = DedicatedServer::factory()->create();

        // Onboard ports are enumerated in firmware order, not in the order they
        // are cabled, so "the first NIC" is a coin toss.
        ServerComponent::factory()->forServer($server)->nic('11:22:33:44:55:66', pxeEnabled: false)->create();
        ServerComponent::factory()->forServer($server)->nic('AA-BB-CC-DD-EE-FF', pxeEnabled: true)->create();

        // And the address is normalised on the way out: the boot server is told
        // which MAC may install, and a vendor's spelling of the right address
        // is a machine that silently never boots.
        $this->assertSame('aa:bb:cc:dd:ee:ff', $server->provisioningMacAddress());
    }

    #[Test]
    public function a_machine_with_no_nic_reports_no_address_rather_than_guessing(): void
    {
        $server = DedicatedServer::factory()->create();

        ServerComponent::factory()->forServer($server)->kind(ComponentKind::Disk)->create();

        $this->assertNull($server->provisioningMacAddress());
    }

    #[Test]
    public function the_allocatable_scope_excludes_everything_that_is_not_on_the_shelf(): void
    {
        $datacenter = Datacenter::factory()->create();

        DedicatedServer::factory()->inDatacenter($datacenter)->create();
        DedicatedServer::factory()->inDatacenter($datacenter)->status(DedicatedServerStatus::Active)->create();
        DedicatedServer::factory()->inDatacenter($datacenter)->retired()->create();

        $this->assertSame(1, DedicatedServer::query()->allocatable()->count());
    }

    #[Test]
    public function a_rendered_install_config_never_stores_a_credential(): void
    {
        $server = DedicatedServer::factory()->create();

        $authorisation = PxeBootAuthorisation::factory()->forServer($server)->create([
            'rendered_config' => [
                'template' => 'a preseed',
                'variables' => [
                    'hostname' => 'ded-01',
                    // A careless caller passing a plain credential. This table
                    // must not become where the platform keeps them.
                    'root_password' => 'hunter2-the-real-thing',
                ],
            ],
        ]);

        $stored = (string) json_encode($authorisation->fresh()?->rendered_config);

        $this->assertStringNotContainsString('hunter2-the-real-thing', $stored);
        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $stored);
        // The rest survives: an operator needs to see what the machine was
        // built with.
        $this->assertStringContainsString('ded-01', $stored);
    }

    #[Test]
    public function an_endpoints_port_falls_back_to_the_protocols_standard_one(): void
    {
        $redfish = BmcEndpoint::factory()->protocol(BmcProtocol::Redfish)->create(['port' => null]);
        $ipmi = BmcEndpoint::factory()->protocol(BmcProtocol::Ipmi)->create(['port' => null]);
        $custom = BmcEndpoint::factory()->protocol(BmcProtocol::Redfish)->create(['port' => 8443]);

        $this->assertSame(443, $redfish->effectivePort());
        $this->assertSame(623, $ipmi->effectivePort());
        $this->assertSame(8443, $custom->effectivePort());
    }

    #[Test]
    public function a_bmc_url_is_always_https(): void
    {
        $endpoint = BmcEndpoint::factory()->at('192.0.2.50')->create(['port' => null]);

        // There is no plaintext option and no switch to add one: the credential
        // this URL carries is a credential for the physical machine.
        $this->assertSame('https://192.0.2.50:443', $endpoint->baseUrl());
    }

    #[Test]
    public function a_reservation_with_no_expiry_is_held_indefinitely(): void
    {
        $server = DedicatedServer::factory()
            ->status(DedicatedServerStatus::Reserved)
            ->create(['reserved_until' => null]);

        // What an operator holding a machine for a named customer wants, and
        // what a reaper must not overrule.
        $this->assertTrue($server->hasLiveReservation());

        $server->forceFill(['reserved_until' => now()->subMinute()])->save();

        $this->assertFalse($server->fresh()?->hasLiveReservation());
    }

    #[Test]
    public function lapsed_authorisations_are_findable_for_a_reaper(): void
    {
        $server = DedicatedServer::factory()->create();

        PxeBootAuthorisation::factory()->forServer($server)->expired()->create();
        PxeBootAuthorisation::factory()->forServer($server)->create();

        // Derived on read rather than stored, the boot server's lookup by MAC
        // and status could not be indexed.
        $this->assertSame(1, PxeBootAuthorisation::query()->lapsed()->count());
    }
}
