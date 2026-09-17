<?php

declare(strict_types=1);

namespace Tests\Support;

use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\DTOs\HardwareHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;

/**
 * A controlled BMC that also counts how many times it was told to do something.
 *
 * ---------------------------------------------------------------------------
 * Why counting is the only assertion that works here
 * ---------------------------------------------------------------------------
 *
 * Because two resets leave a chassis in the same state as one. A test that
 * looked at the power state after a duplicate request would find `on` and pass
 * while the machine had been interrupted mid-boot — which is the whole reason
 * the platform's duplicate-power gap survived this long.
 *
 * It is a decorator rather than a second simulator: every answer comes from the
 * real controlled provider, and the only thing added is a tally. Nothing here
 * decides anything, so nothing here can be wrong about the BMC's behaviour.
 */
final class CountingDedicatedProvider implements DedicatedProvider
{
    /** How many state-changing calls this has been given. */
    public int $calls = 0;

    /** @var list<string> the verbs, in order, for a test that cares which */
    public array $operations = [];

    public function __construct(private readonly DedicatedProvider $inner) {}

    public function protocol(): BmcProtocol
    {
        return $this->inner->protocol();
    }

    public function hardwareHealth(BmcEndpoint $endpoint): HardwareHealth
    {
        return $this->inner->hardwareHealth($endpoint);
    }

    public function powerState(BmcEndpoint $endpoint): PowerState
    {
        return $this->inner->powerState($endpoint);
    }

    public function powerOn(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->record('power_on', fn (): BmcOperation => $this->inner->powerOn($endpoint));
    }

    public function powerOff(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->record('power_off', fn (): BmcOperation => $this->inner->powerOff($endpoint));
    }

    public function gracefulShutdown(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->record('graceful_shutdown', fn (): BmcOperation => $this->inner->gracefulShutdown($endpoint));
    }

    public function reset(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->record('reset', fn (): BmcOperation => $this->inner->reset($endpoint));
    }

    public function setOneTimePxeBoot(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->record('set_one_time_pxe', fn (): BmcOperation => $this->inner->setOneTimePxeBoot($endpoint));
    }

    /**
     * @return list<string>
     */
    public function bootOrder(BmcEndpoint $endpoint): array
    {
        return $this->inner->bootOrder($endpoint);
    }

    /**
     * @return list<FirmwareComponent>
     */
    public function firmwareInventory(BmcEndpoint $endpoint): array
    {
        return $this->inner->firmwareInventory($endpoint);
    }

    /**
     * @param  callable(): BmcOperation  $call
     */
    private function record(string $operation, callable $call): BmcOperation
    {
        // Counted before the call, not after: an operation that throws still
        // reached the controller, and a test about duplicates has to see it.
        $this->calls++;
        $this->operations[] = $operation;

        return $call();
    }
}
