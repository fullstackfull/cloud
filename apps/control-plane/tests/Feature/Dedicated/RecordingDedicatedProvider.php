<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\DTOs\HardwareHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;

/**
 * A controller that writes down what it was asked to do.
 *
 * The fake provider is a good stand-in for a working machine, but two of the
 * things this module has to get right cannot be observed through it: which
 * physical operation a power `cycle` actually issued, and how many times the
 * platform issued anything after a controller stopped answering. Both are
 * questions about the calls rather than about their effect, so the test needs
 * something that counts them.
 *
 * `$mutationsAttempted` counts only the operations that change the machine.
 * Reads are excluded deliberately: the whole point of the assertion it exists
 * for is that a timed-out reset is never sent twice, and a health or power
 * poll in between is not a second reset.
 */
final class RecordingDedicatedProvider implements DedicatedProvider
{
    /** @var list<string> */
    public array $calls = [];

    public int $mutationsAttempted = 0;

    public function __construct(
        private readonly PowerState $reportedState = PowerState::On,
        private readonly bool $timeOut = false,
    ) {}

    public function protocol(): BmcProtocol
    {
        return BmcProtocol::Redfish;
    }

    public function hardwareHealth(BmcEndpoint $endpoint): HardwareHealth
    {
        $this->calls[] = 'hardware_health';

        return new HardwareHealth(
            overall: ComponentHealth::Ok,
            powerState: $this->reportedState,
        );
    }

    public function powerState(BmcEndpoint $endpoint): PowerState
    {
        $this->calls[] = 'power_state';

        return $this->reportedState;
    }

    public function powerOn(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate($endpoint, 'power_on', PowerState::On);
    }

    public function powerOff(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate($endpoint, 'power_off', PowerState::Off);
    }

    public function gracefulShutdown(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate($endpoint, 'graceful_shutdown', PowerState::Off);
    }

    public function reset(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate($endpoint, 'reset', PowerState::On);
    }

    public function setOneTimePxeBoot(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate($endpoint, 'set_one_time_pxe', null);
    }

    /**
     * @return list<string>
     */
    public function bootOrder(BmcEndpoint $endpoint): array
    {
        $this->calls[] = 'boot_order';

        return ['Hdd', 'Pxe'];
    }

    /**
     * @return list<FirmwareComponent>
     */
    public function firmwareInventory(BmcEndpoint $endpoint): array
    {
        $this->calls[] = 'firmware_inventory';

        return [];
    }

    private function mutate(BmcEndpoint $endpoint, string $operation, ?PowerState $resulting): BmcOperation
    {
        $this->calls[] = $operation;
        $this->mutationsAttempted++;

        if ($this->timeOut) {
            /*
             * Indeterminate, which is the case the module is arranged around:
             * the platform stopped waiting, and the chassis may be acting on
             * the request right now.
             */
            throw DedicatedProviderException::requestFailed(
                'recording',
                $operation,
                ['provider_message' => 'the recording controller stopped answering by design'],
                indeterminate: true,
            );
        }

        return new BmcOperation(
            operation: $operation,
            endpointId: (string) $endpoint->getKey(),
            protocol: BmcProtocol::Redfish,
            resultingPowerState: $resulting,
        );
    }
}
