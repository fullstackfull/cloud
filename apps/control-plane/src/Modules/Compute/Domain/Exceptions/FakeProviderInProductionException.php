<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The fake hypervisor was constructed while running in production.
 *
 * The fake reports every machine as created without contacting anything, so in
 * production it would mark services active, start subscriptions and send
 * credentials for servers that do not exist — and nothing would look wrong
 * until the customer tried to log in. There is no safer degraded behaviour to
 * fall back to, so the object refuses to exist at all.
 */
final class FakeProviderInProductionException extends DomainException
{
    public static function forProvider(string $provider): self
    {
        $exception = new self(sprintf(
            'Refusing to resolve the fake compute provider "%s" in production. '
            .'It reports machines as created without creating them. Set COMPUTE_PROVIDER to a real driver.',
            $provider,
        ));

        return $exception->withContext(['provider' => $provider, 'environment' => 'production']);
    }

    public function errorCode(): string
    {
        return 'compute.fake_provider_in_production';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
