<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\DTOs;

use Lynomia\Modules\Provisioning\Domain\Enums\CompensationAction;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;

/**
 * What compensation decided, and why.
 *
 * The failure class is carried alongside the action so that the record on the
 * job explains itself: "quarantined because timeout" is an answer an operator
 * can act on; "quarantined" alone is a mystery.
 *
 * @immutable
 */
final readonly class CompensationOutcome
{
    public function __construct(
        public CompensationAction $action,
        public FailureClass $failureClass,
        public int $reservations,
        public string $reason,
    ) {}

    public function released(): bool
    {
        return $this->action === CompensationAction::Released;
    }

    public function quarantined(): bool
    {
        return $this->action === CompensationAction::Quarantined;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'failure_class' => $this->failureClass->value,
            'reservations' => $this->reservations,
            'reason' => $this->reason,
        ];
    }
}
