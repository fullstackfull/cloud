<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Infrastructure;

/**
 * One customer's console, from the socket accepting to the socket closing.
 *
 * Mutable and deliberately dumb: it holds the two sockets, the two buffers and
 * the three clocks, and makes no decisions. Every decision about this
 * connection is made by the server, which is the class a reviewer has to read.
 */
final class GatewayConnection
{
    /** @var resource|null */
    public $upstream = null;

    public string $clientBuffer = '';

    public string $upstreamBuffer = '';

    public bool $handshakeComplete = false;

    public bool $upstreamReady = false;

    public string $upstreamKey = '';

    public float $openedAt;

    public float $lastActivityAt;

    /** When the permit was spent. Null until it has been. */
    public ?float $authorisedAt = null;

    public ?string $virtualMachineId = null;

    /**
     * @param  resource  $client
     */
    public function __construct(
        public $client,
        public readonly string $peer,
    ) {
        $this->openedAt = microtime(true);
        $this->lastActivityAt = $this->openedAt;
    }

    public function touch(): void
    {
        $this->lastActivityAt = microtime(true);
    }

    /**
     * The peer without its ephemeral port.
     *
     * Rate limiting keys on this: including the port would give every
     * connection its own bucket, which is the same as having no limit.
     */
    public function peerAddress(): string
    {
        $colon = strrpos($this->peer, ':');

        return $colon === false ? $this->peer : substr($this->peer, 0, $colon);
    }
}
