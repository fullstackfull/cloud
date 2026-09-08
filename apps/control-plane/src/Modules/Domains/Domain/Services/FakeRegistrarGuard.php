<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Domains\Domain\Exceptions\FakeRegistrarInProductionException;

/**
 * Refuses to let the fake registrar exist in production.
 *
 * The narrow line, behind whatever configuration inspection the boot does. It
 * fires when the fake is *constructed*, which catches what reading config
 * cannot: a TLD row whose provider column says `fake`, a binding left in a
 * service provider, a queue worker started with a stale environment.
 *
 * Static rather than injected, so there is no seam to swap it out through and
 * no way to build the fake without paying for the check.
 */
final class FakeRegistrarGuard
{
    /**
     * @throws FakeRegistrarInProductionException
     */
    public static function assertNotProduction(string $providerName, ?Application $app = null): void
    {
        $app ??= app();

        if ($app->isProduction()) {
            throw FakeRegistrarInProductionException::forProvider($providerName);
        }
    }
}
