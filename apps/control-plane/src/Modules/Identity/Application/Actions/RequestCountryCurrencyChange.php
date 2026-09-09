<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Exceptions\CountryCurrencyChangeRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * Ask for the account's country or currency to change.
 *
 * Nothing on the account changes here. The request is analysed against the
 * rows as they are and recorded as `blocked` (with what must change first)
 * or `awaiting_approval` (for an operator). One open request per account:
 * two would be two different futures for one set of invoices.
 */
final readonly class RequestCountryCurrencyChange
{
    public function __construct(
        private AnalyseCountryCurrencyChange $analyse,
    ) {}

    /**
     * @throws CountryCurrencyChangeRefusedException
     */
    public function execute(Customer $customer, ?string $toCountry, string $toCurrency, string $reason, ?string $userId): CountryCurrencyChange
    {
        $toCurrency = strtoupper($toCurrency);
        $toCountry = $toCountry === null ? null : strtoupper($toCountry);
        $fromCurrency = strtoupper($customer->currency);
        $fromCountry = $customer->country === null ? null : strtoupper($customer->country);

        if ($toCurrency === $fromCurrency && $toCountry === $fromCountry) {
            throw CountryCurrencyChangeRefusedException::nothingChanges();
        }

        $open = CountryCurrencyChange::query()
            ->where('customer_id', $customer->getKey())
            ->open()
            ->first();

        if ($open !== null) {
            throw CountryCurrencyChangeRefusedException::alreadyOpen((string) $open->getKey());
        }

        $impact = $this->analyse->execute($customer, $toCountry, $toCurrency);

        return CountryCurrencyChange::query()->create([
            'customer_id' => $customer->getKey(),
            'state' => $impact->isBlocked() ? CountryCurrencyChangeState::Blocked : CountryCurrencyChangeState::AwaitingApproval,
            'from_country' => $fromCountry,
            'to_country' => $toCountry,
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'reason' => $reason,
            'impact' => $impact->toArray(),
            'requested_by_user_id' => $userId,
            'analysed_at' => now(),
        ]);
    }
}
