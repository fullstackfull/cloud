<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Reachability;

use Lynomia\Modules\Dedicated\Domain\Contracts\HostReachability;

/**
 * Opens a TCP connection and closes it again.
 *
 * Deliberately the smallest possible probe. It sends nothing, reads nothing
 * and negotiates nothing, because everything beyond "the port accepted a
 * connection" would need credentials the platform does not have, and a probe
 * that authenticated would be a probe holding a key to every customer's
 * machine.
 *
 * Errors are swallowed into `false` on purpose: refused, filtered, unroutable
 * and timed out are four different network facts and one product fact — the
 * machine is not answering yet.
 */
final class TcpHostReachability implements HostReachability
{
    public function answers(string $host, int $port, float $timeoutSeconds = 5.0): bool
    {
        $errorNumber = 0;
        $errorMessage = '';

        /*
         * The @ is load bearing rather than lazy. fsockopen raises a PHP
         * warning for every unreachable host, and a verification step that
         * polls a machine which is still booting would otherwise fill the log
         * with warnings for the expected case.
         */
        $connection = @fsockopen($host, $port, $errorNumber, $errorMessage, max(0.1, $timeoutSeconds));

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
