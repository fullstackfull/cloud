<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Contracts;

use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\DTOs\HardwareHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;

/**
 * Everything the platform is allowed to ask an out-of-band controller to do.
 *
 * Redfish, iLO and IPMI all sit behind this one interface so that ordering,
 * billing and provisioning contain no vendor branch. A fleet with three
 * generations of hardware in it — and every fleet eventually has three — must
 * not push that fact up into the code that decides whether a customer's server
 * gets built.
 *
 * Four rules bind every implementation:
 *
 *  - failures throw {@see DedicatedProviderException}, never a client or
 *    process exception. An HTTP client exception stringifies the request,
 *    which carries the Authorization header; a process exception stringifies
 *    the command line, which for ipmitool carries the password in argv;
 *
 *  - a timeout is reported as INDETERMINATE, not as a failure. The platform
 *    stopping waiting is not the controller stopping working. A reset that
 *    timed out may have dropped a machine into a network install that is
 *    running right now, and retrying it is how a server is reinstalled while
 *    it is being reinstalled;
 *
 *  - the read methods never mutate. {@see self::hardwareHealth()},
 *    {@see self::powerState()}, {@see self::bootOrder()} and
 *    {@see self::firmwareInventory()} are what discovery runs on a schedule
 *    against the whole fleet, so a side effect on a read path would be a
 *    side effect applied to every machine the platform owns;
 *
 *  - {@see self::setOneTimePxeBoot()} sets a boot override for the NEXT boot
 *    only, and no implementation may reach for a persistent boot-order change
 *    instead. A machine left with PXE first in its boot order reinstalls
 *    itself the next time it reboots for any reason, which is a customer's
 *    entire server erased by a power cut.
 *
 * Methods take the endpoint row rather than an address because the row is what
 * carries the port, the TLS policy and the identity of the machine; adapters
 * are built for one endpoint and verify that the row they are handed is that
 * endpoint, because operating on the wrong physical machine cannot be undone.
 */
interface DedicatedProvider
{
    /**
     * The protocol this adapter speaks. It is compared against
     * bmc_endpoints.protocol when the adapter is resolved, so it must never
     * change for a given class once endpoint rows exist.
     */
    public function protocol(): BmcProtocol;

    /**
     * The machine's own account of its hardware and condition.
     *
     * Read-only, and the single most valuable call in the module: it is how
     * "the server is unreachable" is told apart from "the server is broken",
     * which is the distinction that decides whether an incident is a network
     * problem or a customer's data on a dying disk.
     *
     * @throws DedicatedProviderException
     */
    public function hardwareHealth(BmcEndpoint $endpoint): HardwareHealth;

    /**
     * @throws DedicatedProviderException
     */
    public function powerState(BmcEndpoint $endpoint): PowerState;

    /**
     * @throws DedicatedProviderException
     */
    public function powerOn(BmcEndpoint $endpoint): BmcOperation;

    /**
     * Cut the power. The physical equivalent of pulling the cord: the guest
     * gets no warning and unflushed writes are lost. Use only when the host is
     * unreachable or when the caller has already asked politely.
     *
     * @throws DedicatedProviderException
     */
    public function powerOff(BmcEndpoint $endpoint): BmcOperation;

    /**
     * Ask the operating system to shut down cleanly, via ACPI.
     *
     * Requires a host that is listening. The caller decides how long to wait
     * before escalating to {@see self::powerOff()}; this method does not
     * escalate on its own, because "the machine did not answer in ten seconds"
     * and "the machine should lose power" are not the same judgement.
     *
     * @throws DedicatedProviderException
     */
    public function gracefulShutdown(BmcEndpoint $endpoint): BmcOperation;

    /**
     * Hard reset: the reset line, not a clean reboot.
     *
     * This is what a network install is started with, because a machine that
     * has just had a one-time boot override set has no operating system
     * cooperation to rely on.
     *
     * @throws DedicatedProviderException
     */
    public function reset(BmcEndpoint $endpoint): BmcOperation;

    /**
     * Arm a network boot for the NEXT boot only.
     *
     * One-time, never persistent — see the class docblock. Implementations
     * that cannot express "once" must fail rather than fall back to a
     * persistent change.
     *
     * @throws DedicatedProviderException
     */
    public function setOneTimePxeBoot(BmcEndpoint $endpoint): BmcOperation;

    /**
     * The machine's configured boot order, most preferred first.
     *
     * Read-only, and read specifically to prove a negative: that PXE is NOT
     * first. It is how an operator confirms that a one-time override was
     * consumed rather than left behind.
     *
     * @return list<string>
     *
     * @throws DedicatedProviderException
     */
    public function bootOrder(BmcEndpoint $endpoint): array;

    /**
     * Every firmware image the controller can see, with its version.
     *
     * @return list<FirmwareComponent>
     *
     * @throws DedicatedProviderException
     */
    public function firmwareInventory(BmcEndpoint $endpoint): array;
}
