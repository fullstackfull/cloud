<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Infrastructure;

use Closure;
use Lynomia\Modules\Console\Application\Actions\AuthoriseConsoleConnection;
use Lynomia\Modules\Console\Domain\Exceptions\ConsoleConnectionRefusedException;
use Lynomia\Modules\Console\Domain\Exceptions\WebSocketProtocolException;
use Lynomia\Modules\Console\Domain\ValueObjects\ConsoleUpstream;
use Lynomia\Modules\Console\Infrastructure\WebSocket\Frame;
use Lynomia\Modules\Console\Infrastructure\WebSocket\FrameCodec;
use Lynomia\Modules\Console\Infrastructure\WebSocket\Handshake;
use Throwable;

/**
 * The Lynomia Console Gateway.
 *
 * A separate process, deliberately. Everything about a console is wrong for a
 * request-response worker: it lives for as long as somebody is looking at a
 * screen, it holds two sockets open the whole time, and it must not occupy a
 * PHP-FPM worker that the rest of the platform needs. Running it under
 * `console-gateway:serve` also means it can be deployed on its own hosts, in
 * its own count, and restarted without touching the API.
 *
 * ---------------------------------------------------------------------------
 * What it is and is not allowed to do
 * ---------------------------------------------------------------------------
 *
 * It moves bytes. It does not interpret them, log them, or store them: the
 * bytes are a customer's keystrokes and their screen, and the only defensible
 * thing to do with either is to forward them.
 *
 * The security decision is made once, before any byte moves, by
 * {@see AuthoriseConsoleConnection}. This class never sees a token after that
 * call and never learns why a connection was refused — it closes the socket
 * with the same code whatever the reason, because a gateway that answered
 * differently would let anybody who can reach it tell a live session id from a
 * dead one.
 *
 * ---------------------------------------------------------------------------
 * Why hand-rolled sockets
 * ---------------------------------------------------------------------------
 *
 * One `stream_select` loop over a handful of sockets, with no dependency to
 * audit, is a thing a reviewer can read end to end — which matters more here
 * than anywhere else in the platform, because this process stands between a
 * browser and a root console. The WebSocket framing it needs is in
 * {@see FrameCodec}, written from the RFC rather than pulled in.
 *
 * ---------------------------------------------------------------------------
 * Three timeouts, three different failures
 * ---------------------------------------------------------------------------
 *
 *  - **handshake** — a socket that connects and says nothing is broken or
 *    probing; it is closed before it costs anything.
 *  - **idle** — no bytes in either direction. A laptop closed on an open
 *    console is the ordinary case, not the attack, and leaving it open leaves
 *    root access sitting there.
 *  - **session** — the hard ceiling. A console is granted for a reason, and a
 *    connection that outlives the reason is a credential nobody re-checked.
 */
final class GatewayServer
{
    /** Normal closure. */
    private const int CLOSE_NORMAL = 1000;

    /** Policy violation: the one code every refusal uses. */
    private const int CLOSE_REFUSED = 1008;

    private const int CLOSE_UPSTREAM_GONE = 1011;

    private const int READ_CHUNK = 65_536;

    /** @var array<int, GatewayConnection> */
    private array $connections = [];

    /** @var resource|null */
    private $listener = null;

    private bool $running = false;

    public function __construct(
        private readonly AuthoriseConsoleConnection $authorise,
        private readonly FrameCodec $codec,
        private readonly GatewayMetrics $metrics,
        /** Where a log line goes. Injected so the command owns the formatting. */
        private readonly Closure $log,
    ) {}

    /**
     * Start listening. Returns the address actually bound, which matters when
     * the configured port is 0 and the kernel chooses one.
     *
     * @throws \RuntimeException
     */
    public function listen(string $host, int $port): string
    {
        $errorNumber = 0;
        $errorMessage = '';

        $listener = stream_socket_server(
            sprintf('tcp://%s:%d', $host, $port),
            $errorNumber,
            $errorMessage,
        );

        if ($listener === false) {
            throw new \RuntimeException(sprintf('The console gateway could not listen on %s:%d: %s', $host, $port, $errorMessage));
        }

        stream_set_blocking($listener, false);

        $this->listener = $listener;

        $name = stream_socket_get_name($listener, false);

        return is_string($name) ? $name : sprintf('%s:%d', $host, $port);
    }

    /**
     * Run the loop until stopped.
     *
     * @param  float  $tickSeconds  How long one select may block. Also the granularity of every timeout.
     */
    public function run(float $tickSeconds = 1.0): void
    {
        $this->running = true;

        while ($this->running) {
            $this->tick($tickSeconds);
        }
    }

    /**
     * One pass of the loop, exposed so a test can drive the server without a
     * thread — and so the command can check for signals between passes.
     */
    public function tick(float $seconds = 0.05): void
    {
        $read = [];

        if ($this->listener !== null) {
            $read[] = $this->listener;
        }

        foreach ($this->connections as $connection) {
            $read[] = $connection->client;

            if ($connection->upstream !== null) {
                $read[] = $connection->upstream;
            }
        }

        $write = [];
        $except = [];

        $microseconds = (int) round(($seconds - floor($seconds)) * 1_000_000);

        // The @ silences the warning a signal raises here. An interrupted
        // select is not an error: it is how a supervisor asks a process to
        // stop, and the loop's next pass notices.
        $ready = @stream_select($read, $write, $except, (int) floor($seconds), $microseconds);

        if ($ready === false || $ready === 0) {
            $this->closeExpired();

            return;
        }

        foreach ($read as $stream) {
            if ($this->listener !== null && $stream === $this->listener) {
                $this->accept();

                continue;
            }

            $this->readFrom($stream);
        }

        $this->closeExpired();
    }

    public function stop(): void
    {
        $this->running = false;

        foreach ($this->connections as $connection) {
            $this->close($connection, self::CLOSE_NORMAL, 'the gateway is shutting down');
        }

        if ($this->listener !== null) {
            fclose($this->listener);
            $this->listener = null;
        }
    }

    public function openSessions(): int
    {
        return count(array_filter(
            $this->connections,
            static fn (GatewayConnection $connection): bool => $connection->upstream !== null,
        ));
    }

    private function accept(): void
    {
        if ($this->listener === null) {
            return;
        }

        $client = @stream_socket_accept($this->listener, 0, $peer);

        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);

        if (count($this->connections) >= $this->limit('max_sessions')) {
            /*
             * Refused at the door rather than queued. A gateway that accepted
             * beyond its capacity would hold sockets it cannot service and
             * fail every console on the process rather than the one that
             * arrived last.
             */
            $this->metrics->refused('at_capacity');
            fclose($client);

            return;
        }

        $this->connections[(int) $client] = new GatewayConnection($client, is_string($peer) ? $peer : 'unknown');
        $this->metrics->accepted();
    }

    /**
     * @param  resource  $stream
     */
    private function readFrom($stream): void
    {
        $connection = $this->connectionFor($stream);

        if ($connection === null) {
            return;
        }

        $chunk = @fread($stream, self::READ_CHUNK);

        if ($chunk === false || $chunk === '') {
            if (feof($stream)) {
                // Either end hanging up ends the session. A console with one
                // live half is not a console.
                $this->close($connection, self::CLOSE_NORMAL, 'the peer disconnected');
            }

            return;
        }

        $connection->touch();

        try {
            if ($stream === $connection->client) {
                $this->handleClientBytes($connection, $chunk);

                return;
            }

            $this->handleUpstreamBytes($connection, $chunk);
        } catch (WebSocketProtocolException $e) {
            ($this->log)('warning', 'A console peer broke the WebSocket protocol.', [
                'peer' => $connection->peer,
                'reason' => $e->getMessage(),
            ]);

            $this->close($connection, self::CLOSE_REFUSED, 'protocol error');
        } catch (Throwable $e) {
            ($this->log)('error', 'A console connection failed.', [
                'peer' => $connection->peer,
                'reason' => $e->getMessage(),
            ]);

            $this->close($connection, self::CLOSE_UPSTREAM_GONE, 'internal failure');
        }
    }

    private function handleClientBytes(GatewayConnection $connection, string $chunk): void
    {
        $connection->clientBuffer .= $chunk;

        if (! $connection->handshakeComplete) {
            $this->completeHandshake($connection);

            return;
        }

        /*
         * Frames are decoded and re-encoded rather than copied through. The
         * two directions have different masking rules — a client masks, a
         * server does not — so a proxy that forwarded bytes verbatim would
         * send masked frames to an upstream that must reject them.
         */
        while (($frame = $this->codec->decode($connection->clientBuffer, expectMasked: true)) !== null) {
            if ($frame->opcode === Frame::CLOSE) {
                $this->close($connection, self::CLOSE_NORMAL, 'the client closed the console');

                return;
            }

            if ($frame->opcode === Frame::PING) {
                // Answered here rather than forwarded: a ping is about this
                // hop, and the upstream has its own keepalive.
                $this->writeClient($connection, new Frame(Frame::PONG, $frame->payload));

                continue;
            }

            if ($frame->opcode === Frame::PONG) {
                continue;
            }

            if ($connection->upstream === null) {
                continue;
            }

            @fwrite($connection->upstream, $this->codec->encode($frame, mask: true));
        }
    }

    private function handleUpstreamBytes(GatewayConnection $connection, string $chunk): void
    {
        $connection->upstreamBuffer .= $chunk;

        if (! $connection->upstreamReady) {
            if (! Handshake::verifyResponse($connection->upstreamBuffer, $connection->upstreamKey)) {
                return;
            }

            $connection->upstreamReady = true;
            $this->metrics->opened();
        }

        while (($frame = $this->codec->decode($connection->upstreamBuffer, expectMasked: false)) !== null) {
            if ($frame->opcode === Frame::CLOSE) {
                $this->close($connection, self::CLOSE_NORMAL, 'the hypervisor closed the console');

                return;
            }

            if ($frame->opcode === Frame::PING) {
                @fwrite($connection->upstream ?? STDERR, $this->codec->encode(new Frame(Frame::PONG, $frame->payload), mask: true));

                continue;
            }

            if ($frame->opcode === Frame::PONG) {
                continue;
            }

            $this->writeClient($connection, $frame);
        }
    }

    /**
     * Whether a browser on this origin may open a console.
     *
     * An absent Origin is allowed: native clients send none, and refusing them
     * would break every non-browser console without stopping any attacker —
     * who is not constrained by a browser either. Configured empty, the check
     * is off, which is the honest default for a deployment that has not said
     * where its portal lives.
     */
    private function originIsAllowed(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }

        /** @var list<string> $allowed */
        $allowed = config('console_gateway.allowed_origins', []);

        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $candidate) {
            // Compared whole and case-insensitively on the scheme and host,
            // never by prefix: `https://portal.lynomia.test.attacker.example`
            // starts with the portal's own origin.
            if (strcasecmp(rtrim($candidate, '/'), rtrim($origin, '/')) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the browser's upgrade request, authorise it, and dial the upstream.
     */
    private function completeHandshake(GatewayConnection $connection): void
    {
        $request = Handshake::parseRequest($connection->clientBuffer);

        if ($request === null) {
            return;
        }

        if (! Handshake::isUpgrade($request)) {
            /*
             * Answered with a bare 400 and closed. The gateway serves exactly
             * one thing and is not a web server; a health check belongs on the
             * platform's own monitoring surface, not on the socket that
             * carries consoles.
             */
            @fwrite($connection->client, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            $this->close($connection, self::CLOSE_REFUSED, 'not a websocket upgrade');

            return;
        }

        if (! $this->originIsAllowed($request->header('origin'))) {
            /*
             * A page on another origin. The permit is what actually
             * authenticates a console and a hostile page cannot read one, so
             * this is defence in depth — but a WebSocket is not subject to the
             * same-origin policy, and a gateway that any page can reach is a
             * gateway any page can spend the rate limit of.
             *
             * Refused with the same close code as every other refusal, and
             * counted under its own reason so an operator can tell a
             * misconfigured portal from an attack.
             */
            $connection->handshakeComplete = true;
            $this->metrics->refused('origin_not_allowed');
            $this->writeClient($connection, $this->closeFrame(self::CLOSE_REFUSED, 'refused'));
            $this->close($connection, self::CLOSE_REFUSED, 'refused');

            return;
        }

        $connection->handshakeComplete = true;

        try {
            $authorised = $this->authorise->execute(
                sessionId: $request->query('session') ?? '',
                token: $request->query('token') ?? '',
                claimedMachineId: $request->query('machine') ?? '',
                peer: $connection->peerAddress(),
            );
        } catch (ConsoleConnectionRefusedException $e) {
            $this->metrics->refused($e->reason);

            /*
             * The 101 is sent first, then an immediate policy-violation close.
             * A browser's WebSocket API surfaces an HTTP error as an opaque
             * failure with no status, so refusing at the HTTP layer would give
             * the customer "connection failed" and nothing else; a close frame
             * with a code is something the portal can turn into "that console
             * link has already been used".
             */
            @fwrite($connection->client, Handshake::acceptResponse((string) $request->header('sec-websocket-key')));
            $this->writeClient($connection, $this->closeFrame(self::CLOSE_REFUSED, 'refused'));
            $this->close($connection, self::CLOSE_REFUSED, $e->reason);

            return;
        }

        @fwrite($connection->client, Handshake::acceptResponse((string) $request->header('sec-websocket-key')));

        $connection->authorisedAt = microtime(true);
        $connection->virtualMachineId = $authorised->virtualMachineId;

        $this->dialUpstream($connection, $authorised->upstream);
    }

    private function dialUpstream(GatewayConnection $connection, ConsoleUpstream $upstream): void
    {
        $context = stream_context_create([
            'ssl' => [
                // Off only where the deployment has said so — a fake upstream
                // in a test, or a lab cluster with a self-signed certificate.
                // The default is on, and it is on for the same reason the API
                // adapter's is: an unverified TLS connection to a hypervisor
                // is a console proxied to whoever answered.
                'verify_peer' => $upstream->verifyTls,
                'verify_peer_name' => $upstream->verifyTls,
            ],
        ]);

        $errorNumber = 0;
        $errorMessage = '';

        $socket = @stream_socket_client(
            $upstream->socketAddress(),
            $errorNumber,
            $errorMessage,
            (float) $this->limit('upstream_connect_seconds'),
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            ($this->log)('warning', 'A console upstream refused the connection.', [
                'peer' => $connection->peer,
                ...$upstream->forLogging(),
                'reason' => $errorMessage,
            ]);

            $this->metrics->upstreamFailed();
            $this->writeClient($connection, $this->closeFrame(self::CLOSE_UPSTREAM_GONE, 'upstream unavailable'));
            $this->close($connection, self::CLOSE_UPSTREAM_GONE, 'upstream unavailable');

            return;
        }

        stream_set_blocking($socket, false);

        $connection->upstream = $socket;
        $connection->upstreamKey = base64_encode(random_bytes(16));

        @fwrite($socket, Handshake::clientRequest(
            $upstream->authority(),
            $upstream->path,
            $connection->upstreamKey,
            $upstream->headers,
        ));
    }

    private function writeClient(GatewayConnection $connection, Frame $frame): void
    {
        @fwrite($connection->client, $this->codec->encode($frame, mask: false));
    }

    private function closeFrame(int $code, string $reason): Frame
    {
        return new Frame(Frame::CLOSE, pack('n', $code).$reason);
    }

    private function close(GatewayConnection $connection, int $code, string $why): void
    {
        if ($connection->upstream !== null) {
            @fwrite($connection->upstream, $this->codec->encode($this->closeFrame($code, 'closing'), mask: true));
            @fclose($connection->upstream);
        }

        @fclose($connection->client);

        unset($this->connections[(int) $connection->client]);

        $this->metrics->closed($why);
    }

    /**
     * Close whatever has run out of time.
     */
    private function closeExpired(): void
    {
        $now = microtime(true);

        foreach ($this->connections as $connection) {
            if (! $connection->handshakeComplete && $now - $connection->openedAt > $this->limit('handshake_seconds')) {
                $this->close($connection, self::CLOSE_REFUSED, 'handshake timeout');

                continue;
            }

            if ($now - $connection->lastActivityAt > $this->limit('idle_seconds')) {
                $this->close($connection, self::CLOSE_NORMAL, 'idle timeout');

                continue;
            }

            if ($connection->authorisedAt !== null && $now - $connection->authorisedAt > $this->limit('session_seconds')) {
                $this->close($connection, self::CLOSE_NORMAL, 'session limit');
            }
        }
    }

    /**
     * @param  resource  $stream
     */
    private function connectionFor($stream): ?GatewayConnection
    {
        foreach ($this->connections as $connection) {
            if ($connection->client === $stream || $connection->upstream === $stream) {
                return $connection;
            }
        }

        return null;
    }

    private function limit(string $name): int
    {
        $value = config('console_gateway.limits.'.$name);

        return is_numeric($value) ? (int) $value : 60;
    }
}
