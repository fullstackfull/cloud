<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\ValueObjects;

/**
 * One weighted term of a node's placement score.
 *
 * The raw value is kept beside the weighted contribution so that an operator
 * reading a decision can tell the two failure modes apart: a node that lost
 * because it genuinely had less free memory, and a node that lost because
 * somebody set the memory weight to zero.
 *
 * @immutable
 */
final readonly class ScoreComponent
{
    /**
     * @param  float  $value  Normalised to 0..1, so components remain comparable
     *                        when the weights are retuned.
     */
    public function __construct(
        public string $name,
        public float $value,
        public int $weight,
        public ?string $detail = null,
    ) {}

    public function contribution(): float
    {
        return $this->value * $this->weight;
    }

    /**
     * @return array{value: float, weight: int, contribution: float, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'value' => round($this->value, 4),
            'weight' => $this->weight,
            'contribution' => round($this->contribution(), 4),
            'detail' => $this->detail,
        ];
    }
}
