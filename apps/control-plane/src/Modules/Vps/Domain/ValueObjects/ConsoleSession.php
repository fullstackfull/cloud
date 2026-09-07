<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A permit to open one console, on one machine, once, within the next minute.
 *
 * What this object is NOT is the important part. It carries no hypervisor
 * ticket, no VNC password, no node address and no cluster credential. The
 * platform mints its own opaque secret, hands that to the browser, and the
 * console gateway exchanges it — server-side, on redemption — for whatever the
 * hypervisor actually wants. A Proxmox vncproxy ticket is a bearer credential
 * for a root console; once it is in a browser it is in the browser's history,
 * in any extension that reads the DOM, and in whatever the customer pastes
 * into a support chat. It never leaves the platform.
 *
 * The token is present only on the object returned by issuing. Redemption
 * returns the same value object with the token stripped, because by then the
 * only interesting facts are which machine and whose.
 *
 * @immutable
 */
final readonly class ConsoleSession
{
    public function __construct(
        public string $id,
        public string $virtualMachineId,
        public string $customerId,
        public ?string $userId,
        public CarbonImmutable $expiresAt,
        /**
         * The single-use secret, in the clear. Populated only at issue time;
         * what is stored is a hash of it, so a dump of the session store is
         * not a set of usable console permits.
         */
        public ?string $token = null,
    ) {}

    public function withoutToken(): self
    {
        return new self($this->id, $this->virtualMachineId, $this->customerId, $this->userId, $this->expiresAt);
    }

    /**
     * Whole seconds left, floored at zero so a client never sees a negative
     * countdown for a session that expired between issue and serialisation.
     */
    public function expiresInSeconds(): int
    {
        return max(0, (int) floor(CarbonImmutable::now()->diffInSeconds($this->expiresAt, absolute: false)));
    }
}
