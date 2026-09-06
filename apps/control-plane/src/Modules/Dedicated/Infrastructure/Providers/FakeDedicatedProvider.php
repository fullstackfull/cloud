<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Providers;

use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\DTOs\ComponentReading;
use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\DTOs\HardwareHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Domain\Services\FakeDedicatedProviderGuard;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;

/**
 * A controller that touches no hardware and reaches no network.
 *
 * Its behaviour is a pure function of the endpoint it is asked about, which is
 * what makes the failure paths testable without fixtures, stubs or randomness:
 * an address carrying a marker selects an outcome, so a test asks about
 * "10.0.0.5-provider-fail" to exercise the refusal branch and
 * "10.0.0.5-timeout" to exercise the one where the platform stopped waiting
 * and does not know what the controller did.
 *
 * Three decisions are worth stating:
 *
 *  - **the MAC address is derived from the endpoint id**, not random. A PXE
 *    authorisation is granted to a MAC, so a fake that produced a new address
 *    per call would make every provisioning test authorise a machine that then
 *    could not boot;
 *
 *  - **power state is remembered per endpoint**, so a test can power a machine
 *    off and observe that it is off. A fake that always answered "On" would
 *    make the provisioning handler's decision between powerOn() and reset()
 *    untestable, and that decision is the one that either starts an install or
 *    interrupts one;
 *
 *  - **it refuses to exist in production.** It reports hardware as healthy and
 *    accepts power operations without contacting anything, so in production it
 *    would mark servers active and hand over credentials for machines that
 *    were never installed — and, worse in the other direction, tell an
 *    operator that a failing disk is fine.
 */
final class FakeDedicatedProvider implements DedicatedProvider
{
    public const string NAME = 'fake';

    /** An address carrying this is refused outright, as an unreachable controller would be. */
    public const string PROVIDER_FAILURE_MARKER = 'provider-fail';

    /**
     * An address carrying this times out: the call fails with the outcome at
     * the controller unknown, which is the state the platform must never
     * resolve by retrying. Nothing is recorded as changed, deliberately — that
     * is what makes the marker useful, because the caller cannot tell and must
     * behave correctly anyway.
     */
    public const string TIMEOUT_MARKER = 'timeout';

    /** An address carrying this reports a failing disk rather than a healthy machine. */
    public const string UNHEALTHY_MARKER = 'unhealthy';

    /**
     * Power state per endpoint id.
     *
     * @var array<string, PowerState>
     */
    private array $power = [];

    /**
     * Whether a one-time PXE override is currently armed, per endpoint id.
     *
     * @var array<string, bool>
     */
    private array $pxeArmed = [];

    public function __construct(
        private readonly BmcProtocol $protocol = BmcProtocol::Redfish,
    ) {
        // Constructed, not resolved, is the moment worth guarding: a container
        // binding overridden at runtime and a factory branch taken from
        // configuration both avoid a boot-time check, and neither can avoid
        // this constructor.
        FakeDedicatedProviderGuard::assertNotProduction(self::NAME);
    }

    /**
     * The protocol this fake is standing in for.
     *
     * Reported honestly rather than as a "fake" protocol, because callers
     * branch on it and a test of the iLO path must be able to see iLO.
     */
    public function protocol(): BmcProtocol
    {
        return $this->protocol;
    }

    public function hardwareHealth(BmcEndpoint $endpoint): HardwareHealth
    {
        $this->assertReachable($endpoint, 'hardware_health');

        $unhealthy = self::addressCarries($endpoint, self::UNHEALTHY_MARKER);

        return new HardwareHealth(
            overall: $unhealthy ? ComponentHealth::Critical : ComponentHealth::Ok,
            powerState: $this->currentPower($endpoint),
            manufacturer: 'Lynomia',
            model: 'FAKE-1U',
            serialNumber: 'FAKE-'.strtoupper(substr((string) $endpoint->getKey(), -8)),
            biosVersion: '1.0.0',
            components: [
                new ComponentReading(
                    kind: ComponentKind::Cpu,
                    name: 'Fake Xeon',
                    health: ComponentHealth::Ok,
                    model: 'Fake Xeon',
                    quantity: 2,
                ),
                new ComponentReading(
                    kind: ComponentKind::Memory,
                    name: 'System memory',
                    health: ComponentHealth::Ok,
                    attributes: ['total_gib' => 128],
                ),
                new ComponentReading(
                    kind: ComponentKind::Disk,
                    name: 'Bay 1',
                    // The one component that changes with the marker, because a
                    // dead disk is the failure the module actually has to
                    // behave correctly about.
                    health: $unhealthy ? ComponentHealth::Critical : ComponentHealth::Ok,
                    model: 'FAKE-SSD',
                    serial: 'FAKEDISK1',
                    attributes: ['capacity_bytes' => 960197124096, 'media_type' => 'SSD'],
                ),
                new ComponentReading(
                    kind: ComponentKind::Nic,
                    name: 'Provisioning NIC',
                    health: ComponentHealth::Ok,
                    attributes: [
                        'mac_address' => self::macAddressFor($endpoint),
                        'pxe_enabled' => true,
                        'speed_mbps' => 10000,
                    ],
                ),
            ],
            raw: ['fake' => true],
        );
    }

    public function powerState(BmcEndpoint $endpoint): PowerState
    {
        $this->assertReachable($endpoint, 'power_state');

        return $this->currentPower($endpoint);
    }

    public function powerOn(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->setPower($endpoint, PowerState::On, 'power_on');
    }

    public function powerOff(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->setPower($endpoint, PowerState::Off, 'power_off');
    }

    public function gracefulShutdown(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->setPower($endpoint, PowerState::Off, 'graceful_shutdown');
    }

    public function reset(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->setPower($endpoint, PowerState::On, 'reset');
    }

    public function setOneTimePxeBoot(BmcEndpoint $endpoint): BmcOperation
    {
        $this->assertReachable($endpoint, 'set_one_time_pxe');

        $this->pxeArmed[(string) $endpoint->getKey()] = true;

        return new BmcOperation(
            operation: 'set_one_time_pxe',
            endpointId: (string) $endpoint->getKey(),
            protocol: $this->protocol,
            metadata: [
                'boot_source_override_target' => 'Pxe',
                // Stated in the metadata exactly as the real adapter does, so a
                // test that asserts the platform never arms a persistent boot
                // can assert it against the fake too.
                'boot_source_override_enabled' => 'Once',
            ],
        );
    }

    public function bootOrder(BmcEndpoint $endpoint): array
    {
        $this->assertReachable($endpoint, 'boot_order');

        // PXE appears first only while an override is armed, and the override
        // is consumed by the next reset — which is what "one-time" means and
        // what a test asserting the machine does not reinstall itself checks.
        return $this->pxeArmed[(string) $endpoint->getKey()] ?? false
            ? ['Pxe', 'Hdd', 'Cd']
            : ['Hdd', 'Pxe', 'Cd'];
    }

    public function firmwareInventory(BmcEndpoint $endpoint): array
    {
        $this->assertReachable($endpoint, 'firmware_inventory');

        return [
            new FirmwareComponent('bmc', 'Baseboard management controller', '2.60', true, 'Lynomia'),
            new FirmwareComponent('bios', 'System BIOS', 'U30 v2.62', true, 'Lynomia'),
        ];
    }

    /**
     * Whether a one-time PXE override is currently armed, for tests that need
     * to prove it was consumed rather than left behind.
     */
    public function isPxeArmed(BmcEndpoint $endpoint): bool
    {
        return $this->pxeArmed[(string) $endpoint->getKey()] ?? false;
    }

    /**
     * The address a test should give an endpoint to make this provider behave
     * a particular way, so tests state their intent instead of embedding a
     * magic string.
     */
    public static function addressWith(string $base, string $marker): string
    {
        return $base.'-'.$marker;
    }

    private function setPower(BmcEndpoint $endpoint, PowerState $state, string $operation): BmcOperation
    {
        $this->assertReachable($endpoint, $operation);

        $key = (string) $endpoint->getKey();
        $this->power[$key] = $state;

        // A reset consumes the one-time override, exactly as real firmware
        // does. Without this the fake would report PXE first for ever and a
        // test could not tell a one-time override from a persistent one.
        if ($operation === 'reset' || $operation === 'power_on') {
            $this->pxeArmed[$key] = false;
        }

        return new BmcOperation(
            operation: $operation,
            endpointId: $key,
            protocol: $this->protocol,
            resultingPowerState: $state,
            metadata: ['fake' => true],
        );
    }

    private function currentPower(BmcEndpoint $endpoint): PowerState
    {
        // Machines are off until something turns them on, which is the state a
        // freshly racked server is actually in.
        return $this->power[(string) $endpoint->getKey()] ?? PowerState::Off;
    }

    /**
     * @throws DedicatedProviderException
     */
    private function assertReachable(BmcEndpoint $endpoint, string $operation): void
    {
        if (self::addressCarries($endpoint, self::TIMEOUT_MARKER)) {
            throw DedicatedProviderException::requestFailed(self::NAME, $operation, [
                'address' => $endpoint->address,
                'provider_message' => 'the fake BMC timed out on this address by design',
            ], indeterminate: true);
        }

        if (self::addressCarries($endpoint, self::PROVIDER_FAILURE_MARKER)) {
            throw DedicatedProviderException::requestFailed(self::NAME, $operation, [
                'address' => $endpoint->address,
                'provider_message' => 'the fake BMC refused this address by design',
            ]);
        }
    }

    private static function addressCarries(BmcEndpoint $endpoint, string $marker): bool
    {
        return str_contains(strtolower($endpoint->address), $marker);
    }

    /**
     * A stable MAC in the locally-administered range, derived from the
     * endpoint id so that the same machine always has the same address.
     */
    private static function macAddressFor(BmcEndpoint $endpoint): string
    {
        $digest = substr(md5((string) $endpoint->getKey()), 0, 10);

        // 02: locally administered, unicast. Using a real vendor prefix in a
        // fake would put an address on a test network that collides with
        // hardware somebody owns.
        return strtolower('02:'.implode(':', str_split($digest, 2)));
    }
}
