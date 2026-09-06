<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Dedicated\Domain\Exceptions\FakeDedicatedProviderInProductionException;

/**
 * Refuses to let the fake BMC adapter exist in production.
 *
 * Configuration inspection at boot is the first line and cannot see
 * everything: a test double left bound in a service provider, a queue worker
 * started with a stale environment, a factory branch taken at runtime. This
 * guard fires when the fake is CONSTRUCTED, which no such path avoids.
 *
 * It is a static assertion rather than an injected collaborator so that there
 * is no seam through which it can be swapped out, and so the fake cannot be
 * built without paying for the check.
 */
final class FakeDedicatedProviderGuard
{
    /**
     * @throws FakeDedicatedProviderInProductionException
     */
    public static function assertNotProduction(string $providerName, ?Application $app = null): void
    {
        $app ??= app();

        if ($app->isProduction()) {
            throw FakeDedicatedProviderInProductionException::forProvider($providerName);
        }
    }
}
