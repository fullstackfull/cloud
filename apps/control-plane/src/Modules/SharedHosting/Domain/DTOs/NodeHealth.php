<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

/**
 * A node's own account of how it is doing.
 *
 * Deliberately separate from the hosting_nodes row. The row carries what the
 * platform has committed — the account count it has placed — while this
 * carries what the machine observes. Letting a health sync write the
 * commitments would free capacity that is genuinely spoken for: the panel does
 * not know about an account that is still being created, and would report one
 * fewer than exist for as long as a create is in flight.
 *
 * Every field but $online is nullable because a panel under load answers some
 * of these and not others, and a missing load average is not a load average of
 * zero — which is the one value that would make an overloaded node look like
 * the best candidate in the fleet.
 *
 * @immutable
 */
final readonly class NodeHealth
{
    /**
     * @param  array<string, mixed>  $raw  The panel's own answer, redacted.
     */
    public function __construct(
        public bool $online,
        public ?float $loadOne = null,
        public ?float $loadFive = null,
        public ?float $loadFifteen = null,
        public ?int $diskTotalMib = null,
        public ?int $diskUsedMib = null,
        public ?int $accountCount = null,
        public ?string $panelVersion = null,
        public array $raw = [],
    ) {}

    /**
     * Disk in use as a percentage, or null when the node did not say.
     *
     * Null rather than 0.0 for the same reason as everywhere else here: the
     * scheduler weights disk hardest, and a node that failed to report would
     * otherwise present itself as completely empty.
     */
    public function diskUsedPercent(): ?float
    {
        if ($this->diskTotalMib === null || $this->diskTotalMib < 1 || $this->diskUsedMib === null) {
            return null;
        }

        return ($this->diskUsedMib / $this->diskTotalMib) * 100;
    }
}
