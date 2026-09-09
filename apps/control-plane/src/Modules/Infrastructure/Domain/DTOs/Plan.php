<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\DTOs;

use Lynomia\Modules\Infrastructure\Domain\Enums\PlanRisk;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;

/**
 * What the plan engine concluded, before it is persisted.
 */
final readonly class Plan
{
    /**
     * @param  list<PlannedChange>  $changes
     * @param  list<array{component: string, reason: string}>  $unchanged
     * @param  list<array{code: string, detail: string}>  $blockers
     */
    public function __construct(
        public string $profile,
        public string $playbook,
        public array $changes,
        public array $unchanged,
        public array $blockers,
        public PlanRisk $risk,
        public SafetyClass $requiredSafetyClass,
        public bool $requiresReboot,
        public bool $isDestructive,
        public string $fingerprint,
    ) {}

    public function isApplicable(): bool
    {
        return $this->blockers === [] && $this->changes !== [];
    }
}
