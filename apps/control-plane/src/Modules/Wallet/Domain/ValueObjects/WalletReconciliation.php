<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\ValueObjects;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The result of comparing a wallet's cached balance against its ledger.
 *
 * Deliberately a report and not a repair. A cache that disagrees with the
 * ledger means something wrote outside WalletLedger, or a write was lost;
 * silently setting balance_minor to the derived figure would erase the only
 * evidence of that while leaving the underlying defect in place. An operator
 * decides what to do, and the fix is a compensating adjustment with an author.
 *
 * @immutable
 */
final readonly class WalletReconciliation
{
    /**
     * @param  list<string>  $divergentEntryIds  entries whose balance_after_minor
     *                                           disagrees with the running total up to that point
     */
    public function __construct(
        public string $walletId,
        public Money $cached,
        public Money $derived,
        public array $divergentEntryIds = [],
    ) {}

    /**
     * How far the cache is from the truth. Positive means the cached balance
     * overstates what the ledger supports — the customer could spend money the
     * ledger never says they have.
     */
    public function drift(): Money
    {
        return $this->cached->minus($this->derived);
    }

    public function isBalanced(): bool
    {
        return $this->cached->equals($this->derived) && $this->divergentEntryIds === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'wallet_id' => $this->walletId,
            'cached_minor' => $this->cached->minorUnits(),
            'derived_minor' => $this->derived->minorUnits(),
            'drift_minor' => $this->drift()->minorUnits(),
            'currency' => $this->cached->currency(),
            'balanced' => $this->isBalanced(),
            'divergent_entry_ids' => $this->divergentEntryIds,
        ];
    }
}
