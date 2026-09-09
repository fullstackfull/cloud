<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\DTOs;

use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

/**
 * How far one requirement is met, which provider meets it, and what stops it
 * from reaching the next rung.
 */
final readonly class RequirementVerdict
{
    public function __construct(
        public Requirement $requirement,
        public ProductReadinessState $satisfiedUpTo,
        public ?string $providerId,
        public ?string $providerName,
        public ?BlockerReason $blocker,
        public string $detail,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->requirement->category->value,
            'capabilities' => $this->requirement->capabilities,
            'optional' => $this->requirement->optional,
            'shared' => $this->requirement->shared,
            'satisfied_up_to' => $this->satisfiedUpTo->value,
            'provider_id' => $this->providerId,
            'provider_name' => $this->providerName,
            'blocker' => $this->blocker?->value,
            'next_action' => $this->blocker?->nextAction(),
            'detail' => $this->detail,
        ];
    }
}
