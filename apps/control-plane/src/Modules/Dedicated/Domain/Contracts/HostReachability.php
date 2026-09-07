<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Contracts;

/**
 * Whether a host answers on a port.
 *
 * This is the platform's only in-band check on a physical machine, and its
 * limits are the reason it is a contract of its own rather than a helper
 * function. It answers "something is listening", which after an unattended
 * install means the machine booted, brought up its network on the address the
 * platform gave it, and started sshd. It does **not** answer "the operating
 * system is the one we asked for", "the customer's key works", or "the machine
 * is healthy". Nothing here should be described as an SSH login, because it is
 * not one: the platform holds no private key for a customer's machine and has
 * no business holding one.
 *
 * Behind an interface so the verification step can be exercised without a
 * network, and so a deployment that reaches its machines through a bastion can
 * substitute something that knows how.
 */
interface HostReachability
{
    /**
     * @param  string  $host  An IP address or hostname.
     * @param  int  $port  The port to knock on.
     * @param  float  $timeoutSeconds  How long to wait before giving up.
     * @return bool true when something accepted the connection
     */
    public function answers(string $host, int $port, float $timeoutSeconds = 5.0): bool;
}
