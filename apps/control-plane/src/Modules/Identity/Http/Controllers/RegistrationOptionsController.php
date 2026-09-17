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

                /*
                 * Where the documents the checkbox names are published, or
                 * null for each one that is not.
                 *
                 * The screen asks a customer to accept a terms of service and
                 * an acceptable use policy. Without these, it names two
                 * documents and offers no way to read either — so the URLs
                 * come from configuration and travel with the rest of what a
                 * registration form is allowed to offer, rather than being
                 * built into the portal where publishing them would be a code
                 * change and a deployment.
                 *
                 * Null is a truthful answer and the screen renders it as one:
                 * the documents are a legal deliverable this repository does
                 * not write, and a link to a page nobody has written would be
                 * a worse answer than no link.
                 */
                'legal' => [
                    'terms_url' => $this->publishedUrl('legal.terms_url'),
                    'aup_url' => $this->publishedUrl('legal.aup_url'),
                ],
            ],
            'meta' => [
                'countries_count' => count($countries),
                'currencies_count' => count($currencies->enabled()),
            ],
        ]);
    }

    /**
     * A configured document URL, or null.
     *
     * Only `http` and `https` are handed to a browser. The value is an
     * operator's, so this is not a trust boundary in the usual sense — but a
     * `javascript:` or `data:` URL reaching an anchor on an unauthenticated
     * page would turn a configuration mistake into a script running in every
     * visitor's browser, and refusing a scheme is cheaper than explaining
     * that. A value this refuses reads as "not published", which is the same
     * answer an unset key gives and the one the screen already handles.
     */
    private function publishedUrl(string $key): ?string
    {
        $configured = config($key);

        if (! is_string($configured)) {
            return null;
        }

        $url = trim($configured);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], strict: true) ? $url : null;
    }
}
