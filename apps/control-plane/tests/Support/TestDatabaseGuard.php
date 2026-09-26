<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use RuntimeException;
use Tests\Feature\Queue\WorkerHarness;
use Tests\TestCase;

/**
 * The refusal that stands between a test and `migrate:fresh` on the wrong
 * database.
 *
 * `RefreshDatabase` runs `migrate:fresh` on the first test of a run, and
 * `migrate:fresh` drops every table in whatever the default connection names.
 * `DatabaseMigrations` does the same on every test and `DatabaseTruncation`
 * empties every table. Hundreds of classes in this suite reach one of them,
 * and until this guard none of them checked anything: the harness that only
 * *truncates* ({@see WorkerHarness}) refused unless four
 * conditions held, while the trait that *drops the schema* checked none. The
 * less destructive operation was the guarded one.
 *
 * That is not hypothetical here. `.env` names the application database, and
 * `DB_DATABASE` is deliberately left overridable by an exported variable
 * (`phpunit.xml` says why), so one `DB_DATABASE=<anything> php artisan test`
 * points every one of those classes at a database of the caller's choosing.
 *
 * Called from {@see TestCase::setUpTraits()}, which is where Laravel
 * runs the traits, so it fires **before** the first statement any of them
 * issues rather than after. All four must hold, and each is there because the
 * others are not enough:
 *
 *  - **The application environment is `testing`.** Necessary and nowhere near
 *    sufficient: it is one variable.
 *  - **The default connection is the one the environment chose.** The traits
 *    act on the default connection; a class that repointed it before the
 *    traits ran must not be able to aim them at whatever it left there.
 *  - **The connection's database is the configured one, and is named.** The
 *    connection object is what the statements go to; `DB_URL` or a changed
 *    connection can make it differ from what the configuration says.
 *  - **The name says it is a test database.** The condition that distrusts the
 *    configuration itself: a copied `.env.testing` that was never repointed,
 *    or an exported `DB_DATABASE`, satisfies the three above while naming a
 *    database full of real rows. The same idiom the worker harness and the
 *    browser suite use.
 *
 * A refusal is a `RuntimeException`, never a skip: a run that cannot establish
 * where it is must fail loudly, and a skip would read as green.
 */
final class TestDatabaseGuard
{
    /**
     * The traits whose set-up destroys schema or rows.
     *
     * `LazilyRefreshDatabase` is not listed separately: it uses
     * `RefreshDatabase`, so `class_uses_recursive()` reports both.
     *
     * @var list<class-string>
     */
    public const array DESTROYING_TRAITS = [
        RefreshDatabase::class,
        DatabaseMigrations::class,
        DatabaseTruncation::class,
    ];

    /**
     * Whether a test class reaches a trait that destroys schema or rows.
     *
     * @param  array<class-string, class-string>  $traits  class_uses_recursive() of the test
     */
    public static function destroys(array $traits): bool
    {
        return array_intersect(self::DESTROYING_TRAITS, array_keys($traits)) !== [];
    }

    /** Refuses unless the application is pointed at a disposable database. */
    public static function refuseAnythingButATestDatabase(Application $app): void
    {
        /** @var DatabaseManager $db */
        $db = $app->make('db');
        $default = (string) $app->make('config')->get('database.default');
        $chosen = Env::get('DB_CONNECTION');

        self::check(
            environment: (string) $app->environment(),
            defaultConnection: $default,
            chosenConnection: is_string($chosen) ? $chosen : $default,
            configuredDatabase: (string) $app->make('config')->get("database.connections.{$default}.database"),
            connectionDatabase: (string) $db->connection($default)->getDatabaseName(),
        );
    }

    /** The four conditions over plain values, so each can be pinned alone. */
    public static function check(
        string $environment,
        string $defaultConnection,
        string $chosenConnection,
        string $configuredDatabase,
        string $connectionDatabase,
    ): void {
        if ($environment !== 'testing') {
            throw new RuntimeException(sprintf(
                'The test suite refuses to drop or empty a database outside the testing environment (it is "%s").',
                $environment,
            ));
        }

        if ($defaultConnection !== $chosenConnection) {
            throw new RuntimeException(sprintf(
                'The test suite refuses to drop or empty the "%s" connection: the environment chose "%s".',
                $defaultConnection,
                $chosenConnection,
            ));
        }

        if ($connectionDatabase === '' || $connectionDatabase !== $configuredDatabase) {
            throw new RuntimeException(sprintf(
                'The test suite refuses to drop or empty "%s": the configured test database is "%s".',
                $connectionDatabase,
                $configuredDatabase,
            ));
        }

        if (! str_contains(strtolower($connectionDatabase), 'test')) {
            throw new RuntimeException(sprintf(
                'The test suite refuses to drop or empty "%s": a database it may drop has to be named as a test database.',
                $connectionDatabase,
            ));
        }
    }
}
