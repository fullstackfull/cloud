<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Naming;

use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;

/**
 * One thing the naming audit found.
 *
 * `CheckStatus` rather than a severity of its own, because the platform already
 * has a vocabulary for "this blocks" against "this is worth saying" and a
 * second one would need translating at every boundary. A legacy row that does
 * not match the standard is a WARNING: it is not stopping anything today and
 * renaming it silently is forbidden. Two rows that normalise to one identity,
 * or a reference name configured as production, are FAILURES: those are wrong
 * now.
 *
 * `nextAction` is required on anything that is not a pass, under the same rule
 * the preflight report has held since Gap 3: a finding that says "naming
 * noncanonical" has told an operator nothing they can act on.
 */
final readonly class NamingFinding
{
    /**
     * @param  string  $id  Stable and machine-readable: automation matches on this, not on the wording.
     * @param  string  $target  What was examined, named the way an operator names it.
     */
    public function __construct(
        public string $id,
        public CheckStatus $status,
        public string $target,
        public string $summary,
        public ?NamingConcept $concept = null,
        public ?string $nextAction = null,
    ) {}

    public static function pass(string $id, string $target, string $summary, ?NamingConcept $concept = null): self
    {
        return new self($id, CheckStatus::Pass, $target, $summary, $concept);
    }

    public static function warn(string $id, string $target, string $summary, string $nextAction, ?NamingConcept $concept = null): self
    {
        return new self($id, CheckStatus::Warning, $target, $summary, $concept, $nextAction);
    }

    public static function fail(string $id, string $target, string $summary, string $nextAction, ?NamingConcept $concept = null): self
    {
        return new self($id, CheckStatus::Fail, $target, $summary, $concept, $nextAction);
    }

    public function blocks(): bool
    {
        return $this->status === CheckStatus::Fail;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'target' => $this->target,
            'summary' => $this->summary,
            'concept' => $this->concept?->value,
            'kind' => $this->concept?->kind()->value,
            'next_action' => $this->nextAction,
        ];
    }
}
