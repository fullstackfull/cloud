<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every production guard in this platform is an exact string comparison
 * against `production` - `$app->isProduction()` and `$app->environment(
 * 'production')` both compare `config('app.env')` verbatim. There are 38 such
 * call sites, and they include the ones that refuse a fake payment, compute,
 * dedicated, hosting, DNS or backup driver, the one that refuses to load the
 * reference estate into a real database, and the one that decides whether the
 * readiness ladder is enforced at all.
 *
 * So a single miscapitalised `APP_ENV=Production` disarmed all of them at
 * once: the value is never normalised, `'Production' !== 'production'`, and
 * every guard silently decided it was not in production.
 *
 * An unset APP_ENV already failed closed, because config/app.php defaults to
 * `production`. A misspelt one did not. These tests pin both directions.
 */
final class AMiscapitalisedEnvironmentCannotDisarmTheProductionGuardsTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function miscapitalisations(): array
    {
        return [['Production'], ['PRODUCTION'], ['ProDuCtIoN'], [' production'], ["production\n"]];
    }

    #[DataProvider('miscapitalisations')]
    public function test_a_miscapitalised_production_env_still_reads_as_production(string $value): void
    {
        $this->assertSame('production', $this->envFor($value));
    }

    public function test_an_unset_environment_still_fails_closed(): void
    {
        $this->assertSame('production', $this->envFor(null));
    }

    public function test_an_empty_environment_fails_closed_rather_than_becoming_blank(): void
    {
        $this->assertSame('production', $this->envFor(''));
        $this->assertSame('production', $this->envFor('   '));
    }

    public function test_the_other_environments_are_untouched(): void
    {
        $this->assertSame('local', $this->envFor('local'));
        $this->assertSame('testing', $this->envFor('testing'));
        $this->assertSame('staging', $this->envFor('Staging'));
    }

    /**
     * Evaluate config/app.php's own expression with APP_ENV set to $value.
     *
     * All three channels are set, not just the Env repository: phpunit.xml
     * puts APP_ENV in $_SERVER, and the repository's adapter chain reads that
     * first, so setting the repository alone is silently ignored.
     */
    private function envFor(?string $value): string
    {
        $previousServer = $_SERVER['APP_ENV'] ?? null;
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousGet = getenv('APP_ENV');

        try {
            if ($value === null) {
                unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
                putenv('APP_ENV');
            } else {
                $_SERVER['APP_ENV'] = $value;
                $_ENV['APP_ENV'] = $value;
                putenv('APP_ENV='.$value);
            }

            /** @var array{env: string} $config */
            $config = require base_path('config/app.php');

            return $config['env'];
        } finally {
            if ($previousServer === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previousServer;
            }

            if ($previousEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousEnv;
            }

            if ($previousGet === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV='.$previousGet);
            }
        }
    }
}
