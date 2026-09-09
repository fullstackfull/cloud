<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\Exceptions;

use RuntimeException;

/**
 * A driver nobody can test.
 *
 * A configuration error rather than an operational one: it means a provider
 * instance names a driver the application does not have, which an architecture
 * gate should have caught before it reached a runtime.
 */
final class NoSuchTester extends RuntimeException
{
    /**
     * @param  list<string>  $known
     */
    public static function forDriver(string $driver, array $known): self
    {
        return new self(sprintf(
            'No connection tester is registered for driver [%s]. Registered drivers: %s.',
            $driver,
            $known === [] ? 'none' : implode(', ', $known),
        ));
    }

    public static function mismatched(string $requested, string $actual): self
    {
        return new self(sprintf(
            'The tester registered for driver [%s] reports itself as [%s]. '
            .'A tester registered under the wrong key answers confidently about the wrong system.',
            $requested,
            $actual,
        ));
    }
}
