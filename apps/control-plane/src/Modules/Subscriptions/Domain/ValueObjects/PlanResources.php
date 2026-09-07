<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\ValueObjects;

/**
 * The shape a plan sells, read out of its resources column.
 *
 * A value object rather than an array because plan resources are free-form
 * jsonb — a hosting plan carries disk and mailboxes, a VPS plan carries vCPU
 * and memory — and the code that compares two plans needs a stable answer to
 * "how much of each of the three things a hypervisor allocates". Missing keys
 * are null rather than zero: "this plan does not describe a disk" and "this
 * plan sells no disk" are different, and only one of them should stop a
 * customer changing plan.
 *
 * @immutable
 */
final readonly class PlanResources
{
    public function __construct(
        public ?int $vcpu = null,
        public ?int $memoryMib = null,
        public ?int $diskGib = null,
    ) {}

    /**
     * @param  array<string, mixed>  $resources
     */
    public static function fromArray(array $resources): self
    {
        return new self(
            vcpu: self::intOrNull($resources['vcpu'] ?? null),
            memoryMib: self::intOrNull($resources['memory_mib'] ?? null),
            diskGib: self::intOrNull($resources['disk_gib'] ?? null),
        );
    }

    /**
     * Whether moving from this shape to another changes what a hypervisor has
     * to allocate.
     *
     * The question that decides whether a plan change is only a billing change
     * or also an infrastructure one — and therefore whether it is finished
     * when the money moves.
     */
    public function differsFrom(self $other): bool
    {
        return $this->vcpu !== $other->vcpu
            || $this->memoryMib !== $other->memoryMib
            || $this->diskGib !== $other->diskGib;
    }

    /**
     * Whether the other shape has a smaller disk than this one.
     *
     * Its own question, because it is the one change the platform refuses. A
     * disk that shrinks is a filesystem truncated: the bytes past the new end
     * are gone, and no amount of "are you sure" makes that a thing to do to a
     * running customer's server on the strength of a plan being cheaper.
     */
    public function wouldShrinkDiskOf(self $other): bool
    {
        return $this->diskGib !== null
            && $other->diskGib !== null
            && $other->diskGib < $this->diskGib;
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'vcpu' => $this->vcpu,
            'memory_mib' => $this->memoryMib,
            'disk_gib' => $this->diskGib,
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
