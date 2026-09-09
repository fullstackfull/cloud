<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Domains\Application\Services\DomainInvoicing;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssertProductMaySell;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Bringing a name here from another registrar.
 *
 * ---------------------------------------------------------------------------
 * The authorisation code does not touch the database
 * ---------------------------------------------------------------------------
 *
 * It arrives on the request, it is handed to the job that sends it, and it
 * goes out of scope. An auth code is a bearer credential for an entire domain:
 * anybody holding it can move the name. A stored copy is a copy that leaks,
 * and the customer can always ask their old registrar for another.
 *
 * ---------------------------------------------------------------------------
 * A transfer usually includes a year
 * ---------------------------------------------------------------------------
 *
 * Most registries add a year to the term as part of the transfer, which is why
 * the transfer price is its own price rather than a fee: the customer is
 * buying a year and a move together.
 */
final readonly class OrderDomainTransfer
{
    public function __construct(
        private RedeemQuote $quotes,
        private DomainInvoicing $invoicing,
        private AssertProductMaySell $sellable,
    ) {}

    /**
     * @throws DomainRefusedException
     */
    public function execute(Customer $customer, string $quoteId, string $authorisationCode): DomainOperation
    {
        // A new name is a new sale; the guard stands aside outside production
        // and refuses in it while the domains line is not ready_to_sell.
        // Renewal and redemption of a name already held are not sales and
        // are not guarded: a customer keeps what they have.
        $this->sellable->execute(Product::Domains);

        $quote = $this->quotes->execute($customer, $quoteId, DomainOperationKind::Transfer);

        $tld = DomainTld::query()->where('tld', $quote->tld)->first();

        if (! $tld instanceof DomainTld || ! $tld->allows_transfer) {
            throw DomainRefusedException::becauseTheOperationIsNotOffered($quote->tld, DomainOperationKind::Transfer);
        }

        return DB::transaction(function () use ($customer, $quote, $authorisationCode): DomainOperation {
            $held = Domain::query()
                ->where('name', $quote->name)
                ->whereIn('state', DomainState::thatHoldTheName())
                ->lockForUpdate()
                ->exists();

            if ($held) {
                /*
                 * Already on this platform. Transferring a name from here to
                 * here is not a thing a registry will do, and the customer is
                 * usually looking for the renewal button.
                 */
                throw DomainRefusedException::becauseItIsNoLongerAvailable($quote->name);
            }

            $domain = Domain::query()->create([
                'customer_id' => $customer->getKey(),
                'name' => $quote->name,
                'tld' => $quote->tld,
                'state' => DomainState::TransferPending,
                'provider' => $quote->provider,
                'term_years' => $quote->term_years,
                'auto_renew' => true,
            ]);

            $invoice = $this->invoicing->forOperation(
                $customer,
                DomainOperationKind::Transfer,
                $quote->name,
                $quote->term_years,
                Money::ofMinor($quote->price_minor, $quote->currency),
            );

            return DomainOperation::query()->create([
                'domain_id' => $domain->getKey(),
                'customer_id' => $customer->getKey(),
                'name' => $quote->name,
                'kind' => DomainOperationKind::Transfer,
                'state' => DomainOperationState::Requested,
                'term_years' => $quote->term_years,
                'currency' => $quote->currency,
                'price_minor' => $quote->price_minor,
                'cost_minor' => $quote->cost_minor,
                'invoice_id' => $invoice->getKey(),
                'provider' => $quote->provider,
                'idempotency_key' => 'domain-transfer-'.$domain->getKey(),

                /*
                 * Encrypted at rest, hidden from every payload, and erased by
                 * the job that sends it. See the migration that added the
                 * column for why it is held at all.
                 */
                'authorisation_code' => $authorisationCode,
            ]);
        });
    }
}
