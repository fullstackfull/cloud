<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Providers\Infrastructure\Testers\FakeConnectionTester;

/**
 * Wires the control centre.
 *
 * Two things here are decisions rather than plumbing.
 *
 * The tester factory is bound with `bind` rather than `singleton`, for the
 * reason every provider factory in this codebase is: a test that swaps one
 * driver must not have to rebuild the world, and a factory resolved once at
 * boot hands every later caller the bindings that existed at boot.
 *
 * The fake tester is registered only outside production. It also refuses to be
 * constructed there — belt and braces, because these two controls fail in
 * different ways: this one depends on the environment being read correctly at
 * boot, and the constructor guard depends on nothing.
 */
final class ProvidersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SecretResolver::class, ControllerEnvironmentSecretResolver::class);

        $this->app->bind(ConnectionTesterFactory::class, function (): ConnectionTesterFactory {
            return new ConnectionTesterFactory($this->app, $this->testers());
        });

        $this->app->bind(FakeConnectionTester::class, function (): FakeConnectionTester {
            return new FakeConnectionTester((string) $this->app->environment());
        });
    }

    /**
     * @return array<string, class-string<ConnectionTester>|\Closure(): ConnectionTester>
     */
    private function testers(): array
    {
        $testers = [];

        if (! $this->app->environment('production')) {
            $environment = (string) $this->app->environment();

            // One fake, two driver names: a remote account and a machine's
            // BMC. Both refuse to be built in production.
            $testers['fake'] = fn (): ConnectionTester => new FakeConnectionTester($environment, 'fake');
            $testers['fake_bmc'] = fn (): ConnectionTester => new FakeConnectionTester($environment, 'fake_bmc');
        }

        return $testers;
    }
}
