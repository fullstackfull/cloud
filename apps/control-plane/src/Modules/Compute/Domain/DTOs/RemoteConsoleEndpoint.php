<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

/**
 * Where a hypervisor will accept a console connection for one machine, right
 * now.
 *
 * Short-lived by nature. Providers issue console credentials with their own
 * expiry — Proxmox's vncproxy ticket lasts a minute — so this object is
 * obtained at the moment a console is opened and never stored. Nothing here
 * may reach a browser: the headers are bearer credentials for a root console
 * on a node that hosts other customers, and the host names a machine that is
 * none of a customer's business.
 *
 * @immutable
 */
final readonly class RemoteConsoleEndpoint
{
    /**
     * @param  array<string, string>  $headers  Sent on the upstream handshake. Never logged, never persisted.
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $path,
        public bool $tls = true,
        public array $headers = [],
        public bool $verifyTls = true,
    ) {}
}
