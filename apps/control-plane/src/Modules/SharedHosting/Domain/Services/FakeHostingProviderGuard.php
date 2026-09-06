<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Services;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\FakeHostingProviderInProductionException;

/**
 * Refuses to let the fake panel exist in production.
 *
 * The fake reports accounts as created without creating them. In production
 * that means services marked active, welcome mail sent with login details for
 * an account that does not exist, and invoices raised for hosting nobody is
 * serving — with the customer, not the platform, discovering it.
 *
 * It fires when the fake is CONSTRUCTED, which catches what inspecting
 * configuration cannot see: a hosting_nodes row whose panel column says
 * "fake", a test double left bound in a service provider, a queue worker
 * started with a stale environment.
 *
 * A static assertion rather than an injected collaborator, so that there is no
 * seam through which it can be swapped out and no way to construct the fake
 * without paying for the check.
 */
final class FakeHostingProviderGuard
{
    /**
     * @throws FakeHostingProviderInProductionException
     */
    public static function assertNotProduction(string $panel, ?Application $app = null): void
    {
        $app ??= app();

        if ($app->isProduction()) {
            throw FakeHostingProviderInProductionException::forPanel($panel);
        }
    }
}
