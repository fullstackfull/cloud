<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Lynomia\Modules\Shared\Infrastructure\Simulation\ControlledSimulationStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The rules the one durable-simulation mechanism is held to.
 *
 * Five families gained the ability to remember something between processes in
 * this gap, and the thing that makes that safe is not the five families: it is
 * that there is one store, it does nothing unless it is asked, it refuses
 * production, and a test can put it back to nothing.
 *
 * Every assertion here is about the mechanism rather than about any provider.
 * What each family does with what it remembers is proved by
 * {@see AControlledProviderRemembersAcrossProcessesTest}, in another process.
 */
#[Group('golden-path')]
final class ControlledSimulationStateIsOptInAndNeverProductionTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/lynomia-store-test-'.getmypid().'-'.uniqid().'/state.dat';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }

        @rmdir(dirname($this->path));

        parent::tearDown();
    }

    #[Test]
    public function nothing_is_stored_unless_a_path_is_configured(): void
    {
        /*
         * The default, and the one that keeps the test suite isolated: a
         * simulator in one process is the whole truth, and a shared file would
         * let one test's machines turn up in the next test's.
         */
        config()->set('compute.fake.state_path', null);

        $this->assertNull(ControlledSimulationStore::fromConfig('compute.fake.state_path'));

        config()->set('compute.fake.state_path', '   ');

        $this->assertNull(
            ControlledSimulationStore::fromConfig('compute.fake.state_path'),
            'whitespace is not a path, and treating it as one would put a file named " " somewhere',
        );
    }

    #[Test]
    public function a_configured_path_is_refused_outright_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('hosting.fake.state_path', $this->path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must never be enabled in production');

        ControlledSimulationStore::fromConfig('hosting.fake.state_path');
    }

    #[Test]
    public function the_refusal_names_the_configuration_key_that_caused_it(): void
    {
        // An operator reading this in a crash report needs to know which
        // variable to unset, not that "simulation" is on somewhere.
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('backups.fake.state_path', $this->path);

        try {
            ControlledSimulationStore::fromConfig('backups.fake.state_path');

            $this->fail('production accepted a controlled simulation state path');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('backups.fake.state_path', $e->getMessage());
        }
    }

    #[Test]
    public function nothing_read_before_anything_is_written(): void
    {
        $store = ControlledSimulationStore::at($this->path);

        // Null rather than an empty array, because a family has to be able to
        // tell "no state yet" from "state, and it is empty" — a fleet nobody
        // has touched from a fleet whose last machine was destroyed.
        $this->assertNull($store->read());
    }

    #[Test]
    public function what_is_written_is_what_comes_back(): void
    {
        $store = ControlledSimulationStore::at($this->path);

        $store->write(['accounts' => ['node-1' => ['alice' => true]], 'next_id' => 4]);

        $this->assertSame(
            ['accounts' => ['node-1' => ['alice' => true]], 'next_id' => 4],
            $store->read(),
        );
    }

    #[Test]
    public function the_directory_is_created_rather_than_required(): void
    {
        // The paths are per-test temporary directories, and a store that
        // demanded the directory already existed would make every caller
        // mkdir first.
        $this->assertDirectoryDoesNotExist(dirname($this->path));

        ControlledSimulationStore::at($this->path)->write(['a' => 1]);

        $this->assertFileExists($this->path);
    }

    #[Test]
    public function a_reader_never_sees_half_a_write(): void
    {
        /*
         * Written beside and renamed. This cannot observe the race directly in
         * one process, so it asserts the property that makes the race
         * impossible: nothing but the final file is left behind, and the
         * temporary it was built in is gone.
         */
        $store = ControlledSimulationStore::at($this->path);

        $store->write(['one' => 1]);
        $store->write(['two' => 2]);

        $files = array_values(array_diff((array) scandir(dirname($this->path)), ['.', '..']));

        $this->assertSame([basename($this->path)], $files);
        $this->assertSame(['two' => 2], $store->read());
    }

    #[Test]
    public function forgetting_puts_it_back_to_nothing(): void
    {
        $store = ControlledSimulationStore::at($this->path);

        $store->write(['held' => ['example.test' => true]]);

        $store->forget();

        $this->assertNull($store->read());
        $this->assertFileDoesNotExist($this->path);
    }

    #[Test]
    public function an_object_the_store_was_not_told_about_is_refused_by_name(): void
    {
        /*
         * The alternative is worse than a refusal. An unlisted class comes back
         * as an incomplete object, and every property read on it is a fatal
         * error thrown somewhere far away from the list that was wrong.
         */
        $writer = ControlledSimulationStore::at($this->path, [ControlledSimulationStore::class]);

        $writer->write(['held' => [ControlledSimulationStore::at('/dev/null')]]);

        $reader = ControlledSimulationStore::at($this->path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was not told it could rebuild');

        $reader->read();
    }

    #[Test]
    public function a_file_that_is_not_state_at_all_reads_as_nothing(): void
    {
        // A truncated write, a file somebody touched, a leftover from a build
        // that changed shape. None of them is worth an exception: the
        // simulator's own empty state is the right answer and the next write
        // replaces the file.
        @mkdir(dirname($this->path), 0o755, true);
        file_put_contents($this->path, 'not serialised php');

        $this->assertNull(ControlledSimulationStore::at($this->path)->read());
    }
}
