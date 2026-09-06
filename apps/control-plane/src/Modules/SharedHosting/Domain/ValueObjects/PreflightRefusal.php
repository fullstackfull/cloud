<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\ValueObjects;

use Lynomia\Modules\SharedHosting\Domain\Enums\PreflightRefusalReason;

/**
 * One reason a machine may not have a panel installed on it.
 *
 * It carries the observation as well as the reason, because "the OS is not
 * supported" sends an operator to check the wrong thing far less often than
 * "the OS is ubuntu 24.04 and this panel supports almalinux 8, almalinux 9".
 *
 * @immutable
 */
final readonly class PreflightRefusal
{
    public function __construct(
        public PreflightRefusalReason $reason,
        public string $observed,
        public string $expected,
    ) {}

    public function errorCode(): string
    {
        return $this->reason->errorCode();
    }

    public function summary(): string
    {
        return sprintf('%s (found %s, needs %s)', $this->reason->value, $this->observed, $this->expected);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason->value,
            'error_code' => $this->reason->errorCode(),
            'observed' => $this->observed,
            'expected' => $this->expected,
            'consequence' => $this->reason->consequence(),
        ];
    }
}
