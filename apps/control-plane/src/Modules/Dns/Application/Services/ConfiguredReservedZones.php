<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Services;

use Lynomia\Modules\Dns\Domain\ValueObjects\ReservedZones;

/**
 * The one place the reserved zones are read from configuration.
 *
 * Two callers, and they must never disagree: the guard in the ClaimZone
 * action that refuses a claim, and the estate preflight finding that says
 * what that guard is holding. Both ask this class, so the report describes
 * the list the guard enforces rather than a second reading of the same
 * variables.
 *
 * Read on every call rather than once, because configuration is what the
 * operator changes to fix what the report told them, and the next claim has to
 * see the change.
 */
final readonly class ConfiguredReservedZones
{
    /**
     * The platform's own addresses: the variable an operator sets, and the
     * configuration key it lands in.
     *
     * The control plane's address and the portal's — the two names every
     * customer is sent to. The report names the variable, not the key,
     * because the variable is what the operator edits.
     *
     * @var array<string, string>
     */
    private const array PLATFORM_URLS = [
        'APP_URL' => 'app.url',
        'FRONTEND_URL' => 'app.frontend_url',
    ];

    public function read(): ReservedZones
    {
        /** @var list<string> $configured */
        $configured = array_map(
            static fn (mixed $entry): string => is_scalar($entry) ? (string) $entry : '',
            array_values((array) config('dns.reserved_zones', [])),
        );

        $urls = [];

        foreach (self::PLATFORM_URLS as $variable => $key) {
            $value = config($key);
            $urls[$variable] = is_string($value) ? $value : null;
        }

        return new ReservedZones($configured, $urls);
    }
}
