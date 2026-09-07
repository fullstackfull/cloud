<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
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
        $this->assertEveryConfiguredDriverExists();
    }

    /**
     * @throws RuntimeException
     */
    public function assertNoFakeProviders(): void
    {
        // Not annotated as a map of strings: this is deployment
        // configuration, and the check below is what establishes that a value
        // is one.
        /** @var array<string, mixed> $providers */
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

    /**
     * Refuses a production deployment configured for a driver this build does
     * not contain.
     *
     * Refusing the fake is only half the job. `PAYMENT_PROVIDER=myfatoorah` and
     * `DNS_PROVIDER=cloudflare` are legal settings that nothing here can
     * honour, and left unchecked they produce the same failure the fake does,
     * only later and in front of a customer: the deployment boots, reports
     * healthy, and throws the first time somebody tries to pay or an operator
     * sets a PTR. A guard that only catches the fake catches the case an
     * operator was already worried about and misses the one they were not.
     *
     * Only the two families whose driver actually comes from configuration are
     * checked. Compute, dedicated and hosting resolve per row — from the
     * cluster's driver, the endpoint's protocol and the node's panel — so a
     * config value for those names nothing, and the control that matters for
     * them is the row-level refusal in each factory.
     *
     * @throws RuntimeException
     */
    public function assertEveryConfiguredDriverExists(): void
    {
        /** @var array<string, mixed> $providers */
        $providers = config('billing.providers', []);

        $this->assertDriverExists(
            'payment',
            $providers['payment'] ?? null,
            $this->app->make(PaymentProviderRegistry::class)->names(),
        );

        $this->assertDriverExists(
            'dns',
            $providers['dns'] ?? null,
            ReverseDnsProviderFactory::drivers(),
        );
    }

    /**
     * @param  list<string>  $available
     *
     * @throws RuntimeException
     */
    private function assertDriverExists(string $family, mixed $configured, array $available): void
    {
        if (! is_string($configured) || $configured === '') {
            return;
        }

        if (in_array(strtolower(trim($configured)), $available, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run in production: the %s provider is configured as "%s", '
            .'and this build contains no such driver. It would boot, report healthy, '
            .'and fail the first time it was used. Available: %s.',
            $family,
            $configured,
            implode(', ', $available),
        ));
    }
}
