<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The payload carried a valid signature but is not an event we can identify.
 *
 * Without a provider event id there is nothing to key the replay defence on,
 * so the event cannot be stored idempotently and must not be acted on.
 */
final class MalformedWebhookPayloadException extends DomainException
{
    public static function forProvider(string $provider, string $reason): self
    {
        $exception = new self(sprintf('The %s webhook payload could not be interpreted: %s.', $provider, $reason));

        return $exception->withContext(['provider' => $provider, 'reason' => $reason]);
    }

    public function errorCode(): string
    {
        return 'payment.webhook_payload_malformed';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
