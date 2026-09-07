<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\ValueObjects;

use Carbon\CarbonInterface;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What a customer holds in one currency, at one moment.
 *
 * A wallet id of null is not an error and not an empty account: it is a
 * currency the customer has never transacted in, reported as a zero balance so
 * a brand-new account gets an answer instead of an empty list. Reading a
 * balance must not open a wallet — a GET that writes a row is a GET that can
 * be made to write rows.
 *
 * @immutable
 */
final readonly class WalletBalance
{
    public function __construct(
        public ?string $walletId,
        public Money $balance,
        public ?CarbonInterface $updatedAt = null,
    ) {}

    public static function unopened(string $currency): self
    {
        return new self(walletId: null, balance: Money::zero($currency));
    }

    public function currency(): string
    {
        return $this->balance->currency();
    }
}
