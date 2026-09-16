<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Exceptions;

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

    /**
     * A controlled driver asked to answer for a production row.
     *
     * Reported as "no tester", because for a production row that is the true
     * statement: the fake is registered for rehearsal in this deployment and
     * there is nothing here that can establish anything about a row the
     * readiness engine consults before a product goes on sale.
     *
     * A refusal rather than a result. A result would be recorded as a
     * connection test against a provider that does not exist, and an operator
     * reading "authentication failed" would go looking for a credential.
     */
    public static function forAProductionRow(string $driver): self
    {
        return new self(sprintf(
            'Driver [%s] is a controlled driver for rehearsing the onboarding path, and this provider row is a '
            .'production row. Nothing here can establish whether a production provider is reachable, and a '
            .'production row is what the readiness engine consults before a product is offered for sale.',
            $driver,
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
