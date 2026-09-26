<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Lynomia\Modules\Infrastructure\Application\Reference\LoadReferenceTopologyForSimulation;
use Lynomia\Modules\Payments\Domain\Exceptions\FakeProviderInProductionException;
use Lynomia\Modules\Payments\Domain\Services\FakeProviderGuard;
use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;
use Lynomia\Support\Environment\SettleTheApplicationEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * F-16, the console half: a miscapitalised `--env=` flag must not disarm the
 * production guards.
 *
 * config/app.php normalises APP_ENV, and AMiscapitalisedEnvironmentCannot
 * DisarmTheProductionGuardsTest pins that. But the guards do not compare
 * `config('app.env')`: `isProduction()` and `environment('production')` compare
 * `$app['env']`, and in a console process Laravel's EnvironmentDetector sets
 * that from an `--env=` argument, verbatim, over the configured value. Measured
 * before this fix: `APP_ENV=production php artisan env --env=Production`
 * printed "The application environment is [Production]" and no guard refused.
 *
 * The first group runs artisan in a separate process, because that is the only
 * way to reach Laravel's own argv parsing and the boot-time guard in
 * ProviderRegistryServiceProvider; the suite's own environment names fake
 * providers for every category, so a process that believes it is production
 * must refuse to boot. The second group re-runs the real LoadConfiguration
 * bootstrapper in this process under a crafted argv, which is what lets it ask
 * the guards that sit behind boot - the reference-topology refusal, the
 * readiness gate, a fake provider's own guard - what they now see.
 */
final class AnEnvFlagCannotDisarmTheProductionGuardsTest extends TestCase
{
    private const string REFUSAL = 'Refusing to run in production with fake providers configured for';

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function flagsThatMeanProduction(): array
    {
        return [
            'Production on a production host' => ['production', ['--env=Production']],
            'PRODUCTION on a production host' => ['production', ['--env=PRODUCTION']],
            'padded on a production host' => ['production', ['--env= production']],
            'space-separated form' => ['production', ['--env', 'Production']],
            'empty flag fails closed' => ['production', ['--env=']],
            'another environment cannot talk a production host out of it' => ['production', ['--env=local']],
            'Production on a testing host' => ['testing', ['--env=Production']],
        ];
    }

    /**
     * @param  list<string>  $flag
     */
    #[Test]
    #[DataProvider('flagsThatMeanProduction')]
    public function artisan_refuses_to_boot_with_fake_providers_whatever_the_flag_says(string $appEnv, array $flag): void
    {
        $process = $this->artisanEnv($appEnv, $flag);

        $this->assertFalse(
            $process->isSuccessful(),
            'artisan booted as "'.trim($process->getOutput()).'" with fake providers configured; the production guard stood down.',
        );
        $this->assertStringContainsString(self::REFUSAL, $process->getOutput().$process->getErrorOutput());
    }

    /**
     * The positive control: a flag that genuinely names another environment,
     * on a host that is not production, is honoured, and the process boots.
     */
    #[Test]
    public function a_flag_naming_testing_on_a_testing_host_boots_as_testing(): void
    {
        $process = $this->artisanEnv('testing', ['--env=Testing']);

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('[testing]', $process->getOutput());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function miscapitalisedFlags(): array
    {
        return [['--env=Production'], ['--env=PRODUCTION'], ['--env=ProDuCtIoN'], ['--env=']];
    }

    #[Test]
    #[DataProvider('miscapitalisedFlags')]
    public function every_guard_behind_boot_sees_production_under_a_miscapitalised_flag(string $flag): void
    {
        $this->bootstrapConfigurationWithArgv(['artisan', 'tinker', $flag]);

        $this->assertSame('production', $this->app['env']);
        $this->assertSame('production', config('app.env'), 'config(app.env) and $app[env] disagree.');
        $this->assertTrue($this->app->isProduction());
        $this->assertTrue($this->app->environment('production'));

        $this->assertTrue(
            $this->app->make(ProductSellability::class)->enforced(),
            'The readiness gate is not enforced under '.$flag.'.',
        );

        try {
            $this->app->make(LoadReferenceTopologyForSimulation::class)->execute();
            $this->fail('The reference topology was loaded under '.$flag.'.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('must never be loaded into a production installation', $e->getMessage());
        }

        $this->expectException(FakeProviderInProductionException::class);
        FakeProviderGuard::assertNotProduction('fake', $this->app);
    }

    #[Test]
    public function without_a_flag_the_configured_environment_stands(): void
    {
        $this->bootstrapConfigurationWithArgv(['artisan', 'tinker']);

        $this->assertSame('testing', $this->app['env']);
        $this->assertFalse($this->app->isProduction());
    }

    /**
     * The one case where the configured `production` is not allowed to win:
     * nothing configured anything (no APP_ENV, no environment file, no cached
     * configuration), so `production` is only Laravel's default for the absent
     * value, and the flag is the only thing that said anything.
     */
    #[Test]
    public function on_an_unconfigured_machine_the_flag_decides_but_is_still_normalised(): void
    {
        $saved = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $base = sys_get_temp_dir().'/r3-unconfigured-'.bin2hex(random_bytes(4));
        mkdir($base.'/bootstrap/cache', 0777, true);

        try {
            unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
            putenv('APP_ENV');

            foreach (['Local' => 'local', 'Production' => 'production', '' => 'production'] as $flag => $expected) {
                $app = new Application($base);
                $app->instance('config', new Repository(['app' => ['env' => 'production']]));
                $app['env'] = $flag;

                (new SettleTheApplicationEnvironment)($app);

                $this->assertSame($expected, $app['env'], 'flag ['.$flag.']');
                $this->assertSame($expected, $app->make('config')->get('app.env'));
            }

            // Once APP_ENV is present, a configured production wins over the flag.
            putenv('APP_ENV=production');
            $app = new Application($base);
            $app->instance('config', new Repository(['app' => ['env' => 'production']]));
            $app['env'] = 'local';
            (new SettleTheApplicationEnvironment)($app);
            $this->assertSame('production', $app['env']);
        } finally {
            [$server, $env, $get] = $saved;
            if ($server === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $server;
            }
            if ($env === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $env;
            }
            $get === false ? putenv('APP_ENV') : putenv('APP_ENV='.$get);
            @rmdir($base.'/bootstrap/cache');
            @rmdir($base.'/bootstrap');
            @rmdir($base);
        }
    }

    /**
     * Re-run the real LoadConfiguration bootstrapper, as the console kernel
     * does, with `$_SERVER['argv']` standing in for the command line. The
     * `bootstrapped:` event it dispatches is what bootstrap/app.php hangs the
     * settling step on, so this exercises the shipped wiring, not the class
     * alone.
     *
     * @param  list<string>  $argv
     */
    private function bootstrapConfigurationWithArgv(array $argv): void
    {
        $saved = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = $argv;

        try {
            $this->app->bootstrapWith([LoadConfiguration::class]);
        } finally {
            $_SERVER['argv'] = $saved;
        }
    }

    /**
     * @param  list<string>  $flag
     */
    private function artisanEnv(string $appEnv, array $flag): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'env', ...$flag],
            base_path(),
            ['APP_ENV' => $appEnv, 'APP_CONFIG_CACHE' => false],
            null,
            120,
        );
        $process->run();

        return $process;
    }
}
