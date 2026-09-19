<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Identity\Domain\DTOs\CountryCurrencyImpact;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;

/**
 * What changing an account's country or currency would touch, and what
 * stops it — computed from the rows as they are right now, never stored
 * as a fact about the future.
 *
 * ===========================================================================
 * THE POLICY, IN ONE PLACE
 * ===========================================================================
 *
 * Nothing already written is ever converted. An invoice keeps the currency
 * and the tax it was issued with; a payment keeps what was paid; an order
 * keeps what was ordered; a subscription keeps the currency it was sold in.
 * So a currency change is only possible once the account has nothing in
 * the old currency that is still live:
 *
 *  - no invoice open (it would be paid in a currency the account no longer
 *    has);
 *  - no order between placement and provisioning (it has a price in the
 *    old currency and an invoice on the way);
 *  - no domain operation with the registrar (its quote and its refund are
 *    in the old currency);
 *  - no active or past-due subscription (a renewal is a new invoice, and a
 *    new invoice in a currency the account does not hold is a silent
 *    repricing — the customer ends the subscription and orders again from
 *    the new price list, at the new price, knowingly);
 *  - no wallet balance in the old currency (credit is not exchanged; it is
 *    spent or refunded);
 *  - and the target currency has a price list at all.
 *
 * A country-only change is lighter: the currency and everything priced in
 * it stay as they are, and what changes is the tax rate on invoices issued
 * from that moment on. That is reported as a warning, with both rates, and
 * is not a reason to refuse.
 *
 * Every number here is a count or a minor-unit amount in the currency named
 * beside it. Nothing is converted, and nothing is a float.
 */
final readonly class AnalyseCountryCurrencyChange
{
    /** Orders that have a price on them and are not finished with it. */
    private const array ORDERS_IN_FLIGHT = [
        OrderStatus::PendingPayment,
        OrderStatus::PaymentFailed,
        OrderStatus::Paid,
        OrderStatus::QueuedForProvisioning,
        OrderStatus::Provisioning,
        OrderStatus::ManualReview,
    ];

    public function __construct(
        private TaxResolver $tax,
    ) {}

    public function execute(Customer $customer, ?string $toCountry, string $toCurrency): CountryCurrencyImpact
    {
        $fromCurrency = strtoupper($customer->currency);
        $toCurrency = strtoupper($toCurrency);
        $fromCountry = $customer->country === null ? null : strtoupper($customer->country);
        $toCountry = $toCountry === null ? null : strtoupper($toCountry);

        $currencyChanges = $fromCurrency !== $toCurrency;
        $countryChanges = $fromCountry !== $toCountry;

        $id = $customer->getKey();

        $subscriptions = Subscription::query()
            ->where('customer_id', $id)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value])
            ->get(['id', 'currency', 'recurring_amount_minor']);

        $openInvoices = Invoice::query()
            ->where('customer_id', $id)
            ->where('status', InvoiceStatus::Open->value)
            ->get(['id', 'currency', 'amount_due_minor']);

        $ordersInFlight = Order::query()
            ->where('customer_id', $id)
            ->whereIn('status', array_map(static fn (OrderStatus $s): string => $s->value, self::ORDERS_IN_FLIGHT))
            ->count();

        $domainOperations = DomainOperation::query()
            ->where('customer_id', $id)
            ->whereIn('state', array_map(
                static fn (DomainOperationState $s): string => $s->value,
                array_values(array_filter(DomainOperationState::cases(), static fn (DomainOperationState $s): bool => $s->isInFlight() || $s === DomainOperationState::Indeterminate)),
            ))
            ->count();

        $activeServices = Service::query()
            ->where('customer_id', $id)
            ->where('status', ServiceStatus::Active->value)
            ->count();

        $wallet = Wallet::query()->where('customer_id', $id)->where('currency', $fromCurrency)->first();
        $walletBalance = (int) ($wallet->balance_minor ?? 0);

        $targetInCatalogue = PlanPrice::query()
            ->where('currency', $toCurrency)
            ->where('is_active', true)
            ->exists();

        $now = now();
        $taxBefore = $this->tax->resolve($fromCountry, $customer->state, $now);
        $taxAfter = $this->tax->resolve($toCountry, $customer->state, $now);

        $facts = [
            'country_changes' => $countryChanges,
            'currency_changes' => $currencyChanges,
            'active_subscriptions' => $subscriptions->count(),
            'recurring_minor' => (int) $subscriptions->sum('recurring_amount_minor'),
            'open_invoices' => $openInvoices->count(),
            'open_invoices_due_minor' => (int) $openInvoices->sum('amount_due_minor'),
            'orders_in_flight' => $ordersInFlight,
            'domain_operations_in_flight' => $domainOperations,
            'active_services' => $activeServices,
            'wallet_balance_minor' => $walletBalance,
            'wallet_currency' => $fromCurrency,
            'target_currency_in_catalogue' => $targetInCatalogue,
            'tax_before' => $this->describe($taxBefore),
            'tax_after' => $this->describe($taxAfter),
        ];

        /*
         * Blockers and warnings are stored as codes with parameters, never as
         * sentences. The sentence is composed when the request is answered,
         * in the language the request asked for (CountryCurrencyChangeResource
         * and lang/{locale}/account.php); a sentence stored at analysis time
         * would be in whichever language the analyser happened to run in.
         */
        $blockers = [];
        $warnings = [];

        if ($currencyChanges) {
            if (! $targetInCatalogue) {
                $blockers[] = self::line('target_currency_not_priced', ['currency' => $toCurrency]);
            }

            if ($openInvoices->count() > 0) {
                $blockers[] = self::line('open_invoices', ['count' => $openInvoices->count(), 'currency' => $fromCurrency]);
            }

            if ($ordersInFlight > 0) {
                $blockers[] = self::line('orders_in_flight', ['count' => $ordersInFlight, 'currency' => $fromCurrency]);
            }

            if ($domainOperations > 0) {
                $blockers[] = self::line('domain_operations_in_flight', ['count' => $domainOperations, 'currency' => $fromCurrency]);
            }

            if ($subscriptions->count() > 0) {
                $blockers[] = self::line('subscriptions_renew', ['count' => $subscriptions->count(), 'currency' => $fromCurrency, 'to_currency' => $toCurrency]);
            }

            if ($walletBalance > 0) {
                $blockers[] = self::line('wallet_holds_balance', ['currency' => $fromCurrency]);
            }

            $warnings[] = self::line('history_keeps_currency', ['currency' => $fromCurrency, 'to_currency' => $toCurrency]);
        }

        if ($countryChanges) {
            $warnings[] = self::line('tax_changes', [
                'tax_after' => self::taxCode($taxAfter),
                'tax_after_rate' => self::taxRate($taxAfter),
                'tax_after_name' => $taxAfter->name ?? '',
                'tax_before' => self::taxCode($taxBefore),
                'tax_before_rate' => self::taxRate($taxBefore),
                'tax_before_name' => $taxBefore->name ?? '',
            ]);

            if (! $currencyChanges && $openInvoices->count() > 0) {
                $warnings[] = self::line('open_invoices_keep_tax', ['count' => $openInvoices->count()]);
            }
        }

        return new CountryCurrencyImpact($facts, $blockers, $warnings);
    }

    /**
     * One blocker or warning: a code and the parameters its sentence needs.
     *
     * @param  array<string, scalar>  $params
     * @return array{code: string, params: array<string, scalar>}
     */
    private static function line(string $code, array $params): array
    {
        return ['code' => $code, 'params' => $params];
    }

    /** Which tax sentence applies: none, or a rate with a name. */
    private static function taxCode(TaxRate $rate): string
    {
        return $rate->isZero() ? 'none' : 'rate';
    }

    private static function taxRate(TaxRate $rate): string
    {
        return $rate->isZero() ? '0' : rtrim(rtrim($rate->rate, '0'), '.');
    }

    /**
     * @return array{rate: string, name: ?string}|null
     */
    private function describe(TaxRate $rate): ?array
    {
        if ($rate->isZero()) {
            return null;
        }

        return ['rate' => self::taxRate($rate), 'name' => $rate->name];
    }
}
