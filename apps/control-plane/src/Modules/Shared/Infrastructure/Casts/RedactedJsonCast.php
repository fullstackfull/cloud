<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * A jsonb column of provider data, with its secrets stripped on the way in.
 *
 * Usage on a model:
 *
 *     protected function casts(): array
 *     {
 *         return ['provider_metadata' => RedactedJsonCast::class];
 *     }
 *
 * Redacting at the cast rather than at each call site is the point. A provider
 * response, a provisioning payload or a rendered install profile is a large,
 * nested, changing structure written by adapters the storing module never
 * sees, and the one place an API token or a generated root password will
 * eventually appear is the branch nobody remembered to sanitise. Here there is
 * no such branch: every write to the column goes through the redactor,
 * including mass assignment, updates and factory states.
 *
 * A provisioning payload is redacted too, and that is deliberate rather than
 * defensive. A credential must never be persisted in order to be sent to a
 * provider: passwords are generated at execution time and delivered out of
 * band. If one is put in the payload anyway, this is where it stops — the
 * handler receives "[redacted]", which fails loudly, instead of the platform
 * quietly keeping a customer's root password in a table half the support team
 * can read.
 *
 * The redaction is destructive by design. Provider payloads are kept to
 * explain to an operator what happened, and what happened can be explained
 * without the credentials it happened with.
 *
 * Declared with `mixed` on the way in: Eloquent hands a cast whatever was
 * assigned, and a provider payload arriving as something other than an array
 * is exactly the case set() wraps rather than drops.
 *
 * @implements CastsAttributes<array<string, mixed>|null, mixed>
 */
final class RedactedJsonCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        /** @var array<array-key, mixed> $data */
        $data = is_array($value) ? $value : ['value' => $value];

        return json_encode(
            app(SecretRedactor::class)->redact($data),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
