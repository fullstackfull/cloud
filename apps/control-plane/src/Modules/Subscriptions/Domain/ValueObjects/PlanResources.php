<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\ValueObjects;

/**
 * The shape a plan sells, read out of its resources column.
 *
 * A value object rather than an array because plan resources are free-form
 * jsonb — a hosting plan carries disk quota and mailboxes, a VPS plan carries
 * vCPU and memory — and the code that compares two plans needs a stable answer
 * to "how much of each of the three things a hypervisor allocates". Missing
 * keys are null rather than zero: "this plan does not describe a disk" and
 * "this plan sells no disk" are different, and only one of them should stop a
 * customer changing plan.
 *
 * Everything a hypervisor does not allocate is kept too, as `panelQuota`. A
 * shared hosting plan's whole product is quota — disk, bandwidth, databases,
 * mailboxes — enforced by a control panel rather than by a hypervisor, and a
 * comparison that looked only at the compute triple decided that moving
 * between two hosting plans changed nothing at the provider. It does: it
 * changes everything the customer bought.
 *
 * @immutable
 */
final readonly class PlanResources
{
    /**
     * @param  array<string, int>  $panelQuota  Everything a panel enforces, keyed as the plan wrote it.
     */
    public function __construct(
        public ?int $vcpu = null,
        public ?int $memoryMib = null,
        public ?int $diskGib = null,
        public array $panelQuota = [],
    ) {}

    /**
     * Keys that describe a quota a control panel enforces.
     *
     * Named rather than "everything that is not compute", because a plan's
     * resources column also carries placement hints and counts that are not
     * part of what the customer bought, and comparing those would queue a
     * provider call for a catalogue edit that changed nothing.
     *
     * @var list<string>
     */
    private const array PANEL_QUOTA_KEYS = [
        'disk_quota_mib',
        'bandwidth_quota_mib',
        'max_addon_domains',
        'max_subdomains',
        'max_databases',
        'max_email_accounts',
        'cpu_limit_percent',
        'memory_limit_mib',
        'io_limit_kbps',
        'process_limit',
    ];

    /**
     * @param  array<string, mixed>  $resources
     */
    public static function fromArray(array $resources): self
    {
        $quota = [];

        foreach (self::PANEL_QUOTA_KEYS as $key) {
            $value = self::intOrNull($resources[$key] ?? null);

            if ($value !== null) {
                $quota[$key] = $value;
            }
        }

        return new self(
            vcpu: self::intOrNull($resources['vcpu'] ?? null),
            memoryMib: self::intOrNull($resources['memory_mib'] ?? null),
            diskGib: self::intOrNull($resources['disk_gib'] ?? null),
            panelQuota: $quota,
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
            || $this->diskGib !== $other->diskGib
            || $this->panelQuota !== $other->panelQuota;
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
            ...$this->panelQuota,
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
