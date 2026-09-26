<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SplFileInfo;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;
use Throwable;

/**
 * `migrate:fresh` never runs against a database that is not a test database.
 *
 * `RefreshDatabase` drops every table in whatever the default connection
 * names, and `DB_DATABASE` is deliberately overridable by an exported variable
 * so that concurrent runs can each choose their own. Before
 * {@see TestDatabaseGuard} nothing stood between those two facts: one
 * `DB_DATABASE=lynomia php artisan test` and the application database was
 * dropped by the first test, while the worker harness — which only truncates —
 * refused unless four conditions held.
 *
 * Three things are pinned here, because a guard nothing can tell from its
 * absence is not a guard:
 *
 *  - each of the four conditions refuses on its own, and the second and third
 *    are fed what the traits act on — the connection the environment chose,
 *    the database the live connection names — rather than a value that agrees
 *    with what it is compared to by construction;
 *  - the guard fires **before** the trait touches the database, through the
 *    same `setUpTraits()` Laravel runs — a guard that fired after the drop
 *    would be a guard in name. The refusal's text cannot show that, since a
 *    late guard names the same database, so the server is asked whether the
 *    trait got there;
 *  - every test class that reaches a destroying trait is reached by it: all
 *    of them extend `Tests\TestCase`, and none of them redeclares
 *    `setUpTraits()` underneath it.
 *
 * A test that asserts a fact about its environment in a comment has asserted
 * nothing. The database the wiring test points at is generated for this run
 * and its absence is established by asking the server, before the test changes
 * any configuration; if it exists, the test stops there.
 */
final class TheTestSuiteRefusesToDropAnythingButATestDatabaseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function this_run_is_pointed_at_a_database_the_guard_accepts(): void
    {
        TestDatabaseGuard::refuseAnythingButATestDatabase($this->app);

        $this->assertStringContainsStringIgnoringCase('test', (string) DB::connection()->getDatabaseName());
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'outside the testing environment' => ['local', 'pgsql', 'pgsql', 'lynomia_test', 'lynomia_test', 'outside the testing environment'];
        yield 'a default connection the environment did not choose' => ['testing', 'other', 'pgsql', 'lynomia_test', 'lynomia_test', 'the environment chose "pgsql"'];
        yield 'a connection pointed somewhere the configuration does not say' => ['testing', 'pgsql', 'pgsql', 'lynomia_test', 'lynomia', 'the configured test database is "lynomia_test"'];
        yield 'a connection naming no database' => ['testing', 'pgsql', 'pgsql', '', '', 'the configured test database is ""'];
        yield 'a database not named as a test database' => ['testing', 'pgsql', 'pgsql', 'lynomia', 'lynomia', 'has to be named as a test database'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function each_condition_refuses_on_its_own(
        string $environment,
        string $default,
        string $chosen,
        string $configured,
        string $actual,
        string $because,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($because);

        TestDatabaseGuard::check($environment, $default, $chosen, $configured, $actual);
    }

    #[Test]
    public function the_name_is_read_without_regard_to_case(): void
    {
        TestDatabaseGuard::check('testing', 'pgsql', 'pgsql', 'LYNOMIA_TEST_X', 'LYNOMIA_TEST_X');

        $this->addToAssertionCount(1);
    }

    /**
     * The traits are written out here rather than read from
     * {@see TestDatabaseGuard::DESTROYING_TRAITS}: that constant is what is
     * under test, and a list spread from it would shrink with it, so a trait
     * deleted from the guard would be deleted from the question too.
     */
    #[Test]
    public function every_trait_that_destroys_schema_or_rows_is_guarded_and_a_transaction_alone_is_not(): void
    {
        foreach ([RefreshDatabase::class, LazilyRefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class] as $trait) {
            $this->assertTrue(
                TestDatabaseGuard::destroys(class_uses_recursive(self::userOf($trait))),
                "{$trait} destroys schema or rows and must be guarded.",
            );
        }

        $this->assertFalse(TestDatabaseGuard::destroys([DatabaseTransactions::class => DatabaseTransactions::class]));
    }

    #[Test]
    public function the_guard_refuses_before_refresh_database_touches_anything(): void
    {
        $connection = (string) config('database.default');
        $original = config("database.connections.{$connection}.database");
        $absent = $this->aDatabaseThisServerDoesNotHave();
        $migrated = RefreshDatabaseState::$migrated;

        /*
         * The shape of an exported DB_DATABASE naming a database that is not a
         * test database, with the run's first-test state restored so that the
         * trait would run migrate:fresh, not merely open a transaction.
         */
        config()->set("database.connections.{$connection}.database", $absent);
        DB::purge($connection);
        RefreshDatabaseState::$migrated = false;

        try {
            $this->setUpTraits();

            $this->fail("setUpTraits() went ahead against \"{$absent}\" (migrate:fresh creates a missing PostgreSQL database and migrates it); the guard must refuse first.");
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString(
                "refuses to drop or empty \"{$absent}\"",
                $refusal->getMessage(),
                'The refusal must come from the guard, before the trait reaches the database; anything else means the trait got there first.',
            );
        } finally {
            RefreshDatabaseState::$migrated = $migrated;
            config()->set("database.connections.{$connection}.database", $original);
            DB::purge($connection);

            /*
             * The refusal's text cannot tell a guard that ran first from one
             * that ran after the trait: both name the database. What tells
             * them apart is on the server, so it is read before the clean-up
             * removes it.
             */
            $reachedTheServer = $this->theServerHas($absent);

            if ($reachedTheServer) {
                $this->dropWhatTheTraitCreated($connection, $absent);
            }
        }

        $this->assertFalse(
            $reachedTheServer,
            "The trait reached the server before the guard refused: \"{$absent}\" was created by migrate:fresh, so the refusal came after the drop, not before it.",
        );
    }

    private function theServerHas(string $database): bool
    {
        return DB::select('select 1 from pg_database where datname = ?', [$database]) !== [];
    }

    /**
     * Undo what an unguarded, or late, trait does to an absent database.
     *
     * Absent is not harmless: `migrate:fresh` on PostgreSQL creates a missing
     * database and then migrates it, so with the guard gone or late this test
     * would leave a new database on the server every run. The name was
     * generated by this run and established absent before anything was pointed
     * at it, so whatever now answers to it is this run's to remove. From a
     * connection of its own, because the test's connection is inside a
     * transaction and `DROP DATABASE` cannot be.
     */
    private function dropWhatTheTraitCreated(string $connection, string $absent): void
    {
        config()->set('database.connections.guard_cleanup', config("database.connections.{$connection}"));

        try {
            DB::connection('guard_cleanup')->statement('drop database if exists "'.$absent.'"');
        } finally {
            DB::purge('guard_cleanup');
        }
    }

    /**
     * The third condition is fed the database the live connection will talk
     * to, not the name the configuration holds.
     *
     * `DB_URL` is the ordinary way the two come apart: the connection is built
     * from the URL while the configuration's `database` key still names the
     * test database. The database the URL names here is named as a test
     * database, so that nothing but the third condition can refuse it; fed the
     * configuration instead, the guard would compare the test database with
     * itself and let the trait drop whatever the URL names.
     */
    #[Test]
    public function the_guard_asks_the_connection_where_it_points_not_the_configuration(): void
    {
        $connection = (string) config('database.default');
        $configured = (string) config("database.connections.{$connection}.database");
        $host = (string) config("database.connections.{$connection}.host");
        $elsewhere = $configured.'_elsewhere';
        $url = config("database.connections.{$connection}.url");

        config()->set("database.connections.{$connection}.url", "pgsql://{$host}/{$elsewhere}");
        DB::purge($connection);

        try {
            $this->assertSame($elsewhere, DB::connection($connection)->getDatabaseName(), 'The URL did not repoint the connection, so this test would measure nothing.');
            $this->assertSame($configured, config("database.connections.{$connection}.database"));

            TestDatabaseGuard::refuseAnythingButATestDatabase($this->app);

            $this->fail("The guard accepted a connection pointed at \"{$elsewhere}\" while the configuration names \"{$configured}\".");
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString(
                "refuses to drop or empty \"{$elsewhere}\": the configured test database is \"{$configured}\"",
                $refusal->getMessage(),
            );
        } finally {
            config()->set("database.connections.{$connection}.url", $url);
            DB::purge($connection);
        }
    }

    /**
     * The second condition is fed the connection the environment chose, not
     * the default it is compared with.
     *
     * The repointed default is a copy of the chosen connection, test database
     * and all, so that nothing but the second condition can refuse it.
     */
    #[Test]
    public function the_guard_compares_the_default_connection_with_the_one_the_environment_chose(): void
    {
        $chosen = (string) config('database.default');

        config()->set('database.connections.guard_repointed', config("database.connections.{$chosen}"));
        config()->set('database.default', 'guard_repointed');

        try {
            TestDatabaseGuard::refuseAnythingButATestDatabase($this->app);

            $this->fail('The guard accepted a default connection the environment did not choose.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString(
                "refuses to drop or empty the \"guard_repointed\" connection: the environment chose \"{$chosen}\"",
                $refusal->getMessage(),
            );
        } finally {
            config()->set('database.default', $chosen);
            DB::purge('guard_repointed');
        }
    }

    /**
     * The sweep reads a class declared at any indentation.
     *
     * PHPUnit collects an indented class like any other, so a sweep that read
     * only column zero would pass over it, and over whatever trait it uses.
     */
    #[Test]
    public function the_sweep_reads_an_indented_class_declaration(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sweep');
        $this->assertIsString($file);

        try {
            file_put_contents($file, "<?php\n\nnamespace Tests\\Somewhere;\n\n    final class IndentedEscapeeTest extends \\PHPUnit\\Framework\\TestCase\n    {\n    }\n");

            $this->assertSame(
                'Tests\\Somewhere\\IndentedEscapeeTest',
                (new ReflectionMethod(self::class, 'classDeclaredIn'))->invoke(null, new SplFileInfo($file)),
            );
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function every_class_that_reaches_a_destroying_trait_is_reached_by_the_guard(): void
    {
        $this->assertSame(
            TestCase::class,
            (new ReflectionClass(TestCase::class))->getMethod('setUpTraits')->getDeclaringClass()->getName(),
            'Tests\TestCase must declare setUpTraits(), which is where the guard runs.',
        );

        $resolved = 0;
        $reaching = 0;
        $unresolvable = [];
        $escapees = [];

        foreach (self::filesUnderTests() as $file) {
            $class = self::classDeclaredIn($file);

            if ($class === null) {
                continue;
            }

            if (! class_exists($class)) {
                $unresolvable[] = $file->getPathname();

                continue;
            }

            $resolved++;

            if (! TestDatabaseGuard::destroys(class_uses_recursive($class))) {
                continue;
            }

            $reaching++;
            $reflection = new ReflectionClass($class);

            if (! $reflection->isSubclassOf(TestCase::class)) {
                $escapees[] = "{$class} does not extend Tests\\TestCase";

                continue;
            }

            $declaring = $reflection->getMethod('setUpTraits')->getDeclaringClass()->getName();

            if ($declaring !== TestCase::class) {
                $escapees[] = "{$class} runs the traits from {$declaring}::setUpTraits(), below the guard";
            }
        }

        // An empty sweep would pass the assertion that matters on nothing.
        $this->assertGreaterThan(400, $resolved, 'The sweep resolved too few classes to mean anything.');
        $this->assertGreaterThan(300, $reaching, 'The sweep found too few classes using a destroying trait to mean anything.');
        $this->assertSame([], $unresolvable, 'A test file declares a class the autoloader cannot find, so the sweep cannot see what it uses.');
        $this->assertSame([], $escapees, 'A test class that drops or empties a database is not reached by the guard.');
    }

    /** A database name this server does not have, established by asking it. */
    private function aDatabaseThisServerDoesNotHave(): string
    {
        $name = 'lynomia_guard_absent_'.bin2hex(random_bytes(6));

        $this->assertSame(
            [],
            DB::select('select 1 from pg_database where datname = ?', [$name]),
            "The server has a database named {$name}; this test will not point anything at it.",
        );

        return $name;
    }

    /**
     * An anonymous user of a trait, for class_uses_recursive().
     *
     * @param  class-string  $trait
     */
    private static function userOf(string $trait): object
    {
        return match ($trait) {
            RefreshDatabase::class => new class
            {
                use RefreshDatabase;
            },
            LazilyRefreshDatabase::class => new class
            {
                use LazilyRefreshDatabase;
            },
            DatabaseMigrations::class => new class
            {
                use DatabaseMigrations;
            },
            DatabaseTruncation::class => new class
            {
                use DatabaseTruncation;
            },
            default => throw new RuntimeException("No user of {$trait} is written here; add one."),
        };
    }

    /** @return iterable<SplFileInfo> */
    private static function filesUnderTests(): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                yield $file;
            }
        }
    }

    /**
     * The one class a file declares, as PHPUnit would collect it.
     *
     * PHPUnit's loader keeps a single class per file, the one named for the
     * file, so this reads the file's namespace and the first class
     * declaration, indented or not. A file with no class declaration — a
     * trait, an interface, a standalone script — is skipped rather than
     * loaded, because loading a script executes it.
     *
     * @return class-string|null
     */
    private static function classDeclaredIn(SplFileInfo $file): ?string
    {
        try {
            $source = (string) file_get_contents($file->getPathname());
        } catch (Throwable) {
            return null;
        }

        if (preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }

        $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $match) === 1 ? $match[1].'\\' : '';

        /** @var class-string */
        return $namespace.$class[1];
    }
}
