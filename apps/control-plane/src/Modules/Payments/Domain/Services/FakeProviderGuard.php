<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Payments\Domain\Exceptions\FakeProviderInProductionException;

/**
 * Refuses to let a fake provider exist in production.
 *
 * ProviderRegistryServiceProvider already fails the boot when the *configured*
 * driver is fake. This guard is the second, narrower line: it fires when a
 * fake is constructed at all, which also catches the cases config inspection
 * cannot see — a test double left bound in a service provider, a queue worker
 * started with a stale environment, a container binding overridden at runtime.
 *
 * It is a static assertion rather than an injected collaborator so that there
 * is no seam through which it can be swapped out, and so that a fake provider
 * cannot be constructed without paying for the check.
 */
final class FakeProviderGuard
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
