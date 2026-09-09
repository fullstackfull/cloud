<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Domains\Application\Services\DomainInvoicing;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Domain\Services\RedemptionAvailability;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Recover a name from redemption: money first, registry second.
 *
 * The same shape as a renewal, and different in the three places that
 * matter. The name must be IN redemption — a renewal is refused there
 * because the ordinary price no longer applies, and a redemption is refused
 * everywhere else because there is nothing to recover. The namespace must be
 * one the platform can recover names in ({@see RedemptionAvailability}): a
 * registrar that cannot, or has never said, or whose penalty the platform
 * was never told, refuses here with the reason rather than issuing an
 * invoice for something that will not happen. And the quote must have been
 * written for THIS name — a redemption quote for a cheap name spent against
 * an expensive one would be the customer choosing their own penalty.
 *
 * One attempt in flight per name. A second order while the first is
 * requested, queued, running or indeterminate answers 409: a redemption
 * that timed out may have been performed and charged, and a second one is a
 * second penalty for a name already restored.
 */
final readonly class OrderDomainRedemption
{
    public function __construct(
        private RedeemQuote $quotes,
        private DomainInvoicing $invoicing,
        private RedemptionAvailability $availability,
    ) {}

    /**
     * @throws DomainRefusedException
     */
    public function execute(Customer $customer, Domain $domain, string $quoteId): DomainOperation
    {
        if (! $domain->state->isRedeemable()) {
            throw DomainRefusedException::becauseTheStateForbidsIt($domain->name, $domain->state);
        }

        $tld = DomainTld::query()->where('tld', $domain->tld)->first();

        if (! $tld instanceof DomainTld) {
            throw DomainRefusedException::becauseTheTldIsNotSold($domain->tld);
        }

        $answer = $this->availability->forTld($tld);

        if (! $answer->support->isAvailable()) {
            throw DomainRefusedException::becauseRedemptionIsUnavailable($tld->tld, $answer->support, $answer->reason);
        }

        $quote = $this->quotes->execute($customer, $quoteId, DomainOperationKind::Redeem);

        if ($quote->name !== $domain->name) {
            throw DomainRefusedException::becauseItIsNoLongerAvailable($domain->name);
        }

        return DB::transaction(function () use ($customer, $domain, $quote): DomainOperation {
            $alreadyRunning = DomainOperation::query()
                ->where('domain_id', $domain->getKey())
                ->where('kind', DomainOperationKind::Redeem->value)
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
                DomainOperationKind::Redeem,
                $domain->name,
                $quote->term_years,
                Money::ofMinor($quote->price_minor, $quote->currency),
            );

            return DomainOperation::query()->create([
                'domain_id' => $domain->getKey(),
                'customer_id' => $customer->getKey(),
                'name' => $domain->name,
                'kind' => DomainOperationKind::Redeem,
                'state' => DomainOperationState::Requested,
                'term_years' => $quote->term_years,
                'currency' => $quote->currency,
                'price_minor' => $quote->price_minor,
                'cost_minor' => $quote->cost_minor,
                'invoice_id' => $invoice->getKey(),
                'provider' => $domain->provider,
                'idempotency_key' => 'domain-redeem-'.$domain->getKey().'-'.$quote->getKey(),
            ]);
        });
    }
}
