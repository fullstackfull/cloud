<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\Providers\Infrastructure\Testers\CloudflareConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\CpanelConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\DirectAdminConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\FakeConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\IpmiConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\ProxmoxBackupConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\ProxmoxConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\RedfishConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\StripeConnectionTester;
use Tests\Architecture\EveryRealDriverHasAnIdentityTesterTest;

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
 * The fake tester is registered only outside production, once per controlled
 * driver. It also refuses to be constructed there, and refuses a provider row
 * whose own environment is production wherever it is built — three controls,
 * because they fail in different ways: the first depends on the deployment
 * environment being read correctly at boot, the second depends on nothing, and
 * the third catches the case neither of the others can see, a production
 * provider row being tested from a staging deployment.
 *
 * Every other driver in the catalogue with a real adapter now has a real
 * tester, and each one identifies its product from something only that product
 * says before it concludes anything at all. Two drivers deliberately have
 * none, and the reasons are recorded in
 * {@see EveryRealDriverHasAnIdentityTesterTest} rather
 * than left to be rediscovered.
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
        /*
         * One entry per driver, and a closure wherever a tester answers for
         * more than one driver name or must not be built by the container.
         *
         * The Stripe tester is built here rather than resolved, deliberately:
         * its constructor takes an optional client so that a test can inject
         * one, and the container would otherwise hand it the application's own
         * configured StripeClient — which carries the key from configuration
         * instead of the key on the credential reference being tested. A
         * tester that tested a different credential from the one asked about
         * would pass and mean nothing.
         */
        $testers = [
            'proxmox' => ProxmoxConnectionTester::class,
            'proxmox_backup' => ProxmoxBackupConnectionTester::class,
            'cpanel' => CpanelConnectionTester::class,
            'directadmin' => DirectAdminConnectionTester::class,
            'cloudflare' => fn (): ConnectionTester => new CloudflareConnectionTester('cloudflare'),
            'cloudflare_rdns' => fn (): ConnectionTester => new CloudflareConnectionTester('cloudflare_rdns'),
            'stripe' => fn (): ConnectionTester => new StripeConnectionTester,
            'redfish' => fn (): ConnectionTester => new RedfishConnectionTester('redfish'),
            'ilo' => fn (): ConnectionTester => new RedfishConnectionTester('ilo'),
            'ipmi' => IpmiConnectionTester::class,
        ];

        if (! $this->app->environment('production')) {
            $environment = (string) $this->app->environment();

            /*
             * One fake tester, one driver name per controlled driver.
             *
             * Iterated rather than listed: the two entries that used to be
             * here were `fake` and `fake_bmc`, and a controlled driver added
             * to the catalogue without a line here is a catalogued driver
             * nothing can test — which the readiness engine reports as a
             * configuration blocker and an operator reads as a platform fault.
             *
             * The tester is told which driver it answers for because it
             * reports that driver's own capabilities: a controlled compute
             * driver must not claim GPU passthrough because the category asks
             * about it.
             */
            foreach (ControlledDriver::cases() as $driver) {
                $testers[$driver->value] = fn (): ConnectionTester => new FakeConnectionTester($environment, $driver->value);
            }
        }

        return $testers;
    }
}
