<?php

declare(strict_types=1);

namespace Lynomia\Http\Support;

use Illuminate\Http\Request;

/**
 * Which language a response should speak.
 *
 * One negotiation for the whole API. The catalogue used to be the only surface
 * that read `Accept-Language`, and it did so on its own; every error message,
 * validation sentence and localised reason now goes through the same rule, so
 * a request cannot be answered in Arabic by one field and in English by the
 * next.
 *
 * Negotiation is limited to the locales the platform actually serves, so an
 * `Accept-Language` naming something exotic lands on the platform default
 * rather than on an empty string or on whatever the client typed. Quality
 * values and region subtags are handled by Symfony's own matcher —
 * "ar-KW,ar;q=0.9,en;q=0.5" resolves to "ar", and "en-GB" to "en". The
 * fallback is deterministic: no header, an empty header, or a header nobody
 * can serve all resolve to `app.fallback_locale`.
 */
final class RequestLocale
{
    public static function for(Request $request): string
    {
        $fallback = (string) config('app.fallback_locale', 'en');

        /** @var list<string> $supported */
        $supported = array_values(array_filter((array) config('app.supported_locales', [])));

        if ($supported === [] || trim((string) $request->header('Accept-Language', '')) === '') {
            return $fallback;
        }

        // getPreferredLanguage returns the first candidate when nothing
        // matches, so the fallback leads the list and an unservable
        // Accept-Language degrades to it instead of to whichever locale
        // happens to be configured first.
        $candidates = array_values(array_unique([$fallback, ...$supported]));

        return $request->getPreferredLanguage($candidates) ?? $fallback;
    }
}
