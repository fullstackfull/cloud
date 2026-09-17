<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Simulation;

use RuntimeException;

/**
 * Where a controlled simulator keeps what it remembers, when it has to remember
 * it in more than one process.
 *
 * ---------------------------------------------------------------------------
 * Why this exists at all
 * ---------------------------------------------------------------------------
 *
 * Because a workflow in this platform is not one process. A customer pays in a
 * request; a listener on a queue fulfils the order; a worker in a third process
 * builds the machine; a scheduled command in a fourth confirms what the worker
 * did. Every one of those is a separate PHP process with its own memory.
 *
 * A simulator that keeps its accounts in a private array is therefore perfectly
 * good at proving a contract and completely unable to take part in a workflow:
 * the worker's copy has never heard of the account the request created, and the
 * request cannot see what the worker did. Three simulators — compute, registrar
 * and payment — had each grown a private answer to this; the other five had
 * none, which is exactly why every cross-process proof in this repository was
 * about compute or the registrar.
 *
 * This is that answer, once. It holds no business rule and knows nothing about
 * any provider: it reads and writes one array on behalf of whoever owns it.
 *
 * ---------------------------------------------------------------------------
 * What it is not
 * ---------------------------------------------------------------------------
 *
 * It is not a cache, not a database, and not a place for the platform's own
 * state. Nothing but a controlled simulator may use it, and a controlled
 * simulator cannot exist in production — each of the eight refuses to be
 * constructed there. This refuses too, for the case none of those can see: a
 * production deployment that somehow set one of the paths, which would be an
 * ordinary configuration accident with a file of imaginary infrastructure at
 * the end of it.
 *
 * ---------------------------------------------------------------------------
 * Opt-in, and silent when it is off
 * ---------------------------------------------------------------------------
 *
 * {@see fromConfig()} returns null when no path is configured, and every
 * simulator treats null as "keep it in memory". That is the normal case and the
 * right one: a shared file between tests in one process would let one test's
 * machines leak into another's, and the test suite's isolation depends on each
 * test starting from nothing.
 */
final readonly class ControlledSimulationStore
{
    /**
     * @param  string  $path  The file this store owns.
     * @param  list<class-string>  $allowedClasses  Classes the stored array may
     *                                              rebuild. Empty means scalars
     *                                              and arrays only, which is
     *                                              the safest and the default.
     */
    private function __construct(
        private string $path,
        private array $allowedClasses,
    ) {}

    /**
     * A store for the family that owns this configuration key, or null.
     *
     * @param  string  $configKey  e.g. `hosting.fake.state_path`
     * @param  list<class-string>  $allowedClasses
     */
    public static function fromConfig(string $configKey, array $allowedClasses = []): ?self
    {
        $path = config($configKey);

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        /*
         * The guard, and the reason it is here rather than only in the
         * simulators: a fake provider refuses production on construction, so
         * in production this code is unreachable through them. What it is not
         * unreachable through is a future caller — and a durable file of
         * pretend infrastructure is worth refusing on its own terms, in the one
         * place every family now shares, rather than trusting eight
         * constructors to stay correct for ever.
         */
        if (app()->isProduction()) {
            throw new RuntimeException(sprintf(
                'Controlled simulation state must never be enabled in production; "%s" names a file for it. '
                .'A production deployment that keeps simulated provider state has a record of infrastructure '
                .'nobody owns, and every screen that reads it is reporting on machines that do not exist.',
                $configKey,
            ));
        }

        return new self($path, $allowedClasses);
    }

    /**
     * A store at an exact path, for a test that wants one without configuration.
     *
     * @param  list<class-string>  $allowedClasses
     */
    public static function at(string $path, array $allowedClasses = []): self
    {
        return new self($path, $allowedClasses);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * What was last written, or null when nothing has been.
     *
     * Null rather than an empty array, so that a family can tell "no state yet"
     * from "state, and it is empty" — the difference between a fleet nobody has
     * touched and a fleet whose last machine was destroyed.
     *
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $state = @unserialize($contents, [
            'allowed_classes' => $this->allowedClasses === [] ? false : $this->allowedClasses,
        ]);

        if (! is_array($state)) {
            return null;
        }

        /*
         * A class the caller forgot to allow comes back as an incomplete
         * object, and an incomplete object is worse than no state: every
         * property read on it is a fatal error thrown somewhere far away from
         * the list that was wrong. So it is refused here, by name.
         */
        $this->refuseIncompleteObjects($state, $this->path);

        /** @var array<string, mixed> $state */
        return $state;
    }

    /**
     * Publishes the state for other processes.
     *
     * Written beside and renamed, because a worker reading while this writes
     * must see either the old state or the new one and never half of either.
     * `rename()` within one filesystem is atomic; a write in place is not.
     *
     * @param  array<string, mixed>  $state
     */
    public function write(array $state): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0o755, recursive: true);
        }

        $temporary = $this->path.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, serialize($state)) === false) {
            /*
             * Silent, deliberately. A simulator whose state file cannot be
             * written is still a working simulator for the process it is in,
             * and turning that into an exception would fail a test for a
             * reason that has nothing to do with the platform. The cross-
             * process tests assert what the other process can see, so a store
             * that never writes makes them fail with the right message.
             */
            return;
        }

        @rename($temporary, $this->path);
    }

    /**
     * Back to nothing, for a test that wants a known starting point.
     */
    public function forget(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * @param  array<array-key, mixed>  $state
     */
    private function refuseIncompleteObjects(array $state, string $path): void
    {
        foreach ($state as $value) {
            if ($value instanceof \__PHP_Incomplete_Class) {
                throw new RuntimeException(sprintf(
                    'The controlled simulation state at %s holds an object of a class this store was not told '
                    .'it could rebuild. Add the class to the allowed list where the store is constructed.',
                    $path,
                ));
            }

            if (is_array($value)) {
                $this->refuseIncompleteObjects($value, $path);
            }
        }
    }
}
