<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * A payment provider refused or failed a request.
 *
 * Adapters translate every SDK exception into this one so that nothing outside
 * Infrastructure/Providers has to know a provider's exception hierarchy — and,
 * more importantly, so that a raw SDK exception (whose message and http body
 * routinely contain the request that carried the API key) never reaches a log
 * or an error response. The message here is composed by us; the provider's own
 * text only travels in the redacted context.
 */
final class PaymentProviderException extends DomainException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function requestFailed(
        string $provider,
        string $operation,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        $exception = new self(
            sprintf('The %s provider could not complete the "%s" request.', $provider, $operation),
            previous: $previous,
        );

        return $exception->withContext([
            'provider' => $provider,
            'operation' => $operation,
            ...$context,
        ]);
    }

    public function errorCode(): string
    {
        return 'payment.provider_request_failed';
    }

    /**
     * A provider failure is not the caller's fault, and retrying the same
     * request is usually the right response.
     */
    public function httpStatus(): int
    {
        return 502;
    }
}
