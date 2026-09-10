<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Http\Support;

use Illuminate\Http\Request;
use Lynomia\Http\Support\RequestLocale as SharedRequestLocale;

/**
 * Which language a response should speak.
 *
 * Localised catalogue copy is stored as a jsonb map keyed by locale, and the
 * API returns a resolved string rather than the map: a client that received
 * every translation would have to reimplement this negotiation, and would get
 * it subtly differently.
 *
 * Negotiation is the platform's, in Lynomia\Http\Support\RequestLocale: the
 * catalogue was the first surface to speak the customer's language and is no
 * longer the only one.
 */
final class RequestLocale
{
    /**
     * Delegates to the platform-wide negotiation, which the SetRequestLocale
     * middleware has already applied to the application by the time a
     * catalogue resource renders. Kept as a method so the resources that
     * built this module keep reading the answer from one named place.
     */
    public static function for(Request $request): string
    {
        return SharedRequestLocale::for($request);
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
            ?? (string) array_values($values)[0];
    }
}
