<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A fake provider was resolved while running in production.
 *
 * This is the single worst failure the payments module can have: the fake
 * reports every charge as captured without contacting anyone, so production
 * would provision paid services, mark invoices settled and send receipts for
 * money that was never taken — and nothing would look wrong until a
 * reconciliation weeks later.
 *
 * The guard therefore refuses to construct the object at all rather than
 * degrading to a safer behaviour, because there is no safe behaviour here.
 */
final class FakeProviderInProductionException extends DomainException
{
    public static function forProvider(string $provider): self
    {
        $exception = new self(sprintf(
            'Refusing to resolve the fake payment provider "%s" in production. '
            .'It reports captures that never happened. Set PAYMENT_PROVIDER to a real driver.',
            $provider,
        ));

        return $exception->withContext(['provider' => $provider, 'environment' => 'production']);
    }

    public function errorCode(): string
    {
        return 'payment.fake_provider_in_production';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
