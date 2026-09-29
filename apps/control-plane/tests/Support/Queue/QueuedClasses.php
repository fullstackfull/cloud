<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;

/**
 * Every class in the application that the queue can carry.
 *
 * Discovery only. What a class *declares* — its queue, its tries, its backoff,
 * its timeout — is deliberately not read here: those are resolved by the
 * framework at payload-construction time from class properties, methods,
 * attributes, traits, parents, the call site and the worker's own options,
 * and a test that re-derives that resolution from source has to be right
 * about every one of those inputs while the framework only has to be right
 * once. {@see ThePayloadTheWorkerWillRead} asks the framework instead.
 *
 * ---------------------------------------------------------------------------
 * Which directories, and why they are not written down here
 * ---------------------------------------------------------------------------
 *
 * The roots are read from `composer.json`'s `autoload.psr-4`, because that is
 * the file which decides where a class can be autoloaded from at all. A
 * hand-written list is a claim about "the codebase" measured over a subset
 * somebody typed, and the obvious subset — `src/` — misses `app/`, which is
 * where `php artisan make:job` writes (`JobMakeCommand::getDefaultNamespace()`
 * returns `$rootNamespace.'\Jobs'`). A queued class planted there with a
 * `$timeout` above the retry clock would reinstate F-08 while a `src/`-only
 * sweep stayed green.
 *
 * `autoload-dev` is excluded on purpose: `Tests\` holds deliberately broken
 * fixtures, and a class that exists only to be refused must not be read as
 * part of the application.
 */
final class QueuedClasses
{
    /**
     * The production autoload roots, as namespace prefix => absolute directory.
     *
     * @return array<string, string>
     */
    public static function roots(): array
    {
        $path = base_path('composer.json');
        $composer = json_decode((string) file_get_contents($path), true);

        if (! is_array($composer) || ! is_array($composer['autoload']['psr-4'] ?? null)) {
            throw new RuntimeException('composer.json declares no autoload.psr-4 block; there is nothing to sweep.');
        }

        $roots = [];

        foreach ($composer['autoload']['psr-4'] as $prefix => $directories) {
            foreach ((array) $directories as $directory) {
                $roots[(string) $prefix] = rtrim(base_path((string) $directory), '/');
            }
        }

        ksort($roots);

        return $roots;
    }

    /**
     * Every concrete class under those roots that implements ShouldQueue.
     *
     * @return list<class-string>
     */
    public static function all(): array
    {
        $classes = [];

        foreach (self::roots() as $prefix => $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($directory) + 1, -4);
                $class = $prefix.str_replace('/', '\\', $relative);

                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);

                if ($reflection->isInstantiable() && $reflection->implementsInterface(ShouldQueue::class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }
}
