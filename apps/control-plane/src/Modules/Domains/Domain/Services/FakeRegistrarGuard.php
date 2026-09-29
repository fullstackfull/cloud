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
 *
 * ---------------------------------------------------------------------------
 * The question, and the refusal
 * ---------------------------------------------------------------------------
 *
 * {@see self::permitsTheFake()} is the predicate the refusal is made of, given
 * a name so that the one kind of caller that must know the answer *before* it
 * constructs can ask rather than learn by being thrown at: something that
 * enumerates every driver the build contains, like `domains:reconcile`'s
 * orphan scan, which used to throw on every production run because the list
 * it walked always holds the fake.
 *
 * Asking changes nothing about refusing. {@see self::assertNotProduction()}
 * throws on exactly the inputs it always did, the fake's constructor still
 * calls it, and nothing is made constructible in production. Whoever asks and
 * then constructs anyway is refused exactly as before — which is what keeps a
 * production `domains` row naming the fake loud.
 */
final class FakeRegistrarGuard
{
    /**
     * Whether the fake registrar may exist in this environment.
     *
     * Read-only. Public because its one caller outside this class lives in
     * another namespace of the module, and PHP offers nothing narrower.
     */
    public static function permitsTheFake(?Application $app = null): bool
    {
        $app ??= app();

        return ! $app->isProduction();
    }

    /**
     * @throws FakeRegistrarInProductionException
     */
    public static function assertNotProduction(string $providerName, ?Application $app = null): void
    {
        if (! self::permitsTheFake($app)) {
            throw FakeRegistrarInProductionException::forProvider($providerName);
        }
    }
}
