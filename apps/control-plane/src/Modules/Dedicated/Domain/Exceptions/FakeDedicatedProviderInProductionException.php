<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The fake BMC adapter was constructed while running in production.
 *
 * The fake reports healthy hardware, accepts power operations and confirms
 * one-time PXE without contacting anything. In production it would mark
 * servers active, start subscriptions and hand over credentials for machines
 * that were never installed — and nothing would look wrong until the customer
 * tried to log in. Worse in the other direction: an operator reading its
 * invented "OK" would believe a failing disk is healthy.
 *
 * There is no safer degraded behaviour to fall back to, so the object refuses
 * to exist at all.
 */
final class FakeDedicatedProviderInProductionException extends DomainException
{
    public static function forProvider(string $provider): self
    {
        $exception = new self(sprintf(
            'Refusing to resolve the fake dedicated-server provider "%s" in production. '
            .'It reports hardware as healthy and installs as complete without contacting a BMC. '
            .'Set DEDICATED_PROVIDER to a real driver.',
            $provider,
        ));

        return $exception->withContext(['provider' => $provider, 'environment' => 'production']);
    }

    public function errorCode(): string
    {
        return 'dedicated.fake_provider_in_production';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
