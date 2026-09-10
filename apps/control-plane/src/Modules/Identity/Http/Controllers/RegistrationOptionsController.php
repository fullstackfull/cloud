<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Lynomia\Modules\Billing\Domain\Services\BillingCurrencies;

/**
 * What a registration form is allowed to offer.
 *
 * Public and unauthenticated, because it is read before an account exists. It
 * discloses nothing about anybody: the country list is the ISO standard's, the
 * currency list is what this platform bills in, and the recommendation is the
 * configured mapping between the two.
 *
 * The endpoint exists so the portal never invents either list. A frontend that
 * carried its own country-to-currency table would drift from the server the
 * first time a currency was enabled or withdrawn, and the customer would find
 * out at checkout. And because the same lists validate the submission, a
 * browser that edits the options in DevTools submits something the server has
 * never offered and is refused.
 *
 * Bounded on purpose: two flat lists and one map, no paging, no query
 * parameters, nothing that grows with the number of customers.
 */
final class RegistrationOptionsController
{
    public function __invoke(BillingCurrencies $currencies): JsonResponse
    {
        $countries = $currencies->countries();
        $fallback = $currencies->recommendationFor(null);

        return response()->json([
            'data' => [
                /*
                 * Codes, not names. The portal labels them with the browser's
                 * own CLDR data, which has every country in both of this
                 * portal's languages and in the ones it does not speak yet.
                 */
                'countries' => array_map(
                    static fn (string $code): array => [
                        'code' => $code,
                        'currency' => $currencies->recommendedFor($code),
                        'currency_is_explicit' => $currencies->recommendationFor($code)['is_explicit'],
                    ],
                    $countries,
                ),

                'currencies' => $currencies->enabled(),

                /*
                 * The currency a country with no row of its own gets. Named
                 * here so the screen can say "billed in US dollars" rather
                 * than showing a default that arrived from nowhere.
                 */
                'fallback_currency' => $fallback['currency'],
            ],
            'meta' => [
                'countries_count' => count($countries),
                'currencies_count' => count($currencies->enabled()),
            ],
        ]);
    }
}
