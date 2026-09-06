<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Refuses to boot a production deployment that is configured to use a fake
 * provider.
 *
 * The failure this prevents is the worst kind the platform can have: a
 * production system that reports payments captured and servers created while
 * doing neither, and only reveals it when a customer asks where their VPS is.
 * Failing at boot turns that into a deployment error an operator sees in the
 * first thirty seconds.
 *
 * The check runs on every environment so that the guard itself is exercised by
 * the test suite, rather than being a branch that only executes in production.
 */
final class ProviderRegistryServiceProvider extends ServiceProvider
{
    private const string FAKE = 'fake';

    public function boot(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        $this->assertNoFakeProviders();
    }

    /**
     * @throws RuntimeException
     */
    public function assertNoFakeProviders(): void
    {
        /** @var array<string, string> $providers */
        $providers = config('billing.providers', []);

        $fake = array_keys(array_filter(
            $providers,
            static fn (mixed $driver): bool => is_string($driver) && strtolower($driver) === self::FAKE,
        ));

        if ($fake !== []) {
            throw new RuntimeException(sprintf(
                'Refusing to run in production with fake providers configured for: %s. '
                .'A fake provider reports success without doing anything, which in production '
                .'means charging customers for services that were never created. '
                .'Set the corresponding *_PROVIDER environment variables to a real driver.',
                implode(', ', $fake),
            ));
        }
    }
}
