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

    /**
     * @param  list<string>  $failFrom  Operations this controller refuses out loud, by name.
     * @param  list<string>  $goQuietFrom  Operations after which it stops answering.
     */
    public function __construct(
        private readonly PowerState $reportedState = PowerState::On,
        private readonly bool $timeOut = false,
        /*
         * A controller that works for one operation and not the next.
         *
         * The reinstall path needs exactly this: arming a one-time boot
         * override succeeds and the power cycle that would consume it does
         * not, which is the case that decides whether a machine is left primed
         * to erase itself at its next reboot. `$timeOut` cannot express it,
         * because it fails everything from the first call.
         */
        private readonly array $failFrom = [],
        private readonly array $goQuietFrom = [],
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

        if (in_array($operation, $this->failFrom, strict: true)) {
            // Answered and declined. Nothing physical happened, and the caller
            // is expected to undo whatever it had already armed.
            throw DedicatedProviderException::requestFailed(
                'recording',
                $operation,
                ['provider_message' => 'the recording controller refused this operation by design'],
            );
        }

        if ($this->timeOut || in_array($operation, $this->goQuietFrom, strict: true)) {
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
