<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A token asked for a per-minute ceiling higher than the tier allows.
 *
 * `rate_limit_per_minute` is read by the `api` limiter and *replaces* the
 * default for every request that token makes. A customer who could set it
 * freely could therefore switch their own throttle off, which is the one field
 * on this endpoint where a missing bound is not a validation nicety but the
 * removal of a platform control.
 *
 * Enforced in the action rather than only in the form request, so a queue job
 * or a future admin path that mints a token cannot raise the ceiling by not
 * going through HTTP.
 */
final class RateLimitAboveCeilingException extends DomainException
{
    public static function ceiling(int $requested, int $ceiling): self
    {
        $exception = new self(sprintf(
            'A token may not be given a ceiling above %d requests per minute.',
            $ceiling,
        ));

        return $exception->withContext([
            'requested_per_minute' => $requested,
            'maximum_per_minute' => $ceiling,
        ]);
    }

    public function errorCode(): string
    {
        return 'api_token.rate_limit_above_ceiling';
    }
}
