<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure;

use Closure;
use Illuminate\Contracts\Container\Container;
use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\Exceptions\NoSuchTester;

/**
 * Which tester handles which driver.
 *
 * Deliberately not a singleton, for the same reason every other provider
 * factory in this codebase is not: a test that swaps one driver's
 * implementation must not have to rebuild the world, and a factory resolved
 * once at boot would hand every later caller the instance built with the
 * bindings that existed at boot.
 */
final readonly class ConnectionTesterFactory
{
    /**
     * @param  array<string, class-string<ConnectionTester>|Closure(): ConnectionTester>  $drivers
     *                                                                                              A class to build through the container, or a closure that builds
     *                                                                                              one — the latter for a tester that answers for more than one
     *                                                                                              driver name and has to be told which.
     */
    public function __construct(
        private Container $container,
        private array $drivers,
    ) {}

    public function for(string $driver): ConnectionTester
    {
        $class = $this->drivers[$driver] ?? null;

        if ($class === null) {
            throw NoSuchTester::forDriver($driver, array_keys($this->drivers));
        }

        $tester = is_string($class) ? $this->container->make($class) : $class();

        // A tester registered under the wrong key would test the wrong thing
        // and report a confident answer about it. Cheap to check, and the
        // failure it prevents is the kind nobody investigates.
        if ($tester->driver() !== $driver) {
            throw NoSuchTester::mismatched($driver, $tester->driver());
        }

        return $tester;
    }

    /** @return list<string> */
    public function drivers(): array
    {
        return array_keys($this->drivers);
    }

    public function handles(string $driver): bool
    {
        return array_key_exists($driver, $this->drivers);
    }
}
