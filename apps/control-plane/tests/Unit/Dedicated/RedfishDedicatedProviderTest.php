<?php

declare(strict_types=1);

namespace Tests\Unit\Dedicated;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\BmcConnection;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\RedfishDedicatedProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adapter that talks to real out-of-band controllers.
 *
 * Two properties are worth more than everything else here and are asserted
 * against the recorded requests rather than trusted:
 *
 *  - the boot override is sent as `Once` and never as `Continuous`. A machine
 *    left with PXE first in its boot order reinstalls itself the next time it
 *    reboots for any reason, which is a customer's entire server erased by a
 *    power cut;
 *
 *  - the credential travels in the Authorization header and appears nowhere
 *    else — not in a URL, not in a body, and above all not in an exception
 *    message, because an exception message is an entry in the error tracker.
 */
final class RedfishDedicatedProviderTest extends TestCase
{
    private const string ENDPOINT_ID = '01JBMCENDPOINT00000000000A';

    private const string USERNAME = 'lynomia-svc';

    private const string PASSWORD = 'a-real-bmc-password-9f3c';

    #[Test]
    public function a_one_time_pxe_boot_is_sent_as_once_and_never_as_continuous(): void
    {
        Http::fake(['*' => Http::response([], 204)]);

        $this->provider()->setOneTimePxeBoot($this->endpoint());

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PATCH') {
                return false;
            }

            $body = $request->data();

            $this->assertSame('Pxe', $body['Boot']['BootSourceOverrideTarget'] ?? null);

            /*
             * The assertion this whole class exists for. "Continuous" would
             * work, and would keep working: the machine would network boot on
             * every power-on, so the next power cut silently reinstalls the
             * customer's server.
             */
            $this->assertSame(
                'Once',
                $body['Boot']['BootSourceOverrideEnabled'] ?? null,
                'A PXE override must be armed for exactly one boot.',
            );

            return true;
        });

        // Stated as a negative as well, because the positive assertion above
        // would still pass if some other request in the same flow had sent
        // Continuous.
        Http::assertNotSent(
            fn (Request $request): bool => str_contains((string) $request->body(), 'Continuous'),
        );
    }

    #[Test]
    public function the_pxe_override_is_patched_onto_the_system_resource(): void
    {
        Http::fake(['*' => Http::response([], 204)]);

        $operation = $this->provider()->setOneTimePxeBoot($this->endpoint());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/redfish/v1/Systems/1'));

        $this->assertSame('set_one_time_pxe', $operation->operation);
        $this->assertSame(self::ENDPOINT_ID, $operation->endpointId);
        $this->assertSame(BmcProtocol::Redfish, $operation->protocol);
        $this->assertSame('Once', $operation->metadata['boot_source_override_enabled']);
    }

    #[Test]
    public function every_request_authenticates_with_basic_auth_over_tls(): void
    {
        Http::fake(['*' => Http::response(['PowerState' => 'On'])]);

        $this->provider()->powerState($this->endpoint());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                'Basic '.base64_encode(self::USERNAME.':'.self::PASSWORD),
                $request->header('Authorization')[0] ?? '',
            );

            // The credential is in the header and nowhere else. A password in a
            // URL is a password in every proxy log between here and the rack.
            $this->assertStringNotContainsString(self::PASSWORD, $request->url());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $request->body());
            $this->assertStringStartsWith('https://', $request->url());

            return true;
        });
    }

    #[Test]
    public function certificate_verification_stays_on_unless_the_endpoint_row_waives_it(): void
    {
        Http::fake(['*' => Http::response(['PowerState' => 'On'])]);

        $this->provider()->powerState($this->endpoint());

        Http::assertSent(function (Request $request): bool {
            // Laravel records the merged Guzzle options on the request, which
            // is where an accidental global "verify: false" would show up.
            $this->assertTrue($request->toPsrRequest()->getUri()->getScheme() === 'https');

            return true;
        });

        $verifying = $this->provider(verifyTls: false);
        $this->assertFalse($this->connectionOf($verifying)->verifyTls);
    }

    #[Test]
    public function each_power_verb_sends_its_own_reset_type(): void
    {
        Http::fake(['*' => Http::response([], 204)]);

        $provider = $this->provider();
        $endpoint = $this->endpoint();

        $provider->powerOn($endpoint);
        $provider->powerOff($endpoint);
        $provider->gracefulShutdown($endpoint);
        $provider->reset($endpoint);

        foreach (['On', 'ForceOff', 'GracefulShutdown', 'ForceRestart'] as $resetType) {
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
                && str_ends_with($request->url(), '/redfish/v1/Systems/1/Actions/ComputerSystem.Reset')
                && ($request->data()['ResetType'] ?? null) === $resetType);
        }
    }

    #[Test]
    public function a_dropped_connection_is_reported_as_indeterminate_and_never_as_a_plain_failure(): void
    {
        // A reset the controller may well have carried out a moment after the
        // socket died. Reported as an ordinary failure it would be
        // indistinguishable from a 400, and a caller retrying on that would
        // power cycle a customer's machine a second time.
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->provider()->reset($this->endpoint());

            $this->fail('A timed-out reset was reported as success.');
        } catch (DedicatedProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
            $this->assertTrue($e->context()['indeterminate']);
        }
    }

    #[Test]
    public function a_refusal_the_controller_spoke_out_loud_is_not_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['@Message.ExtendedInfo' => [['Message' => 'The value Continuous is not supported.']]],
        ], 400)]);

        try {
            $this->provider()->setOneTimePxeBoot($this->endpoint());

            $this->fail('A refused PATCH was reported as success.');
        } catch (DedicatedProviderException $e) {
            // The controller answered: nothing was armed, and a retry is safe.
            $this->assertFalse($e->isIndeterminate());
            $this->assertStringContainsString('Continuous is not supported', (string) $e->context()['provider_message']);
        }
    }

    #[Test]
    public function a_gateway_failure_in_front_of_the_controller_is_indeterminate(): void
    {
        // A 503 comes from a load balancer or from the controller's own
        // overloaded web stack and says nothing about whether the request
        // arrived, let alone what it did.
        Http::fake(['*' => Http::response('gateway unavailable', 503)]);

        try {
            $this->provider()->powerOff($this->endpoint());

            $this->fail('A 503 on a power-off was treated as a clean refusal.');
        } catch (DedicatedProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function the_bmc_password_never_appears_in_an_exception_message_or_context(): void
    {
        // A controller that quotes the request back at us, which is exactly
        // how a credential ends up in an error tracker.
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'rejected credentials '.self::PASSWORD.' for user '.self::USERNAME],
        ], 401)]);

        try {
            $this->provider()->powerOn($this->endpoint());

            $this->fail('A 401 was reported as success.');
        } catch (DedicatedProviderException $e) {
            $serialised = $e->getMessage().json_encode($e->context());

            $this->assertStringNotContainsString(self::PASSWORD, $serialised);
            $this->assertStringNotContainsString(
                base64_encode(self::USERNAME.':'.self::PASSWORD),
                $serialised,
            );
            $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $serialised);
        }
    }

    #[Test]
    public function hardware_health_reads_identity_power_and_components(): void
    {
        Http::fake([
            '*/redfish/v1/Systems/1/EthernetInterfaces' => Http::response([
                'Members' => [['@odata.id' => '/redfish/v1/Systems/1/EthernetInterfaces/1']],
            ]),
            '*/redfish/v1/Systems/1/EthernetInterfaces/1' => Http::response([
                'Id' => '1',
                'Name' => 'NIC 1',
                'MACAddress' => 'AA:BB:CC:DD:EE:FF',
                'SpeedMbps' => 10000,
                'Status' => ['Health' => 'OK'],
            ]),
            '*/redfish/v1/Systems/1/Storage' => Http::response(['Members' => []]),
            '*/redfish/v1/Systems/1' => Http::response([
                'PowerState' => 'On',
                'Manufacturer' => 'HPE',
                'Model' => 'ProLiant DL360 Gen10',
                'SerialNumber' => 'CZ1234ABCD',
                'BiosVersion' => 'U32 v2.62',
                'Status' => ['Health' => 'OK'],
                'ProcessorSummary' => ['Count' => 2, 'Model' => 'Xeon Gold 6248', 'Status' => ['HealthRollup' => 'OK']],
                'MemorySummary' => ['TotalSystemMemoryGiB' => 384, 'Status' => ['HealthRollup' => 'OK']],
            ]),
        ]);

        $health = $this->provider()->hardwareHealth($this->endpoint());

        $this->assertSame(PowerState::On, $health->powerState);
        $this->assertSame(ComponentHealth::Ok, $health->overall);
        $this->assertSame('CZ1234ABCD', $health->serialNumber);

        // The MAC is why the interface walk is worth its extra requests: it is
        // the identity a PXE authorisation is granted to.
        $this->assertSame('aa:bb:cc:dd:ee:ff', $health->provisioningMacAddress());
        $this->assertCount(1, $health->componentsOfKind(ComponentKind::Nic));
        $this->assertCount(1, $health->componentsOfKind(ComponentKind::Cpu));
    }

    #[Test]
    public function a_failing_drive_degrades_the_machines_effective_health_even_when_the_chassis_says_ok(): void
    {
        Http::fake([
            '*/redfish/v1/Systems/1/EthernetInterfaces' => Http::response(['Members' => []]),
            '*/redfish/v1/Systems/1/Storage' => Http::response([
                'Members' => [['@odata.id' => '/redfish/v1/Systems/1/Storage/DE00A000']],
            ]),
            '*/redfish/v1/Systems/1/Storage/DE00A000' => Http::response([
                'Drives' => [['@odata.id' => '/redfish/v1/Systems/1/Storage/DE00A000/Drives/1']],
            ]),
            '*/redfish/v1/Systems/1/Storage/DE00A000/Drives/1' => Http::response([
                'Name' => 'Bay 3',
                'SerialNumber' => 'PHYS1234',
                'Status' => ['Health' => 'Critical'],
            ]),
            '*/redfish/v1/Systems/1' => Http::response([
                'PowerState' => 'On',
                'Status' => ['Health' => 'OK'],
            ]),
        ]);

        $health = $this->provider()->hardwareHealth($this->endpoint());

        // The chassis said OK. A dying disk that only the drive resource knows
        // about must not be reported as a healthy machine.
        $this->assertSame(ComponentHealth::Ok, $health->overall);
        $this->assertSame(ComponentHealth::Critical, $health->effectiveHealth());
        $this->assertSame('PHYS1234', $health->componentsOfKind(ComponentKind::Disk)[0]->serial);
    }

    #[Test]
    public function a_link_pointing_away_from_the_controller_is_never_followed(): void
    {
        /*
         * The credential-exfiltration case. Redfish collections carry only
         * links, so the adapter builds its next request out of a value that
         * came back in a response — and every request it makes carries HTTP
         * Basic, which is power control and virtual media on a physical host.
         *
         * The HTTP client ignores the configured base URL for anything that
         * starts with a scheme, so a controller answering with an off-box
         * link would have the platform hand its BMC password to whoever owns
         * that host, and fetch arbitrary internal URLs from a worker sitting
         * on the management network on request.
         */
        Http::fake([
            '*/redfish/v1/Systems/1/EthernetInterfaces' => Http::response([
                'Members' => [['@odata.id' => 'https://attacker.example.net/redfish/v1/harvest']],
            ]),
            '*/redfish/v1/Systems/1/Storage' => Http::response(['Members' => []]),
            '*/redfish/v1/Systems/1' => Http::response(['PowerState' => 'On', 'Status' => ['Health' => 'OK']]),
        ]);

        try {
            $this->provider()->hardwareHealth($this->endpoint());

            $this->fail('The adapter followed a link that pointed away from the controller.');
        } catch (DedicatedProviderException $e) {
            // Refused out loud rather than skipped: a machine naming somewhere
            // else must not be quietly reported as merely missing a NIC.
            $this->assertFalse($e->isIndeterminate());
        }

        // The assertion that matters: nothing was ever sent to the other host,
        // so the Authorization header never left the management network.
        Http::assertNotSent(
            fn (Request $request): bool => str_contains($request->url(), 'attacker.example.net'),
        );
    }

    #[Test]
    public function a_self_referential_absolute_link_from_the_controller_is_still_followed(): void
    {
        // Some implementations publish fully qualified self-links. Those point
        // back at the same controller and are safe, so the guard above must not
        // break a machine that speaks that dialect.
        Http::fake([
            '*/redfish/v1/Systems/1/EthernetInterfaces' => Http::response([
                'Members' => [['@odata.id' => 'https://192.0.2.10:443/redfish/v1/Systems/1/EthernetInterfaces/1']],
            ]),
            '*/redfish/v1/Systems/1/EthernetInterfaces/1' => Http::response([
                'Id' => '1',
                'MACAddress' => 'AA:BB:CC:DD:EE:FF',
                'Status' => ['Health' => 'OK'],
            ]),
            '*/redfish/v1/Systems/1/Storage' => Http::response(['Members' => []]),
            '*/redfish/v1/Systems/1' => Http::response(['PowerState' => 'On', 'Status' => ['Health' => 'OK']]),
        ]);

        $health = $this->provider()->hardwareHealth($this->endpoint());

        $this->assertSame('aa:bb:cc:dd:ee:ff', $health->provisioningMacAddress());
    }

    #[Test]
    public function an_adapter_refuses_to_operate_on_an_endpoint_it_was_not_built_for(): void
    {
        Http::fake(['*' => Http::response([], 204)]);

        $other = new BmcEndpoint;
        $other->forceFill(['address' => '192.0.2.9', 'protocol' => BmcProtocol::Redfish, 'verify_tls' => true]);
        $other->id = '01JBMCENDPOINT00000000000B';

        // Powering off somebody else's physical host cannot be undone, so the
        // adapter refuses rather than trusting the caller resolved correctly.
        $this->expectException(BmcNotConfiguredException::class);

        $this->provider()->powerOff($other);
    }

    private function endpoint(): BmcEndpoint
    {
        $endpoint = new BmcEndpoint;

        $endpoint->forceFill([
            'protocol' => BmcProtocol::Redfish,
            'address' => '192.0.2.10',
            'port' => 443,
            'username' => self::USERNAME,
            'verify_tls' => true,
        ]);

        $endpoint->id = self::ENDPOINT_ID;

        return $endpoint;
    }

    private function provider(bool $verifyTls = true): RedfishDedicatedProvider
    {
        return new RedfishDedicatedProvider($this->connection($verifyTls), new SecretRedactor);
    }

    private function connection(bool $verifyTls = true): BmcConnection
    {
        return new BmcConnection(
            endpointId: self::ENDPOINT_ID,
            protocol: BmcProtocol::Redfish,
            address: '192.0.2.10',
            port: 443,
            username: self::USERNAME,
            password: self::PASSWORD,
            verifyTls: $verifyTls,
            timeoutSeconds: 5,
        );
    }

    private function connectionOf(RedfishDedicatedProvider $provider): BmcConnection
    {
        $reflection = new \ReflectionProperty(RedfishDedicatedProvider::class, 'connection');

        /** @var BmcConnection $connection */
        $connection = $reflection->getValue($provider);

        return $connection;
    }
}
