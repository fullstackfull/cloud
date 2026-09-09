<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Infrastructure;

use Illuminate\Contracts\Container\Container;
use Lynomia\Modules\Estate\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Estate\Domain\Exceptions\NoSuchTester;

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
     * @param  array<string, class-string<ConnectionTester>>  $drivers
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

        $tester = $this->container->make($class);

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
