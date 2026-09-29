<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use RuntimeException;

/**
 * Writes a real queued class into a real application root for the length of
 * one callback.
 *
 * A fixture root cannot pin what this exists to pin. The defect F-08's sweep
 * once had was not "the finder cannot read shape X", it was "the finder was
 * never pointed at directory Y" — `app/`, where `make:job` writes — and a test
 * that hands the finder a root of its own re-introduces the assumption under
 * test. So the file goes under the real `App\` root, where Composer's PSR-4
 * map will find it, and is removed afterwards.
 *
 * **This writes into the repository.** The removal runs in `finally`, and is
 * also registered with `register_shutdown_function()`, because `finally` runs
 * on neither a PHP fatal nor `exit()` — and the sweep reaches planted files
 * through `class_exists()`, which executes them, so a fatal in a file the
 * sweep loaded is the ordinary way to skip a `finally`. A hard kill still
 * skips both. What that buys is a leftover that is loud rather than
 * impossible: a stray class under `app/Jobs` on `payments` is refused by name
 * by the very rule it was planted to test. Never plant while another test run
 * is reading the same checkout.
 */
trait PlantsAQueuedClassInARealRoot
{
    /**
     * @template T
     *
     * @param  string  $body  the class body, between the braces
     * @param  string  $declaration  anything that goes between `final class Name` and the body
     * @param  callable(class-string): T  $while
     * @return T
     */
    protected function planting(string $body, string $declaration, callable $while): mixed
    {
        $name = 'F08Planted'.bin2hex(random_bytes(6));
        $directory = base_path('app/Jobs');
        $path = $directory.'/'.$name.'.php';
        $createdDirectory = ! is_dir($directory);

        if ($createdDirectory && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create '.$directory);
        }

        $remove = static function () use ($path, $directory, $createdDirectory): void {
            if (is_file($path)) {
                unlink($path);
            }

            if ($createdDirectory && is_dir($directory) && glob($directory.'/*') === []) {
                rmdir($directory);
            }
        };

        register_shutdown_function($remove);

        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Jobs;\n\nfinal class {$name} {$declaration}\n{\n{$body}\n}\n");

        try {
            /** @var class-string $class */
            $class = 'App\\Jobs\\'.$name;

            return $while($class);
        } finally {
            $remove();
        }
    }
}
