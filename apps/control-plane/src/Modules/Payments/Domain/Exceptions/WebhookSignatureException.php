<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An inbound webhook failed signature verification.
 *
 * The payload that produced this is never parsed, stored or acted on. A
 * webhook endpoint is a public URL: anything that reaches it is attacker
 * input until the HMAC says otherwise, and "store it so we can look at it
 * later" is exactly how an unverified payload ends up being replayed by an
 * operator debugging a queue.
 *
 * The reason travels in the context, never the failed payload or the header
 * that was presented.
 */
final class WebhookSignatureException extends DomainException
{
    public static function rejected(string $provider, string $reason): self
    {
        $exception = new self(sprintf('Rejected an unverified %s webhook: %s.', $provider, $reason));

        return $exception->withContext(['provider' => $provider, 'reason' => $reason]);
    }

    public function errorCode(): string
    {
        return 'payment.webhook_signature_invalid';
    }

    /**
     * 400 rather than 401: the provider must not retry a payload it cannot
     * sign correctly, and a 401 would invite a credential-refresh loop.
     */
    public function httpStatus(): int
    {
        return 400;
    }
}
