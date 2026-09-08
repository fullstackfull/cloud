<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainQuote;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * Spends a quote, once, for the account it was made for.
 *
 * ---------------------------------------------------------------------------
 * Three checks, and each one is an attack
 * ---------------------------------------------------------------------------
 *
 *  - **Whose quote is it.** Scoped to the acting account, and answered 404
 *    rather than 403: telling somebody that a quote id exists but is not
 *    theirs confirms that another account priced that name.
 *
 *  - **Has it expired.** A quote is a promise about a namespace anybody may
 *    buy from at any moment. An hour-old premium price is a price the registry
 *    has probably moved.
 *
 *  - **Has it been spent.** Under a row lock, because two checkouts arriving
 *    together on one quote would otherwise both pass the check and both buy —
 *    two registrations at one agreed price, which matters most on exactly the
 *    premium names worth gaming.
 */
final readonly class RedeemQuote
{
    /**
     * @throws DomainRefusedException
     */
    public function execute(Customer $customer, string $quoteId, DomainOperationKind $expected): DomainQuote
    {
        return DB::transaction(function () use ($customer, $quoteId, $expected): DomainQuote {
            $quote = DomainQuote::query()
                ->where('customer_id', $customer->getKey())
                ->whereKey($quoteId)
                ->lockForUpdate()
                ->first();

            if (! $quote instanceof DomainQuote) {
                /*
                 * The same answer a quote that never existed gets. A caller
                 * cannot tell "not yours" from "not real", which is what stops
                 * an id from confirming that somebody else priced a name.
                 */
                throw DomainRefusedException::becauseTheQuoteHasExpired($quoteId);
            }

            if ($quote->operation !== $expected) {
                /*
                 * A registration quote redeemed against a renewal would charge
                 * the registration price for a renewal — usually the cheaper
                 * of the two, and always the wrong one.
                 */
                throw DomainRefusedException::becauseTheQuoteHasExpired($quoteId);
            }

            if ($quote->consumed_at !== null) {
                throw DomainRefusedException::becauseTheQuoteIsSpent($quoteId);
            }

            if (! $quote->isRedeemable()) {
                throw DomainRefusedException::becauseTheQuoteHasExpired($quoteId);
            }

            $quote->forceFill(['consumed_at' => now()])->save();

            return $quote->refresh();
        });
    }
}
