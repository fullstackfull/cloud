<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\ValueObjects;

use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\PreflightRefusalReason;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingPreflightFailedException;

/**
 * The verdict on one machine, with every refusal rather than only the first.
 *
 * All the checks run even after one has failed, on purpose. A machine being
 * commissioned is cheap to reinstall for about an hour; discovering its
 * problems one round trip at a time — fix the hostname, run again, discover
 * the ports, run again — spends that hour and usually ends with somebody
 * installing anyway.
 *
 * @immutable
 */
final readonly class PreflightReport
{
    /**
     * @param  list<PreflightRefusal>  $refusals
     */
    public function __construct(
        public string $hostname,
        public HostingPanel $panel,
        public array $refusals,
    ) {}

    /**
     * Whether the machine may have the panel installed on it.
     */
    public function passed(): bool
    {
        return $this->refusals === [];
    }

    public function refused(): bool
    {
        return ! $this->passed();
    }

    /**
     * @return list<string>
     */
    public function errorCodes(): array
    {
        return array_map(
            static fn (PreflightRefusal $refusal): string => $refusal->reason->errorCode(),
            $this->refusals,
        );
    }

    public function refusedFor(PreflightRefusalReason $reason): bool
    {
        foreach ($this->refusals as $refusal) {
            if ($refusal->reason === $reason) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn a refused report into the exception that stops the installer.
     *
     * A report that is merely returned can be ignored by a caller who forgets
     * to check it; this is what makes "refuses" mean refuses.
     *
     * @throws HostingPreflightFailedException
     */
    public function throwIfRefused(): void
    {
        if ($this->refused()) {
            throw HostingPreflightFailedException::refused($this->hostname, $this->panel->value, $this->refusals);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (PreflightRefusal $refusal): array => $refusal->toArray(),
            $this->refusals,
        );
    }
}
