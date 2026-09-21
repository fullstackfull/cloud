<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\DTOs;

/**
 * Whether this platform's own configuration can describe where a plan goes.
 *
 * Not whether a provider would accept the job. Every value behind this answer
 * is a row Lynomia already holds — a hosting package, a cluster, an IP pool,
 * an OS image — so it can be asked before a customer is charged, which is the
 * whole point of asking it.
 *
 * @immutable
 */
final readonly class PlacementResolution
{
    /**
     * @param  array<string, mixed>  $values  the resolved placement, empty when blocked
     */
    private function __construct(
        public ?string $blockedReason,
        public array $values,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function ready(array $values = []): self
    {
        return new self(null, $values);
    }

    public static function blocked(string $reason): self
    {
        return new self($reason, []);
    }

    public function isFeasible(): bool
    {
        return $this->blockedReason === null;
    }
}
