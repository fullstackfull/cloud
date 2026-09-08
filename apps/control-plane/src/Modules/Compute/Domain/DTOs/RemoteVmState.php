<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;

/**
 * The hypervisor's own view of one machine.
 *
 * This is what reconciliation compares the local row against, so it carries
 * the shape as well as the power state: a machine an operator resized by hand
 * is drift the platform has to notice, because the customer is still being
 * billed for what they bought.
 *
 * @immutable
 */
final readonly class RemoteVmState
{
    /**
     * @param  array<string, mixed>  $raw  Redacted provider payload, kept for operator diagnosis.
     */
    public function __construct(
        public string $providerId,
        public string $nodeName,
        public ?string $name,
        public PowerState $powerState,
        public ?int $vcpu = null,
        public ?int $memoryMib = null,
        public ?int $diskGib = null,
        public ?int $uptimeSeconds = null,
        /**
         * The provider's own config lock, when it has one.
         *
         * Present so that suspension can be *verified* rather than assumed. A
         * platform that suspended a machine and then trusted its own row would
         * have no way to notice a lock somebody cleared by hand, which is
         * exactly the drift the reconciler exists to find.
         */
        public ?string $lock = null,
        /**
         * Whether the provider will start this machine when its node reboots.
         *
         * Null when the provider does not report it. Suspension has to clear
         * this, or it survives only until the next maintenance window and the
         * customer's unpaid server quietly comes back.
         */
        public ?bool $startsOnBoot = null,
        public array $raw = [],
    ) {}

    /**
     * Whether the provider is currently refusing to start this machine.
     *
     * Any lock counts, not only the platform's own. A machine locked by a
     * running backup is equally not startable, and reporting it as
     * unsuspended would have the reconciler fight the backup.
     */
    public function isLockedAtProvider(): bool
    {
        return $this->lock !== null && $this->lock !== '';
    }

    /**
     * Whether *this platform* is the reason the machine is locked.
     *
     * The narrower question, and the one reconciliation needs: a machine
     * locked by a running backup is not a suspended machine, and a suspended
     * service whose machine carries somebody else's lock is still a suspension
     * that was never applied.
     */
    public function isSuspendedByPlatform(): bool
    {
        return $this->lock === SuspensionPolicy::LOCK_NAME;
    }

    /**
     * The same machine, powered differently.
     *
     * These copy helpers exist because rebuilding the record field by field
     * loses whichever field the author forgot, and the field most easily
     * forgotten is the suspension lock — which would silently make a suspended
     * machine startable again.
     */
    public function withPowerState(PowerState $powerState): self
    {
        return new self(
            providerId: $this->providerId,
            nodeName: $this->nodeName,
            name: $this->name,
            powerState: $powerState,
            vcpu: $this->vcpu,
            memoryMib: $this->memoryMib,
            diskGib: $this->diskGib,
            uptimeSeconds: $powerState->isOn() ? ($this->uptimeSeconds ?? 0) : null,
            lock: $this->lock,
            startsOnBoot: $this->startsOnBoot,
            raw: $this->raw,
        );
    }

    /**
     * The same machine, locked or released.
     */
    public function withSuspension(?string $lock, ?bool $startsOnBoot): self
    {
        return new self(
            providerId: $this->providerId,
            nodeName: $this->nodeName,
            name: $this->name,
            powerState: $this->powerState,
            vcpu: $this->vcpu,
            memoryMib: $this->memoryMib,
            diskGib: $this->diskGib,
            uptimeSeconds: $this->uptimeSeconds,
            lock: $lock,
            startsOnBoot: $startsOnBoot,
            raw: $this->raw,
        );
    }

    /**
     * The same machine, reshaped.
     */
    public function withShape(?int $vcpu, ?int $memoryMib, ?int $diskGib): self
    {
        return new self(
            providerId: $this->providerId,
            nodeName: $this->nodeName,
            name: $this->name,
            powerState: $this->powerState,
            vcpu: $vcpu,
            memoryMib: $memoryMib,
            diskGib: $diskGib,
            uptimeSeconds: $this->uptimeSeconds,
            lock: $this->lock,
            startsOnBoot: $this->startsOnBoot,
            raw: $this->raw,
        );
    }
}
