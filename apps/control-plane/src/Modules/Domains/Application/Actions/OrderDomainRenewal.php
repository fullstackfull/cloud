<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Domains\Application\Services\DomainInvoicing;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Paying to keep a name for longer.
 *
 * The same shape as a registration and for the same reason: the invoice is
 * issued here, the registry is asked only once the money has arrived. A
 * renewal charged to the platform and never paid for is a year of somebody
 * else's domain bought with this platform's money.
 *
 * One renewal at a time per name. A second one queued while the first is in
 * flight would extend the term twice, and registries do not refund that.
 */
final readonly class OrderDomainRenewal
{
    public function __construct(
        private RedeemQuote $quotes,
        private DomainInvoicing $invoicing,
    ) {}

    /**
     * @throws DomainRefusedException
     */
    public function execute(Customer $customer, Domain $domain, string $quoteId): DomainOperation
    {
        if (! $domain->state->isRenewable()) {
            throw DomainRefusedException::becauseTheStateForbidsIt($domain->name, $domain->state);
        }

        $quote = $this->quotes->execute($customer, $quoteId, DomainOperationKind::Renew);

        if ($quote->name !== $domain->name) {
            /*
             * A quote for one name spent against another would let a customer
             * renew an expensive domain at a cheap domain's price. The quote
             * carries the name it was written for and this is where the two
             * are required to agree.
             */
            throw DomainRefusedException::becauseItIsNoLongerAvailable($domain->name);
        }

        return DB::transaction(function () use ($customer, $domain, $quote): DomainOperation {
            $alreadyRunning = DomainOperation::query()
                ->where('domain_id', $domain->getKey())
                ->where('kind', DomainOperationKind::Renew->value)
                ->whereIn('state', [
                    DomainOperationState::Requested->value,
                    DomainOperationState::Queued->value,
                    DomainOperationState::Running->value,
                    DomainOperationState::AwaitingRegistry->value,
                    DomainOperationState::Indeterminate->value,
                ])
                ->lockForUpdate()
                ->exists();

            if ($alreadyRunning) {
                throw DomainRefusedException::becauseAnOperationIsAlreadyRunning($domain->name);
            }

            $invoice = $this->invoicing->forOperation(
                $customer,
                DomainOperationKind::Renew,
                $domain->name,
                $quote->term_years,
                Money::ofMinor($quote->price_minor, $quote->currency),
            );

            return DomainOperation::query()->create([
                'domain_id' => $domain->getKey(),
                'customer_id' => $customer->getKey(),
                'name' => $domain->name,
                'kind' => DomainOperationKind::Renew,
                'state' => DomainOperationState::Requested,
                'term_years' => $quote->term_years,
                'currency' => $quote->currency,
                'price_minor' => $quote->price_minor,
                'cost_minor' => $quote->cost_minor,
                'invoice_id' => $invoice->getKey(),
                'provider' => $domain->provider,
                'idempotency_key' => 'domain-renew-'.$domain->getKey().'-'.$quote->getKey(),
            ]);
        });
    }
}
