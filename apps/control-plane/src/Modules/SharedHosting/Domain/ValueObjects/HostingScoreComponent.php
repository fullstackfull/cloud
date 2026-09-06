<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\ValueObjects;

/**
 * One weighted term of a node's placement score.
 *
 * The raw value is kept beside the weighted contribution so that an operator
 * reading a decision can tell the two failure modes apart: a node that lost
 * because it genuinely had less disk headroom, and a node that lost because
 * somebody set the disk weight to zero.
 *
 * @immutable
 */
final readonly class HostingScoreComponent
{
    /**
     * @param  float  $value  Normalised to 0..1 so the terms stay comparable when the
     *                        weights are retuned. Clamped here rather than trusted: a panel
     *                        reporting more disk free than the node has must not be able to
     *                        produce a node that outscores every honest one.
     */
    public function __construct(
        public string $name,
        float $value,
        public int $weight,
        public ?string $detail = null,
    ) {
        $this->value = max(0.0, min(1.0, $value));
    }

    public float $value;

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
