<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Estate\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Estate\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Estate\Infrastructure\Testers\FakeConnectionTester;

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
final class EstateServiceProvider extends ServiceProvider
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
     * @return array<string, class-string<ConnectionTester>>
     */
    private function testers(): array
    {
        $testers = [];

        if (! $this->app->environment('production')) {
            $testers['fake'] = FakeConnectionTester::class;
        }

        return $testers;
    }
}
