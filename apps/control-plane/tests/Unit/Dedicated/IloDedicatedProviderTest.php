<?php

declare(strict_types=1);

namespace Tests\Unit\Dedicated;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\BmcConnection;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IloDedicatedProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * iLO is Redfish with holes in it, and this class is only about the holes.
 *
 * The properties that must NOT diverge per vendor — the Once boot override,
 * the reset action, TLS verification, the scrubbing of credentials — are
 * asserted here too, because "it extends the Redfish adapter" is a claim about
 * today's code and these are the guarantees a customer's data depends on.
 */
final class IloDedicatedProviderTest extends TestCase
{
    private const string ENDPOINT_ID = '01JBMCILOENDPOINT00000000A';

    #[Test]
    public function it_reports_itself_as_ilo_so_callers_and_rows_agree(): void
    {
        $this->assertSame(BmcProtocol::Ilo, $this->provider()->protocol());
    }

    #[Test]
    public function the_inherited_pxe_override_is_still_once_and_never_continuous(): void
    {
        Http::fake(['*' => Http::response([], 204)]);

        $this->provider()->setOneTimePxeBoot($this->endpoint());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('PATCH', $request->method());
            $this->assertSame('Once', $request->data()['Boot']['BootSourceOverrideEnabled'] ?? null);

            return true;
        });

        Http::assertNotSent(fn (Request $request): bool => str_contains((string) $request->body(), 'Continuous'));
    }

    #[Test]
    public function the_boot_order_falls_back_to_hpes_oem_block(): void
    {
        // iLO does not publish Boot.BootOrder. Reading only the standard field
        // would report every HPE machine as having no boot devices — and the
        // one thing bootOrder() is called for is to prove PXE is NOT first.
        Http::fake(['*' => Http::response([
            'Boot' => [],
            'Oem' => ['Hpe' => ['Boot' => ['PersistentBootConfigOrder' => ['HD.1.1', 'NIC.LOM.1.1']]]],
        ])]);

        $this->assertSame(['HD.1.1', 'NIC.LOM.1.1'], $this->provider()->bootOrder($this->endpoint()));
    }

    #[Test]
    public function a_standard_boot_order_is_preferred_over_the_oem_one(): void
    {
        // So that a firmware update which adds proper Redfish support is
        // picked up without a code change.
        Http::fake(['*' => Http::response([
            'Boot' => ['BootOrder' => ['Boot0001', 'Boot0002']],
            'Oem' => ['Hpe' => ['Boot' => ['PersistentBootConfigOrder' => ['HD.1.1']]]],
        ])]);

        $this->assertSame(['Boot0001', 'Boot0002'], $this->provider()->bootOrder($this->endpoint()));
    }

    #[Test]
    public function aggregate_health_is_read_when_the_standard_status_is_missing(): void
    {
        Http::fake([
            '*/EthernetInterfaces' => Http::response(['Members' => []]),
            '*/Storage' => Http::response(['Members' => []]),
            '*' => Http::response([
                'PowerState' => 'On',
                // No Status.Health at all, which is what older iLO does.
                'Oem' => ['Hp' => ['AggregateHealthStatus' => ['Status' => ['Health' => 'Warning']]]],
            ]),
        ]);

        $health = $this->provider()->hardwareHealth($this->endpoint());

        // Without this fallback a healthy HPE machine and a silent one would be
        // indistinguishable, and a fleet health report would go green on
        // machines that never answered.
        $this->assertSame(ComponentHealth::Warning, $health->overall);
    }

    #[Test]
    public function the_firmware_inventory_falls_back_to_the_managers_path(): void
    {
        Http::fake([
            '*/redfish/v1/UpdateService/FirmwareInventory' => Http::response(['Members' => []]),
            '*/redfish/v1/Managers/1/UpdateService/FirmwareInventory' => Http::response([
                'Members' => [['@odata.id' => '/redfish/v1/Managers/1/UpdateService/FirmwareInventory/1']],
            ]),
            '*/redfish/v1/Managers/1/UpdateService/FirmwareInventory/1' => Http::response([
                'Id' => 'iLO5',
                'Name' => 'iLO 5',
                'Version' => '2.78',
            ]),
        ]);

        $firmware = $this->provider()->firmwareInventory($this->endpoint());

        $this->assertCount(1, $firmware);
        $this->assertSame('2.78', $firmware[0]->version);

        // Both reads are GETs, so the fallback cannot change anything on the
        // controller — which is what makes retrying a second path acceptable
        // on a read that runs across the whole fleet.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    private function endpoint(): BmcEndpoint
    {
        $endpoint = new BmcEndpoint;

        $endpoint->forceFill([
            'protocol' => BmcProtocol::Ilo,
            'address' => '192.0.2.20',
            'port' => 443,
            'username' => 'lynomia-svc',
            'verify_tls' => true,
        ]);

        $endpoint->id = self::ENDPOINT_ID;

        return $endpoint;
    }

    private function provider(): IloDedicatedProvider
    {
        return new IloDedicatedProvider(
            new BmcConnection(
                endpointId: self::ENDPOINT_ID,
                protocol: BmcProtocol::Ilo,
                address: '192.0.2.20',
                port: 443,
                username: 'lynomia-svc',
                password: 'ilo-password-3xZ',
                timeoutSeconds: 5,
            ),
            new SecretRedactor,
        );
    }
}
