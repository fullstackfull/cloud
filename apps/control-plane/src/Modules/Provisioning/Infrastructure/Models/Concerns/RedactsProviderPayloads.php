<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Stores a jsonb column of provider data with its secrets stripped on the way
 * in.
 *
 * Redacting at the mutator rather than at each call site is the point. A
 * provisioning payload or a provider response is a large, nested, changing
 * structure written by adapters this module never sees, and the one place an
 * API token or a generated root password will eventually appear is the branch
 * nobody remembered to sanitise. Here there is no such branch: every write to
 * these columns goes through the redactor, including mass assignment, updates
 * and factory states.
 *
 * The job payload is redacted too, and that is deliberate rather than
 * defensive. A credential must never be persisted in order to be sent to a
 * provider: passwords are generated at execution time and delivered out of
 * band. If one is put in the payload anyway, this is where it stops — the
 * handler will receive "[redacted]", which fails loudly, instead of the
 * platform quietly keeping a customer's root password in a table half the
 * support team can read.
 */
trait RedactsProviderPayloads
{
    /**
     * @return Attribute<array<string, mixed>|null, string|null>
     */
    protected static function redactedJsonAttribute(): Attribute
    {
        return Attribute::make(
            get: static function (mixed $value): ?array {
                if ($value === null) {
                    return null;
                }

                if (is_array($value)) {
                    return $value;
                }

                /** @var array<string, mixed>|null $decoded */
                $decoded = json_decode((string) $value, true);

                return is_array($decoded) ? $decoded : null;
            },
            set: static function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                /** @var array<array-key, mixed> $data */
                $data = is_array($value) ? $value : ['value' => $value];

                return json_encode(
                    app(SecretRedactor::class)->redact($data),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            },
        );
    }
}
