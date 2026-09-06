<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Stores a jsonb column of provider data with its secrets stripped on the way
 * in.
 *
 * Redacting at the mutator rather than at each call site is the point: a
 * provider response is a large, nested, changing structure, and the one place
 * a `client_secret` or a raw `Authorization` header will eventually appear is
 * the branch nobody remembered to sanitise. Here there is no such branch —
 * every write to the column goes through the redactor, including mass
 * assignment, updates and factory states.
 *
 * The redaction is destructive by design. We keep provider payloads to explain
 * to an operator what happened, and a payment can be explained without its
 * credentials.
 */
trait RedactsJsonSecrets
{
    /**
     * @return Attribute<array<string, mixed>|null, string|null>
     */
    protected function providerMetadata(): Attribute
    {
        return self::redactedJsonAttribute();
    }

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
