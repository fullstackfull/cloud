<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
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
 * issues rather than after. {@see LeavesNothingCommitted}, which empties every
 * table in `tearDown()`, is counted among the destroying traits, so a class
 * using it is refused here too, and it also asks this guard itself before it
 * truncates anything, for a class that is not a `Tests\TestCase`. All four must hold, and each is there because the
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
 *    database full of real rows. The rule is {@see isNamedAsATestDatabase()}:
 *    `test` as a whole word of the name, not as a substring of it. The
 *    substring rule it replaced admitted `lynomia_latest`, `contest` and
 *    `attestation`, and a run pointed at `lynomia_latest` passed the guard,
 *    after which `migrate:fresh` created that database and migrated it. The
 *    worker harness uses the same rule.
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
     * `LeavesNothingCommitted` is listed because it truncates every table in
     * the public schema; before it was, a class using it alone was not
     * guarded, and the only protection was that every class using it today
     * also uses `RefreshDatabase`.
     *
     * @var list<class-string>
     */
    public const array DESTROYING_TRAITS = [
        RefreshDatabase::class,
        DatabaseMigrations::class,
        DatabaseTruncation::class,
        LeavesNothingCommitted::class,
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

    /**
     * Whether a database name says it is a test database: `test`, in any
     * case, as a whole word of the name, where the words are what lies
     * between characters other than ASCII letters and digits.
     *
     * So `lynomia_test`, `lynomia_test_<slug>`, `test`, `lynomia-test` and
     * `LYNOMIA_TEST_X` are test databases, and `lynomia`, `lynomia_latest`,
     * `contest`, `attestation`, `lynomiatest`, `lynomia_testing` and
     * `lynomia_tests` are not. The last two are refused on purpose: a rule
     * that let a word merely start with `test` would let `testimonials` in.
     */
    public static function isNamedAsATestDatabase(string $name): bool
    {
        return in_array('test', preg_split('/[^a-z0-9]+/', strtolower($name)) ?: [], true);
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

    /**
     * Refuses unless a connection a test is about to empty tables on points at
     * the test database this run chose.
     *
     * For the tests that commit for real and clean up with their own
     * `TRUNCATE` or `->table(...)->delete()` — outside any destroying trait,
     * often on a second connection or after repointing the default. The same
     * conditions as {@see refuseAnythingButATestDatabase()}, over the
     * connection handed in rather than the default: the environment is
     * `testing`; the connection's database is the one the environment chose
     * (the database of the connection `DB_CONNECTION` names); and that name
     * says it is a test database. Called immediately before the statement.
     * `TheTestSuiteRefusesToDropAnythingButATestDatabaseTest` refuses a test
     * file that empties tables without asking.
     */
    public static function refuseToEmpty(ConnectionInterface $connection): void
    {
        $app = app();
        $chosen = Env::get('DB_CONNECTION');
        $chosen = is_string($chosen) ? $chosen : (string) $app->make('config')->get('database.default');

        self::check(
            environment: (string) $app->environment(),
            defaultConnection: $chosen,
            chosenConnection: $chosen,
            configuredDatabase: (string) $app->make('config')->get("database.connections.{$chosen}.database"),
            connectionDatabase: (string) $connection->getDatabaseName(),
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

        if (! self::isNamedAsATestDatabase($connectionDatabase)) {
            throw new RuntimeException(sprintf(
                'The test suite refuses to drop or empty "%s": a database it may drop has to be named as a test database.',
                $connectionDatabase,
            ));
        }
    }
}
