<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\DTOs\HardwareHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;

/**
 * A controlled BMC with something happening while it is being talked to.
 *
 * The window this exists for is the one between a power request's claim and
 * its settle: the claim is committed before the controller is called, and the
 * outcome is written after it answers. Two things can happen inside that
 * window and neither can be staged from outside it:
 *
 *  - the process dies — a deploy, an OOM kill, a SIGKILL mid-call — and the
 *    settle never runs. `$duringCall` throws, which is what that looks like
 *    from the row's side: the claim is there and nothing ever came back for it.
 *  - time passes and the rest of the platform moves on without this request:
 *    the lease lapses, the sweep runs, and then the controller answers after
 *    all. `$duringCall` moves the clock and runs the sweep, then returns.
 *
 * Every answer comes from the inner provider, and every state-changing call is
 * counted before the hook runs — a call that dies still reached the chassis,
 * and a test about a second intent has to see the first one.
 */
final class InterruptibleDedicatedProvider implements DedicatedProvider
{
    /** How many state-changing calls this has been given. */
    public int $mutations = 0;

    /**
     * Run inside the next state-changing call, once, before the controller
     * answers. Cleared as it runs, so a retry is not interrupted again.
     *
     * @var (Closure(): void)|null
     */
    public ?Closure $duringCall = null;

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
        return $this->mutate(fn (): BmcOperation => $this->inner->powerOn($endpoint));
    }

    public function powerOff(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate(fn (): BmcOperation => $this->inner->powerOff($endpoint));
    }

    public function gracefulShutdown(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate(fn (): BmcOperation => $this->inner->gracefulShutdown($endpoint));
    }

    public function reset(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate(fn (): BmcOperation => $this->inner->reset($endpoint));
    }

    public function setOneTimePxeBoot(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->mutate(fn (): BmcOperation => $this->inner->setOneTimePxeBoot($endpoint));
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
    private function mutate(callable $call): BmcOperation
    {
        $this->mutations++;

        $hook = $this->duringCall;
        $this->duringCall = null;

        if ($hook !== null) {
            $hook();
        }

        return $call();
    }
}
