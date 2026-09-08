<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Lynomia\Modules\Console\Infrastructure\WebSocket\Frame;
use Lynomia\Modules\Console\Infrastructure\WebSocket\FrameCodec;
use Lynomia\Modules\Console\Infrastructure\WebSocket\Handshake;

/**
 * A hypervisor console, stood in for by something a test controls.
 *
 * A real WebSocket server on a real socket: it completes the RFC handshake,
 * reads masked client frames and answers with unmasked server frames, exactly
 * as Proxmox's vncwebsocket endpoint does. That fidelity is the point — a
 * mocked upstream would prove the gateway calls a method, and what needs
 * proving is that the gateway speaks the protocol.
 *
 * It echoes what it is sent, prefixed, so a test can tell a byte that made the
 * round trip from one that was reflected by the gateway.
 */
final class ControlledConsoleUpstream
{
    /** @var resource */
    private $listener;

    /** @var resource|null */
    private $client = null;

    private string $buffer = '';

    private bool $handshakeComplete = false;

    /** @var list<string> */
    public array $received = [];

    public array $sawHeaders = [];

    public bool $refuseUpgrade = false;

    private FrameCodec $codec;

    public function __construct()
    {
        $errorNumber = 0;
        $errorMessage = '';

        // Port 0: the kernel picks a free one, so a suite running in parallel
        // never collides with itself.
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

        if ($listener === false) {
            throw new \RuntimeException('The controlled upstream could not listen: '.$errorMessage);
        }

        stream_set_blocking($listener, false);

        $this->listener = $listener;
        $this->codec = new FrameCodec;
    }

    public function port(): int
    {
        $name = (string) stream_socket_get_name($this->listener, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Service the socket once. Called between gateway ticks.
     */
    public function tick(): void
    {
        if ($this->client === null) {
            $client = @stream_socket_accept($this->listener, 0);

            if ($client === false) {
                return;
            }

            stream_set_blocking($client, false);
            $this->client = $client;
        }

        $chunk = @fread($this->client, 65_536);

        if ($chunk === false || $chunk === '') {
            return;
        }

        $this->buffer .= $chunk;

        if (! $this->handshakeComplete) {
            $request = Handshake::parseRequest($this->buffer);

            if ($request === null) {
                return;
            }

            $this->sawHeaders = $request->headers;

            if ($this->refuseUpgrade) {
                @fwrite($this->client, "HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n");
                $this->close();

                return;
            }

            @fwrite($this->client, Handshake::acceptResponse((string) $request->header('sec-websocket-key')));
            $this->handshakeComplete = true;
        }

        while (($frame = $this->codec->decode($this->buffer, expectMasked: true)) !== null) {
            if ($frame->opcode === Frame::CLOSE) {
                $this->close();

                return;
            }

            if ($frame->isControl()) {
                continue;
            }

            $this->received[] = $frame->payload;

            // Unmasked, because a server never masks. The prefix distinguishes
            // a byte that reached the hypervisor from one the gateway echoed.
            @fwrite($this->client, $this->codec->encode(
                new Frame(Frame::BINARY, 'upstream:'.$frame->payload),
                mask: false,
            ));
        }
    }

    /**
     * Push a frame at the gateway without being asked, as a screen update.
     */
    public function send(string $payload): void
    {
        if ($this->client === null) {
            return;
        }

        @fwrite($this->client, $this->codec->encode(new Frame(Frame::BINARY, $payload), mask: false));
    }

    public function isConnected(): bool
    {
        return $this->client !== null && ! feof($this->client);
    }

    public function close(): void
    {
        if ($this->client !== null) {
            @fclose($this->client);
            $this->client = null;
        }
    }

    public function shutdown(): void
    {
        $this->close();
        @fclose($this->listener);
    }
}
