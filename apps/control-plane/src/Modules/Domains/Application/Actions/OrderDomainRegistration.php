<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Domains\Application\Services\DomainInvoicing;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssertProductMaySell;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Buying a name: the part that happens before any money has moved.
 *
 * ---------------------------------------------------------------------------
 * What this does and, more importantly, what it does not
 * ---------------------------------------------------------------------------
 *
 * It spends a quote, claims the name inside this platform, records the
 * contacts the registration will be filed with, and issues an invoice. It does
 * **not** talk to the registrar. Nothing is registered until that invoice is
 * paid, and the registrar call happens from a job, once, afterwards.
 *
 * The order matters. Registering first and invoicing afterwards would mean a
 * name held at a registry against an invoice a customer may never pay, and a
 * domain is not a virtual machine: it cannot be suspended and reclaimed, the
 * registry fee is spent the moment the registration succeeds, and the
 * platform, not the customer, would be the one out of pocket.
 *
 * ---------------------------------------------------------------------------
 * The claim, and the race it settles
 * ---------------------------------------------------------------------------
 *
 * The `domains` row is written here, in `registration_pending`, before the
 * invoice exists. That row is what stops two accounts buying the same name in
 * the seconds between their two searches — the partial unique index refuses
 * the second one, and this action turns that refusal into a refusal a customer
 * can read.
 *
 * It also means a name can be claimed inside this platform and never
 * registered, if the invoice goes unpaid. That is the cheap failure of the
 * two, and it is swept up rather than prevented: see the expiry of unpaid
 * registration operations.
 */
final readonly class OrderDomainRegistration
{
    public function __construct(
        private RedeemQuote $quotes,
        private DomainInvoicing $invoicing,
        private AssertProductMaySell $sellable,
    ) {}

    /**
     * @param  array<value-of<DomainContactRole>|string, ContactDetails>  $contacts
     *
     * @throws DomainRefusedException
     */
    public function execute(
        Customer $customer,
        string $quoteId,
        array $contacts,
        ?string $idempotencyKey = null,
    ): DomainOperation {
        // A new name is a new sale; the guard stands aside outside production
        // and refuses in it while the domains line is not ready_to_sell.
        // Renewal and redemption of a name already held are not sales and
        // are not guarded: a customer keeps what they have.
        $this->sellable->execute(Product::Domains);

        $quote = $this->quotes->execute($customer, $quoteId, DomainOperationKind::Register);

        $registrant = $contacts[DomainContactRole::Registrant->value] ?? null;

        if (! $registrant instanceof ContactDetails) {
            /*
             * Every registry files a registration against a registrant, and a
             * registration submitted without one is refused by the registry
             * after the customer has paid. Refused here instead.
             */
            throw DomainRefusedException::becauseAContactIsMissing(DomainContactRole::Registrant->value);
        }

        $tld = DomainTld::query()->where('tld', $quote->tld)->first();

        if (! $tld instanceof DomainTld) {
            throw DomainRefusedException::becauseTheTldIsNotSold($quote->tld);
        }

        return DB::transaction(function () use ($customer, $quote, $contacts, $idempotencyKey): DomainOperation {
            $this->refuseIfAlreadyHeld($quote->name);

            $domain = Domain::query()->create([
                'customer_id' => $customer->getKey(),
                'name' => $quote->name,
                'tld' => $quote->tld,
                'state' => DomainState::RegistrationPending,
                'provider' => $quote->provider,
                'term_years' => $quote->term_years,

                /*
                 * On by default, and the single most consequential default in
                 * this module. A domain that lapses is gone — not suspended,
                 * not recoverable at the ordinary price — and the customer who
                 * loses one to a missed renewal loses their mail with it.
                 */
                'auto_renew' => true,
            ]);

            foreach ($contacts as $role => $details) {
                $this->recordContact($domain, DomainContactRole::from((string) $role), $details);
            }

            $invoice = $this->invoicing->forOperation(
                $customer,
                DomainOperationKind::Register,
                $quote->name,
                $quote->term_years,
                Money::ofMinor($quote->price_minor, $quote->currency),
            );

            return DomainOperation::query()->create([
                'domain_id' => $domain->getKey(),
                'customer_id' => $customer->getKey(),
                'name' => $quote->name,
                'kind' => DomainOperationKind::Register,
                'state' => DomainOperationState::Requested,
                'term_years' => $quote->term_years,
                'currency' => $quote->currency,
                'price_minor' => $quote->price_minor,
                'cost_minor' => $quote->cost_minor,
                'invoice_id' => $invoice->getKey(),
                'provider' => $quote->provider,

                /*
                 * The key the registrar will be given, and the key this
                 * platform will not issue twice. Derived from the operation's
                 * own identity rather than from anything a client sent: a
                 * client-chosen key that repeated would either merge two
                 * genuine purchases or split one.
                 */
                'idempotency_key' => $idempotencyKey ?? 'domain-register-'.$domain->getKey(),
            ]);
        });
    }

    /**
     * The name, taken inside this platform, before the registry is asked.
     *
     * The database enforces this with a partial unique index and would refuse
     * the insert anyway. The check is here as well so that the customer gets a
     * sentence instead of a constraint violation, and it is inside the
     * transaction so that the two cannot disagree.
     */
    private function refuseIfAlreadyHeld(string $name): void
    {
        $held = Domain::query()
            ->where('name', $name)
            ->whereIn('state', DomainState::thatHoldTheName())
            ->lockForUpdate()
            ->exists();

        if ($held) {
            throw DomainRefusedException::becauseItIsNoLongerAvailable($name);
        }
    }

    private function recordContact(Domain $domain, DomainContactRole $role, ContactDetails $details): void
    {
        DomainContact::query()->create([
            'domain_id' => $domain->getKey(),
            'role' => $role,
            'name' => $details->name,
            'organisation' => $details->organisation,
            'email' => $details->email,
            'phone' => $details->phone,
            'address_line_one' => $details->addressLineOne,
            'address_line_two' => $details->addressLineTwo,
            'city' => $details->city,
            'region' => $details->region,
            'postal_code' => $details->postalCode,
            'country' => $details->country,
        ]);
    }
}
