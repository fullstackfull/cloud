<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\DTOs;

use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;

/**
 * What one controller run produced, in the four shapes that matter.
 *
 *   succeeded      the run finished and said so
 *   failed         the run finished and said it did not; the class says whether
 *                  a person or a retry is the answer (never a retry for timeout)
 *   indeterminate  the run did not finish inside the deadline. The machine may
 *                  be half-changed. Nothing retries this; a person looks.
 *
 * @param  list<array{name: string, outcome: string, detail?: string}>  $steps
 * @param  array<string, string>  $facts  What verification observed, to be written as facts.
 */
final readonly class DeploymentOutcome
{
    /**
     * @param  list<array{name: string, outcome: string, detail?: string}>  $steps
     * @param  array<string, string>  $facts
     */
    private function __construct(
        public bool $succeeded,
        public bool $indeterminate,
        public ?FailureClass $failureClass,
        public ?string $detail,
        public array $steps,
        public array $facts,
    ) {}

    /**
     * @param  list<array{name: string, outcome: string, detail?: string}>  $steps
     * @param  array<string, string>  $facts
     */
    public static function succeeded(array $steps, array $facts = []): self
    {
        return new self(true, false, null, null, $steps, $facts);
    }

    /**
     * @param  list<array{name: string, outcome: string, detail?: string}>  $steps
     */
    public static function failed(FailureClass $class, string $detail, array $steps): self
    {
        return new self(false, false, $class, $detail, $steps, []);
    }

    /**
     * @param  list<array{name: string, outcome: string, detail?: string}>  $steps
     */
    public static function indeterminate(string $detail, array $steps): self
    {
        return new self(false, true, FailureClass::Timeout, $detail, $steps, []);
    }
}
