<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Services;

use Lynomia\Modules\Billing\Domain\Exceptions\UnsupportedBillingCurrencyException;

/**
 * The currencies this platform bills in, and the currency it recommends for a
 * country.
 *
 * One place answers both questions, and it answers them from configuration
 * that a human wrote. There is no algorithm here and no locale guess: a
 * country either has a row in `billing.country_currencies` or it takes the
 * explicit '*' row, and either way the answer is a recommendation the
 * registration screen shows before anything is submitted.
 *
 * The reason this is a service and not three calls to `config()` scattered
 * about is the AR-11 defect it exists to close: registration used to accept
 * no country at all and book the account in the platform's default currency
 * without saying so. A customer discovered their currency on their first
 * invoice, and changing it afterwards is a support conversation. So every
 * currency that reaches a customer record passes through `assertEnabled()`,
 * and a currency that is not on the list is refused rather than substituted.
 */
final class BillingCurrencies
{
    /**
     * Every currency the platform is willing to bill in, as ISO-4217 codes.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        /** @var list<string> $configured */
        $configured = config('billing.currencies', []);

        $codes = array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $configured,
        )));

        /*
         * The platform's own default is always billable, whatever the list
         * says. An environment that trims the list without noticing that it
         * has removed its own default currency would otherwise be unable to
         * price anything at all, and would discover that at a customer's
         * checkout rather than at boot.
         */
        $default = $this->default();

        return in_array($default, $codes, true) ? $codes : [...$codes, $default];
    }

    public function default(): string
    {
        return strtoupper((string) config('billing.default_currency', 'KWD'));
    }

    public function isEnabled(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), $this->enabled(), true);
    }

    /**
     * @throws UnsupportedBillingCurrencyException
     */
    public function assertEnabled(string $currency): string
    {
        $code = strtoupper(trim($currency));

        if (! $this->isEnabled($code)) {
            throw UnsupportedBillingCurrencyException::forCurrency($code, $this->enabled());
        }

        return $code;
    }

    /**
     * The currency recommended for a country, and whether the recommendation
     * came from that country's own row or from the catch-all.
     *
     * A caller that wants to tell the customer where the answer came from —
     * the registration screen does — needs the distinction; a caller that
     * only wants a currency can use recommendedFor().
     *
     * @return array{currency: string, is_explicit: bool}
     */
    public function recommendationFor(?string $country): array
    {
        /** @var array<string, string> $map */
        $map = config('billing.country_currencies', []);

        $code = $country === null ? null : strtoupper(trim($country));

        $explicit = $code !== null && isset($map[$code]) ? strtoupper((string) $map[$code]) : null;

        if ($explicit !== null && $this->isEnabled($explicit)) {
            return ['currency' => $explicit, 'is_explicit' => true];
        }

        $fallback = isset($map['*']) ? strtoupper((string) $map['*']) : $this->default();

        return [
            'currency' => $this->isEnabled($fallback) ? $fallback : $this->default(),
            'is_explicit' => false,
        ];
    }

    public function recommendedFor(?string $country): string
    {
        return $this->recommendationFor($country)['currency'];
    }

    /**
     * The countries the platform accepts, as ISO-3166-1 alpha-2 codes.
     *
     * @return list<string>
     */
    public function countries(): array
    {
        /** @var list<string> $countries */
        $countries = config('geography.countries', []);

        return array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $countries,
        )));
    }

    public function isKnownCountry(string $country): bool
    {
        return in_array(strtoupper(trim($country)), $this->countries(), true);
    }
}
