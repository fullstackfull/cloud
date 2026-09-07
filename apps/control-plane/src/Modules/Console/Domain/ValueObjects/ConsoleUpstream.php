<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Domain\ValueObjects;

/**
 * Where the gateway connects to reach a machine's console, and with what.
 *
 * Everything in here is a server-side fact and none of it may ever reach a
 * browser: the node's address, the path that identifies the machine at the
 * hypervisor, and — the dangerous one — whatever credential the provider
 * requires. A Proxmox vncproxy ticket is a bearer credential for a root
 * console on a node that hosts other customers, so it is obtained by the
 * gateway on its own connection, used once, and never persisted or logged.
 *
 * @immutable
 */
final readonly class ConsoleUpstream
{
    /**
     * @param  array<string, string>  $headers  Sent on the upstream handshake. Never logged.
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $path,
        public bool $tls = false,
        public array $headers = [],
        public bool $verifyTls = true,
    ) {}

    /**
     * The value for the upstream's Host header.
     */
    public function authority(): string
    {
        return $this->port === ($this->tls ? 443 : 80)
            ? $this->host
            : sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * The address stream_socket_client() connects to.
     */
    public function socketAddress(): string
    {
        return sprintf('%s://%s:%d', $this->tls ? 'ssl' : 'tcp', $this->host, $this->port);
    }

    /**
     * A description safe to put in a log line.
     *
     * The headers are the reason this exists: an operator debugging a console
     * failure needs to know which node was dialled, and must not be handed a
     * console ticket in the same breath.
     *
     * @return array<string, scalar>
     */
    public function forLogging(): array
    {
        return [
            'upstream_host' => $this->host,
            'upstream_port' => $this->port,
            'upstream_tls' => $this->tls,
        ];
    }
}
