<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Application\DTOs;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What paying this invoice from stored credit would do.
 *
 * The three figures a payment screen has to show, and the reason they are one
 * object: a screen that computed "remaining" itself from the other two would
 * be a second implementation of the rule that a wallet cannot overpay an
 * invoice, and the two would disagree the first time either changed.
 *
 * @immutable
 */
final readonly class WalletCreditQuote
{
    public function __construct(
        /** Everything the customer holds in the invoice's currency. */
        public Money $available,
        /** What this invoice can take of it: min(available, amount due). */
        public Money $applicable,
        /** What would still be owed afterwards, payable by card. */
        public Money $remaining,
        /** False when applying nothing is the only possible outcome. */
        public bool $isPayable,
    ) {}
}
