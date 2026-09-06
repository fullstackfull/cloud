<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Dedicated\Application\Actions\SyncHardwareInventory;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Discovery, which is read-only at the controller and must stay that way.
 *
 * This runs on a schedule across the whole fleet. A mutation reaching this
 * path would be a mutation applied to every machine the platform owns —
 * including every customer's production server — in a single pass, which is
 * why the "only GETs" assertion below is the most valuable one in the file.
 */
final class SyncHardwareInventoryTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'bmc-secret-4Jd9';

    private DedicatedServer $server;

    private BmcEndpoint $endpoint;

    protected function setUp(): void
    {
        parent::setUp();

        // A real adapter, not the fake: the point of this test is what goes
        // onto the wire.
        config()->set('dedicated.provider', 'redfish');
        config()->set('dedicated.credentials.test-bmc', [
            'username' => 'lynomia-svc',
            'password' => self::PASSWORD,
        ]);

        $this->server = DedicatedServer::factory()
            ->status(DedicatedServerStatus::Active)
            ->create(['power_state' => PowerState::Unknown]);

        $this->endpoint = BmcEndpoint::factory()->forServer($this->server)->create([
            'credentials_reference' => 'test-bmc',
        ]);
    }

    #[Test]
    public function it_issues_only_get_requests(): void
    {
        $this->fakeAHealthyMachine();

        app(SyncHardwareInventory::class)->execute($this->server);

        // Stated both ways round. The positive assertion alone would pass if a
        // single POST had slipped in among the GETs.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');

        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            Http::assertNotSent(fn (Request $request): bool => $request->method() === $method);
        }
    }

    #[Test]
    public function it_never_powers_resets_or_reconfigures_the_machine(): void
    {
        $this->fakeAHealthyMachine();

        app(SyncHardwareInventory::class)->execute($this->server);

        // Named explicitly, because these are the three URLs that would turn a
        // fleet-wide discovery sweep into a fleet-wide outage.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ComputerSystem.Reset'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'BootSourceOverride'));
        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'ResetType'));
    }

    #[Test]
    public function it_records_components_and_the_observed_power_state(): void
    {
        $this->fakeAHealthyMachine();

        $result = app(SyncHardwareInventory::class)->execute($this->server);

        $this->assertSame(PowerState::On, $result->powerState);
        $this->assertSame(ComponentHealth::Ok, $result->health);
        $this->assertSame(4, $result->componentsReported);
        $this->assertSame(4, $result->componentsCreated);

        $this->server->refresh();
        $this->assertSame(PowerState::On, $this->server->power_state);
        $this->assertNotNull($this->server->last_seen_at);

        $nic = ServerComponent::query()->where('kind', ComponentKind::Nic->value)->sole();
        $this->assertSame('aa:bb:cc:dd:ee:ff', $nic->macAddress());
    }

    #[Test]
    public function it_never_writes_the_status_column(): void
    {
        $this->fakeAHealthyMachine();

        app(SyncHardwareInventory::class)->execute($this->server);

        /*
         * status is intent — an operator's decision to take a machine out of
         * service, an order's claim on it — and the controller knows nothing
         * about either. A sync that inferred status would return a machine to
         * stock because it answered a ping, with a customer's data still on
         * its disks.
         */
        $this->assertSame(DedicatedServerStatus::Active, $this->server->fresh()?->status);
    }

    #[Test]
    public function a_machine_left_in_maintenance_by_an_operator_is_not_returned_to_service(): void
    {
        $this->server->forceFill(['status' => DedicatedServerStatus::Maintenance])->save();

        $this->fakeAHealthyMachine();

        app(SyncHardwareInventory::class)->execute($this->server);

        $this->assertSame(DedicatedServerStatus::Maintenance, $this->server->fresh()?->status);
    }

    #[Test]
    public function a_component_that_stops_being_reported_is_flagged_and_never_deleted(): void
    {
        $vanishing = ServerComponent::factory()->forServer($this->server)->create([
            'kind' => ComponentKind::Disk,
            'serial' => 'OLDDISK123',
            'health' => ComponentHealth::Ok,
        ]);

        $this->fakeAHealthyMachine();

        $result = app(SyncHardwareInventory::class)->execute($this->server);

        $vanishing->refresh();

        /*
         * A drive that vanished has been pulled or has died. Deleting the row
         * would erase the serial number of the disk a customer's data was on
         * at exactly the moment that serial number became interesting.
         */
        $this->assertNotNull($vanishing->id);
        $this->assertSame('OLDDISK123', $vanishing->serial);
        // Unknown rather than critical: the platform has stopped hearing about
        // it, which is what is true; calling it critical would send an engineer
        // to a machine that may be fine.
        $this->assertSame(ComponentHealth::Unknown, $vanishing->health);
        $this->assertSame(1, $result->componentsMissing);
        $this->assertTrue($result->needsAttention());
    }

    #[Test]
    public function a_second_pass_updates_rather_than_duplicates(): void
    {
        $this->fakeAHealthyMachine();

        app(SyncHardwareInventory::class)->execute($this->server);
        $second = app(SyncHardwareInventory::class)->execute($this->server->fresh() ?? $this->server);

        $this->assertSame(0, $second->componentsCreated);
        $this->assertSame(4, $second->componentsUpdated);
        $this->assertSame(4, ServerComponent::query()->count());
    }

    #[Test]
    public function a_drive_reported_critical_is_recorded_with_its_serial(): void
    {
        $this->fakeAHealthyMachine(driveHealth: 'Critical');

        $result = app(SyncHardwareInventory::class)->execute($this->server);

        $this->assertSame(ComponentHealth::Critical, $result->health);
        $this->assertTrue($result->needsAttention());

        $drive = ServerComponent::query()->where('kind', ComponentKind::Disk->value)->sole();

        // The serial a warranty claim is made against, recorded before the part
        // died rather than after.
        $this->assertSame('PHYS0001', $drive->serial);
        $this->assertSame(ComponentHealth::Critical, $drive->health);
    }

    #[Test]
    public function the_controllers_firmware_version_is_recorded_on_the_endpoint(): void
    {
        $this->fakeAHealthyMachine();

        app(SyncHardwareInventory::class)->execute($this->server);

        $this->endpoint->refresh();

        $this->assertSame('2.78', $this->endpoint->firmware_version);
        $this->assertNotNull($this->endpoint->last_contacted_at);
        $this->assertNull($this->endpoint->last_error);
    }

    #[Test]
    public function a_failing_sync_records_a_redacted_error_on_the_endpoint_and_rethrows(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'authentication failed for password '.self::PASSWORD],
        ], 401)]);

        try {
            app(SyncHardwareInventory::class)->execute($this->server);

            $this->fail('A failing sync was reported as success.');
        } catch (DedicatedProviderException) {
            // Expected: the caller has to know discovery did not run.
        }

        $this->endpoint->refresh();

        $this->assertNotNull($this->endpoint->last_error);
        // An operator has to see the failure on the row without reading logs —
        // and a provider's text can quote the request that carried the
        // credential.
        $this->assertStringNotContainsString(self::PASSWORD, (string) $this->endpoint->last_error);
    }

    /**
     * A controller answering with a complete, healthy machine.
     */
    private function fakeAHealthyMachine(string $driveHealth = 'OK'): void
    {
        Http::fake([
            '*/redfish/v1/Systems/1/EthernetInterfaces' => Http::response([
                'Members' => [['@odata.id' => '/redfish/v1/Systems/1/EthernetInterfaces/1']],
            ]),
            '*/redfish/v1/Systems/1/EthernetInterfaces/1' => Http::response([
                'Id' => '1',
                'Name' => 'NIC 1',
                'MACAddress' => 'AA:BB:CC:DD:EE:FF',
                'Status' => ['Health' => 'OK'],
            ]),
            '*/redfish/v1/Systems/1/Storage/DE00A000/Drives/1' => Http::response([
                'Name' => 'Bay 1',
                'Model' => 'INTEL SSDSC2KB960G8',
                'SerialNumber' => 'PHYS0001',
                'CapacityBytes' => 960197124096,
                'Status' => ['Health' => $driveHealth],
            ]),
            '*/redfish/v1/Systems/1/Storage/DE00A000' => Http::response([
                'Drives' => [['@odata.id' => '/redfish/v1/Systems/1/Storage/DE00A000/Drives/1']],
            ]),
            '*/redfish/v1/Systems/1/Storage' => Http::response([
                'Members' => [['@odata.id' => '/redfish/v1/Systems/1/Storage/DE00A000']],
            ]),
            '*/redfish/v1/UpdateService/FirmwareInventory/1' => Http::response([
                'Id' => 'iLO5',
                'Name' => 'iLO 5',
                'Version' => '2.78',
            ]),
            '*/redfish/v1/UpdateService/FirmwareInventory' => Http::response([
                'Members' => [['@odata.id' => '/redfish/v1/UpdateService/FirmwareInventory/1']],
            ]),
            '*/redfish/v1/Systems/1' => Http::response([
                'PowerState' => 'On',
                'Manufacturer' => 'HPE',
                'Model' => 'ProLiant DL360 Gen10',
                'SerialNumber' => 'CZ1234ABCD',
                'Status' => ['Health' => 'OK'],
                'ProcessorSummary' => ['Count' => 2, 'Model' => 'Xeon Gold 6248', 'Status' => ['HealthRollup' => 'OK']],
                'MemorySummary' => ['TotalSystemMemoryGiB' => 384, 'Status' => ['HealthRollup' => 'OK']],
            ]),
        ]);
    }
}
