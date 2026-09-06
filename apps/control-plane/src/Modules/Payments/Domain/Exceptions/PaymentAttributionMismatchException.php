<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A payment was presented for settlement by someone other than its payer.
 *
 * The return-from-checkout flow takes a payment reference out of a URL, and a
 * URL is guessable and shareable. Asking the provider whether that payment
 * succeeded is only half the check: the other half is asking who it belongs
 * to. Without it, a signed-in customer can hand back a reference from someone
 * else's checkout and have a real capture recorded against their own account.
 *
 * The payer of record is the customer id in the metadata we attached when the
 * intent was created, read back from the provider. Anything else — the
 * session, the query string, the request body — is a claim.
 */
final class PaymentAttributionMismatchException extends DomainException
{
    public static function between(string $provider, string $reference, string $claimed, string $actual): self
    {
        $exception = new self(sprintf(
            'The %s payment %s belongs to another customer and cannot be settled by %s.',
            $provider,
            $reference,
            $claimed,
        ));

        return $exception->withContext([
            'provider' => $provider,
            'provider_reference' => $reference,
            'claimed_customer_id' => $claimed,
            // The real payer is deliberately not exposed to the caller's
            // error body; it is here for the operator reading the log.
            'actual_customer_id' => $actual,
        ]);
    }

    public function errorCode(): string
    {
        return 'payment.attribution_mismatch';
    }

    /**
     * 403 rather than 422: the request is well formed, and the reason it is
     * refused is that it concerns someone else's payment.
     */
    public function httpStatus(): int
    {
        return 403;
    }
}
