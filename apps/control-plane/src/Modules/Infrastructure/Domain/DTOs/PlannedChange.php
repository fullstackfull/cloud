<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\DTOs;

use Lynomia\Modules\Infrastructure\Domain\Enums\PlanRisk;

/**
 * One thing a plan would do, rendered for a person and hashed for the
 * approval.
 */
final readonly class PlannedChange
{
    /**
     * @param  array<string, string>  $configuration  The overrides that apply to this component, already validated.
     */
    public function __construct(
        public string $component,
        public string $action,
        public string $role,
        public PlanRisk $risk,
        public bool $requiresReboot,
        public array $configuration,
        public string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'action' => $this->action,
            'role' => $this->role,
            'risk' => $this->risk->value,
            'requires_reboot' => $this->requiresReboot,
            'configuration' => (object) $this->configuration,
            'reason' => $this->reason,
        ];
    }
}
