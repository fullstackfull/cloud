<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Compute\Domain\Exceptions\FakeProviderInProductionException;

/**
 * Refuses to let the fake hypervisor exist in production.
 *
 * ProviderRegistryServiceProvider already fails the boot when the configured
 * driver is fake. This guard is the second, narrower line: it fires when the
 * fake is constructed at all, which catches what config inspection cannot see
 * — a cluster row whose driver column says "fake", a test double left bound in
 * a service provider, a queue worker started with a stale environment.
 *
 * It is a static assertion rather than an injected collaborator so that there
 * is no seam through which it can be swapped out, and so that the fake cannot
 * be constructed without paying for the check.
 */
final class FakeComputeProviderGuard
{
    /**
     * @throws FakeProviderInProductionException
     */
    public static function assertNotProduction(string $providerName, ?Application $app = null): void
    {
        $app ??= app();

        if ($app->isProduction()) {
            throw FakeProviderInProductionException::forProvider($providerName);
        }
    }
}
