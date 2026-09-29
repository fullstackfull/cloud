<?php

declare(strict_types=1);

namespace Lynomia\Support\Environment;

use Illuminate\Contracts\Foundation\Application;

/**
 * Decides, once, which environment this process is in (F-16).
 *
 * Every production guard in this platform asks `$app->isProduction()` or
 * `$app->environment('production')`. Both compare `$app['env']`, not
 * `config('app.env')`, and both compare it verbatim. The two values usually
 * agree, because Laravel's LoadConfiguration bootstrapper sets `$app['env']`
 * from `config('app.env')` - except in a console process, where
 * EnvironmentDetector lets an `--env=` argument override it. config/app.php
 * normalises APP_ENV, but nothing normalised the flag, so
 * `php artisan queue:work --env=Production` on a production host ran with
 * `$app['env'] === 'Production'` and every guard - the fake-provider boot
 * guard, the reference-topology refusal, the readiness gate - stood down at
 * once, for the life of that process.
 *
 * This runs immediately after LoadConfiguration (registered in
 * bootstrap/app.php), before any service provider registers or boots, and
 * settles both values to one answer:
 *
 *  - the flag is trimmed and lower-cased like APP_ENV, and an empty one
 *    (`--env=`) fails closed to `production` like an empty APP_ENV;
 *  - a configuration that says production cannot be talked out of it by the
 *    flag: `--env=local` on a host whose APP_ENV (or cached configuration)
 *    is production is still production. The flag exists to pick an
 *    environment file, not to disarm a production host. The one exception is
 *    the machine nobody has configured at all - no APP_ENV anywhere, no
 *    environment file, no cached configuration - where `production` is only
 *    Laravel's default for the absent value (see
 *    ProviderRegistryServiceProvider::productionIsAnUnconfiguredDefault);
 *    there the flag is the only thing that said anything, so it decides;
 *  - `config('app.env')` is then set to the same answer, so the two values
 *    every reader might consult never disagree again.
 */
final class SettleTheApplicationEnvironment
{
    public const string PRODUCTION = 'production';

    public function __invoke(Application $app): void
    {
        $configured = self::normalise(self::configValue($app));
        $detected = self::normalise(is_string($app['env'] ?? null) ? $app['env'] : null);

        $settled = $configured === self::PRODUCTION && ! self::nothingWasConfigured($app)
            ? self::PRODUCTION
            : $detected;

        $app['env'] = $settled;

        if ($app->bound('config')) {
            $app->make('config')->set('app.env', $settled);
        }
    }

    /**
     * Trimmed, lower-cased, and `production` when there is nothing left.
     */
    public static function normalise(?string $value): string
    {
        return strtolower(trim((string) $value)) ?: self::PRODUCTION;
    }

    /**
     * The `--env` argument on a console command line, exactly as Laravel's
     * EnvironmentDetector reads it (`--env=X` or `--env X`), or null when
     * there is none.
     *
     * @param  array<int, mixed>  $argv
     */
    public static function consoleArgument(array $argv): ?string
    {
        foreach (array_values($argv) as $i => $value) {
            if ($value === '--env') {
                $next = $argv[$i + 1] ?? null;

                return is_string($next) ? $next : '';
            }

            if (is_string($value) && str_starts_with($value, '--env=')) {
                return substr($value, strlen('--env='));
            }
        }

        return null;
    }

    /**
     * Whether APP_ENV is present in the process environment, read from the
     * superglobals and getenv() (after the dotenv file, if any, was loaded).
     */
    public static function appEnvWasNamed(): bool
    {
        foreach ([$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')] as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether nothing configured this process: no APP_ENV, no cached
     * configuration, no environment file. Then `production` is only Laravel's
     * default for the absent value.
     *
     * Public because the provider guard asks the same question
     * (ProviderRegistryServiceProvider::productionIsAnUnconfiguredDefault) and
     * must get the same answer. It used to ask its own copy without the cache
     * clause, so a host running from a cached production configuration and no
     * environment file settled as production here and had its boot-time
     * provider checks stand down there (OB-3, re-audit of round three).
     */
    public static function nothingWasConfigured(Application $app): bool
    {
        return ! self::appEnvWasNamed()
            && ! $app->configurationIsCached()
            && ! is_file($app->environmentFilePath());
    }

    private static function configValue(Application $app): ?string
    {
        if (! $app->bound('config')) {
            return null;
        }

        $value = $app->make('config')->get('app.env');

        return is_string($value) ? $value : null;
    }
}
