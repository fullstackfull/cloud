<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
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

    /** The only session driver whose rows the account-security surface can read. */
    private const string ENUMERABLE_SESSION_DRIVER = 'database';

    public function boot(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        if ($this->productionIsAnUnconfiguredDefault()) {
            /*
             * Nothing has been configured at all: no environment file, and no
             * APP_ENV in the environment either. Laravel's default for the
             * absent value is "production", so this is not a production system
             * — it is a machine that has not been configured yet, which is what
             * every build and tooling step looks like. Composer's package
             * discovery, `artisan test` on a fresh clone and the static
             * analyser booting the application all arrive here, and all three
             * were being refused with a message about production deployments.
             *
             * A twelve-factor container that carries its configuration in the
             * environment rather than in a file still sets APP_ENV=production
             * explicitly, so it is guarded exactly as before. The distinction is
             * between "production was chosen" and "production was what was left
             * when nothing was said".
             */
            Log::warning(
                'The provider guard did not run: no environment file and no APP_ENV, so '
                .'"production" is a default rather than a configured deployment.',
                ['environment_file' => $this->app->environmentFilePath()],
            );

            return;
        }

        $this->assertNoFakeProviders();
        $this->assertEveryConfiguredDriverExists();
        $this->assertSessionsAreEnumerable();
        $this->assertSuspensionPolicyIsUnderstood();
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
                .'Set the corresponding *_PROVIDER environment variables to a real driver.%s',
                implode(', ', $fake),
                $this->environmentHint(),
            ));
        }
    }

    /**
     * The suspension policy names something the platform can actually do.
     *
     * Read at boot rather than at the first suspension. The value is only
     * consulted when a customer stops paying, so a typo would otherwise sit
     * undiscovered until the night it matters and then surface as an exception
     * inside a queued listener.
     *
     * @throws RuntimeException
     */
    public function assertSuspensionPolicyIsUnderstood(): void
    {
        try {
            SuspensionPolicy::configured();
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage().$this->environmentHint(), previous: $e);
        }
    }

    /**
     * Whether this process is running as "production" only because nothing said
     * otherwise.
     *
     * Both halves matter. A missing environment file alone is not enough — a
     * container may legitimately carry everything in real environment variables
     * — so APP_ENV must also be absent from the environment before the guard
     * stands down.
     */
    private function productionIsAnUnconfiguredDefault(): bool
    {
        return ! $this->environmentWasNamed() && ! is_file($this->app->environmentFilePath());
    }

    /**
     * Whether APP_ENV is present in the process environment.
     *
     * Read from the superglobals and getenv() rather than through `env()`,
     * which this codebase forbids outside config/ for a reason that matters
     * exactly here: with a cached configuration Laravel never loads the dotenv
     * file, so `env()` answers null in a perfectly well configured production
     * deployment — and a guard that stood down on that answer would stand down
     * in production, which is the one place it exists for. The question being
     * asked is not "what is the configured environment" (that is
     * `$this->app->isProduction()`, already answered above) but "did anything
     * out there say so at all".
     */
    private function environmentWasNamed(): bool
    {
        foreach ([$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')] as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Says so when "production" is a default rather than a decision.
     *
     * With no environment file loaded, Laravel falls back to APP_ENV=production
     * and every provider to its packaged default, which is the fake — so this
     * guard fires during `composer install`'s package discovery on any machine
     * that has not written its .env yet. It is the guard working correctly in a
     * context nobody intended it for, and without this sentence it reads as a
     * misconfigured deployment. It cost a CI pipeline six red runs before
     * anybody looked at what the message actually meant.
     */
    private function environmentHint(): string
    {
        $path = $this->app->environmentFilePath();

        if (is_file($path)) {
            return '';
        }

        return sprintf(
            ' No environment file was found at %s, so APP_ENV defaulted to "production" '
            .'and every provider to its packaged default. If this is a build or install step '
            .'rather than a deployment, set APP_ENV for it.',
            $path,
        );
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
     * Only the families whose driver actually comes from configuration are
     * checked: payment, dns and now backup. Compute, dedicated and hosting
     * resolve per row — from the cluster's driver, the endpoint's protocol and
     * the node's panel — so a config value for those names nothing, and the
     * control that matters for them is the row-level refusal in each factory.
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

        /*
         * One key drives two adapters — forward DNS in the Dns module and
         * reverse DNS in Ipam — so a driver is only usable if both contain it.
         * The intersection, rather than either list, is what a deployment can
         * actually rely on: a driver present in one and missing from the other
         * boots fine and then fails on whichever half was not there.
         */
        $this->assertDriverExists(
            'backup',
            $providers['backup'] ?? null,
            BackupProviderFactory::drivers(),
        );

        $this->assertDriverExists(
            'dns',
            $providers['dns'] ?? null,
            array_values(array_intersect(
                DnsProviderFactory::drivers(),
                ReverseDnsProviderFactory::drivers(),
            )),
        );
    }

    /**
     * Refuses a production deployment whose sessions cannot be listed or
     * revoked.
     *
     * The account-security screen shows a customer every device signed in to
     * their account and lets them revoke one, or all the others. Both read and
     * write the `sessions` table, which only the database driver populates.
     * Under any other driver the platform does not fail — it answers. The
     * device list comes back empty, so a customer who suspects their password
     * has been taken is shown no intruder and reassured; "sign out other
     * devices" deletes nothing and answers 204, so they believe they have just
     * evicted whoever it was. A security feature that silently does nothing is
     * worse than one that is absent, because the absent one does not get
     * trusted.
     *
     * Redis remains the cache and queue backend; this is only about where a
     * session row lives.
     *
     * @throws RuntimeException
     */
    public function assertSessionsAreEnumerable(): void
    {
        $driver = config('session.driver');

        if (is_string($driver) && strtolower(trim($driver)) === self::ENUMERABLE_SESSION_DRIVER) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run in production with SESSION_DRIVER="%s". The account-security '
            .'surface lists and revokes a customer\'s signed-in devices through the `sessions` '
            .'table, which only the "%s" driver writes: under any other driver that screen shows '
            .'no devices to a customer who has them, and revocation silently does nothing. '
            .'Set SESSION_DRIVER=%s.',
            is_string($driver) ? $driver : gettype($driver),
            self::ENUMERABLE_SESSION_DRIVER,
            self::ENUMERABLE_SESSION_DRIVER,
        ));
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
