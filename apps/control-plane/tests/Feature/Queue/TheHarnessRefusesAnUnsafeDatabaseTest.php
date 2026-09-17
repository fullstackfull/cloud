<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * The guard on the most destructive statement in this repository.
 *
 * {@see WorkerHarness} ends every test in two directories with `truncate
 * <every table in the schema except migrations> cascade`, because those suites
 * cannot use RefreshDatabase — a transaction is invisible to the worker
 * process they exist to exercise — and a teardown that tried to remember what
 * to delete could not see what the other process wrote.
 *
 * That is acceptable only under hard isolation, so the harness establishes
 * where it is before it empties anything: the testing environment, its own
 * connection, the database `phpunit.xml` names, and not the database this
 * machine develops against.
 *
 * ---------------------------------------------------------------------------
 * Why this test does not use the harness
 * ---------------------------------------------------------------------------
 *
 * It calls the guard directly, with connections pointed at names it must
 * refuse. Nothing here truncates anything — proving a refusal by letting the
 * dangerous path run against a real second database would be the accident this
 * guard exists to prevent, and a test that needed a spare database to be safe
 * would be a test nobody could run.
 *
 * The guard is private, which is correct: it is not an extension point. It is
 * reached by reflection here for the same reason — asserting it through a
 * public wrapper would mean adding a public method whose only caller is a
 * test, and then the thing under test would be the wrapper.
 */
final class TheHarnessRefusesAnUnsafeDatabaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The harness defines this connection in its own setUp, which is not
         * run here — see the class docblock. Defined the same way it defines
         * it: a copy of the default connection, same credentials, same
         * database, its own PDO handle.
         */
        config([
            'database.connections.queue_test' => config('database.connections.pgsql'),
        ]);
    }

    /**
     * A concrete subclass, because the harness is abstract and the guard is an
     * instance method. Its setUp is never run: nothing here needs Redis, a
     * worker, or a committed row.
     */
    private function guard(): ReflectionMethod
    {
        $method = new ReflectionMethod(WorkerHarness::class, 'refuseToTruncateAnythingButATestDatabase');
        $method->setAccessible(true);

        return $method;
    }

    private function harness(): WorkerHarness
    {
        return new class('unsafe-database-guard') extends WorkerHarness
        {
            // Deliberately empty: the guard is the only thing under test, and
            // the harness's own setUp would start flushing Redis databases.
        };
    }

    #[Test]
    public function a_connection_that_is_not_the_harness_own_is_refused(): void
    {
        /*
         * The case that matters most in practice. `outsideTheTransaction()`
         * changes the default connection and the concurrency tests add their
         * own, so a teardown that trusted whatever was currently default would
         * be one mis-restored connection away from emptying the wrong schema.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refuses to empty anything but its own connection');

        $this->guard()->invoke($this->harness(), $this->app['db']->connection('pgsql'));
    }

    #[Test]
    public function a_test_configuration_pointed_at_a_database_not_named_as_one_is_refused(): void
    {
        /*
         * The condition that distrusts the configuration rather than the
         * connection. Every other check passes here: testing environment, the
         * harness's own connection name, and a target that matches what the
         * configuration says the test database is — because the configuration
         * itself has been pointed somewhere else, which is what a copied
         * `.env.testing` looks like.
         *
         * Nothing connects to it. The guard reads the name off the
         * configuration and refuses before a statement is prepared.
         */
        config([
            'database.connections.pgsql.database' => 'lynomia',
            'database.connections.queue_test' => [
                ...(array) config('database.connections.pgsql'),
                'database' => 'lynomia',
            ],
        ]);

        $this->app['db']->purge('queue_test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has to be named as a test database');

        $this->guard()->invoke($this->harness(), $this->app['db']->connection('queue_test'));
    }

    #[Test]
    public function a_database_that_is_not_the_configured_test_database_is_refused(): void
    {
        /*
         * A connection carrying the harness's own NAME and somebody else's
         * database — the shape of a misconfigured `.env.testing`, or of a
         * copied connection definition that was never repointed.
         *
         * `lynomia` is this repository's development database name, from
         * `.env.example`. Nothing connects to it here: the guard reads the
         * name off the configuration and refuses before any statement is
         * prepared.
         */
        config(['database.connections.queue_test' => [
            ...(array) config('database.connections.pgsql'),
            'database' => 'lynomia',
        ]]);

        $this->app['db']->purge('queue_test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the configured test database is');

        $this->guard()->invoke($this->harness(), $this->app['db']->connection('queue_test'));
    }

    #[Test]
    public function the_configured_test_database_on_the_harness_connection_is_accepted(): void
    {
        /*
         * The positive twin, and the reason the other two are not simply a
         * blanket refusal: the harness has to be able to do its job. Same
         * connection, same database `phpunit.xml` names, testing environment
         * — and the guard returns without complaint.
         *
         * It returns void, so the assertion is that nothing was thrown. The
         * database is not emptied: only the guard is called.
         */
        $this->guard()->invoke($this->harness(), $this->app['db']->connection('queue_test'));

        $this->assertSame('testing', (string) $this->app->environment());
        $this->assertSame(
            (string) config('database.connections.pgsql.database'),
            (string) $this->app['db']->connection('queue_test')->getDatabaseName(),
        );
    }

    #[Test]
    public function an_environment_that_is_not_testing_is_refused(): void
    {
        /*
         * Last rather than first, because it has to put the environment back.
         * A production deployment can hold every other condition — a
         * connection named `queue_test`, a database called `lynomia_test` — if
         * somebody copied a configuration file, and this is the condition that
         * makes the other three worth having.
         */
        $original = (string) $this->app->environment();

        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            $this->guard()->invoke($this->harness(), $this->app['db']->connection('queue_test'));

            $this->fail('The harness agreed to empty a database outside the testing environment.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('outside the testing environment', $e->getMessage());
        } finally {
            $this->app->detectEnvironment(static fn (): string => $original);
        }
    }
}
