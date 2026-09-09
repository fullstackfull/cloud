<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\DTOs;

use Lynomia\Modules\Providers\Infrastructure\Models\ConnectionTest;

/**
 * What one look at a machine produced: the test that got us there, and what
 * the machine said about itself.
 *
 * Facts are empty whenever the test did not reach a usable state. A discovery
 * that returned an inventory for a machine it never authenticated to would
 * have invented one, and inventing hardware is how a plan later targets a
 * disk that is not there.
 */
final readonly class ServerDiscovery
{
    /**
     * @param  array<string, string>  $facts
     */
    public function __construct(
        public ConnectionTest $test,
        public array $facts,
    ) {}
}
