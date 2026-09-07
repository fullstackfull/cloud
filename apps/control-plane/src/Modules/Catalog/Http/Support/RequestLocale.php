<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Support;

use Illuminate\Http\Request;

/**
 * Which language a response should speak.
 *
 * Localised catalogue copy is stored as a jsonb map keyed by locale, and the
 * API returns a resolved string rather than the map: a client that received
 * every translation would have to reimplement this negotiation, and would get
 * it subtly differently.
 *
 * Negotiation is limited to the locales the platform actually serves, so an
 * Accept-Language naming something exotic lands on the platform default rather
 * than on an empty string. Quality values and region subtags are handled by
 * Symfony's own matcher — "ar-KW,ar;q=0.9,en;q=0.5" resolves to "ar".
 */
final class RequestLocale
{
    public static function for(Request $request): string
    {
        $fallback = (string) config('app.fallback_locale', 'en');

        /** @var list<string> $supported */
        $supported = array_values(array_filter((array) config('app.supported_locales', [])));

        if ($supported === [] || ! $request->hasHeader('Accept-Language')) {
            return $fallback;
        }

        // getPreferredLanguage returns the first candidate when nothing
        // matches, so the fallback leads the list and an unservable
        // Accept-Language degrades to it instead of to whichever locale
        // happens to be configured first.
        $candidates = array_values(array_unique([$fallback, ...$supported]));

        return $request->getPreferredLanguage($candidates) ?? $fallback;
    }

    /**
     * Resolves one localised jsonb map to a single string.
     *
     * Falls back to the platform default and then to whatever translation
     * exists, so a locale added before its copy renders the other language
     * rather than an empty string — the same rule the models apply to `name`,
     * applied to the columns that have no accessor of their own.
     *
     * @param  array<string, string>|null  $values
     */
    public static function resolve(?array $values, string $locale): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        return $values[$locale]
            ?? $values[(string) config('app.fallback_locale')]
            ?? (string) (array_values($values)[0] ?? '');
    }
}
