<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Infrastructure\WebSocket;

use Lynomia\Modules\Console\Domain\Exceptions\WebSocketProtocolException;

/**
 * The HTTP request that turns into a WebSocket, in both directions.
 *
 * The accept key is the interesting part and the reason this is not a
 * one-liner. RFC 6455 fixes a magic GUID that both ends append to the client's
 * nonce before hashing; a server that echoes the nonce, or hashes without the
 * GUID, will still connect to a permissive client and will silently fail
 * against a browser. It is written out here so a reviewer can check it against
 * the RFC rather than against a library's tests.
 */
final class Handshake
{
    /** RFC 6455 §1.3. Not a secret, not configurable, and not ours to change. */
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * The value a server must answer with for a given client nonce.
     */
    public static function accept(string $clientKey): string
    {
        return base64_encode(sha1($clientKey.self::GUID, binary: true));
    }

    /**
     * Parse an inbound upgrade request.
     *
     * Returns null while the request is still arriving — the header block ends
     * with a blank line, and a socket read can stop anywhere — so the caller
     * keeps buffering rather than rejecting a request it has only half read.
     *
     * @param  string  $buffer  Consumed in place up to and including the blank line.
     *
     * @throws WebSocketProtocolException
     */
    public static function parseRequest(string &$buffer): ?ServerHandshakeRequest
    {
        $end = strpos($buffer, "\r\n\r\n");

        if ($end === false) {
            if (strlen($buffer) > 16_384) {
                // A request head this long is not a browser opening a console.
                throw WebSocketProtocolException::because('the request head exceeded 16 KiB');
            }

            return null;
        }

        $head = substr($buffer, 0, $end);
        $buffer = substr($buffer, $end + 4);

        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines);

        if (! preg_match('#^(GET) (\S+) HTTP/1\.1$#', $requestLine, $matches)) {
            throw WebSocketProtocolException::because('the request line was not a HTTP/1.1 GET');
        }

        $headers = [];

        foreach ($lines as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            // Header names are case-insensitive and browsers do not agree on
            // capitalisation; lowercased once here so every later lookup is
            // exact rather than hopeful.
            $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        return new ServerHandshakeRequest($matches[2], $headers);
    }

    /**
     * Whether a parsed request is actually asking to become a WebSocket.
     */
    public static function isUpgrade(ServerHandshakeRequest $request): bool
    {
        return str_contains(strtolower($request->header('upgrade') ?? ''), 'websocket')
            && str_contains(strtolower($request->header('connection') ?? ''), 'upgrade')
            && ($request->header('sec-websocket-version') ?? '') === '13'
            && ($request->header('sec-websocket-key') ?? '') !== '';
    }

    /**
     * The 101 a server sends to complete the upgrade.
     */
    public static function acceptResponse(string $clientKey): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            .'Sec-WebSocket-Accept: '.self::accept($clientKey)."\r\n"
            ."\r\n";
    }

    /**
     * The request a client sends to open one.
     *
     * @param  array<string, string>  $headers  Anything the upstream needs — a
     *                                          provider ticket, a cookie — which
     *                                          is why this is server-to-server.
     */
    public static function clientRequest(string $host, string $path, string $key, array $headers = []): string
    {
        $request = sprintf("GET %s HTTP/1.1\r\n", $path)
            .sprintf("Host: %s\r\n", $host)
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            .sprintf("Sec-WebSocket-Key: %s\r\n", $key)
            ."Sec-WebSocket-Version: 13\r\n";

        foreach ($headers as $name => $value) {
            $request .= sprintf("%s: %s\r\n", $name, $value);
        }

        return $request."\r\n";
    }

    /**
     * Check a server's 101 against the nonce we sent.
     *
     * @param  string  $buffer  Consumed in place on success.
     *
     * @throws WebSocketProtocolException
     */
    public static function verifyResponse(string &$buffer, string $sentKey): bool
    {
        $end = strpos($buffer, "\r\n\r\n");

        if ($end === false) {
            return false;
        }

        $head = substr($buffer, 0, $end);
        $rest = substr($buffer, $end + 4);

        if (! str_starts_with($head, 'HTTP/1.1 101')) {
            throw WebSocketProtocolException::because('the upstream refused the upgrade');
        }

        if (! preg_match('#^sec-websocket-accept:\s*(\S+)$#mi', $head, $matches)) {
            throw WebSocketProtocolException::because('the upstream sent no accept key');
        }

        /*
         * Compared rather than assumed. An upstream that answers 101 without
         * the right accept value is not a WebSocket server — it may be a proxy
         * or a cache replaying a response — and proxying console bytes into it
         * would be sending a customer's keystrokes somewhere unknown.
         */
        if (! hash_equals(self::accept($sentKey), $matches[1])) {
            throw WebSocketProtocolException::because('the upstream returned the wrong accept key');
        }

        $buffer = $rest;

        return true;
    }
}
