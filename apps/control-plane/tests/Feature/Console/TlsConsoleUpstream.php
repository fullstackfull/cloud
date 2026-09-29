<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use RuntimeException;

/**
 * A hypervisor's console port behind TLS, answering from a process of its own.
 *
 * It has to be another process. The gateway dials with a blocking
 * `stream_socket_client()`, which completes the TLS handshake — and the
 * certificate checks — before it returns, so a server serviced between gateway
 * ticks, the way {@see ControlledConsoleUpstream} is, would never get the
 * chance to answer it.
 *
 * It reports each connection as one line: whether the TLS handshake completed
 * and, the part that matters, whether a request arrived after it and which
 * Authorization header that request carried. On a Proxmox console that header
 * is the cluster's API token, so "no request arrived" is the observable form
 * of "the token did not go to whoever answered". A request that does arrive is
 * answered with a real 101, so the gateway can go on to open the console.
 */
final class TlsConsoleUpstream
{
    /**
     * The server, run as `php -r` with the certificate-and-key file as its
     * only argument. Plain TCP with crypto enabled after accept, so that a
     * connection that fails its handshake is told apart from no connection.
     */
    private const string SERVER = <<<'PHP'
        $context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'verify_peer' => false]]);
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($server === false) {
            fwrite(STDERR, $errorMessage);
            exit(1);
        }
        $name = (string) stream_socket_get_name($server, false);
        fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1)."\n");
        $held = [];
        while (true) {
            $client = @stream_socket_accept($server, 60);
            if ($client === false) {
                continue;
            }
            stream_set_timeout($client, 10);
            $report = ['handshake' => false, 'request' => null, 'authorization' => null];
            if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true) {
                $report['handshake'] = true;
                $head = '';
                while (! str_contains($head, "\r\n\r\n")) {
                    $chunk = @fread($client, 8192);
                    if ($chunk === false || $chunk === '') {
                        if (feof($client) || stream_get_meta_data($client)['timed_out']) {
                            break;
                        }
                        continue;
                    }
                    $head .= $chunk;
                }
                if (str_contains($head, "\r\n\r\n")) {
                    $lines = explode("\r\n", substr($head, 0, (int) strpos($head, "\r\n\r\n")));
                    $report['request'] = array_shift($lines);
                    $key = '';
                    foreach ($lines as $line) {
                        [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
                        $field = strtolower(trim($field));
                        if ($field === 'authorization') {
                            $report['authorization'] = trim($value);
                        }
                        if ($field === 'sec-websocket-key') {
                            $key = trim($value);
                        }
                    }
                    fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
                        .'Sec-WebSocket-Accept: '.base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))."\r\n\r\n");
                    $held[] = $client;
                }
            }
            fwrite(STDOUT, json_encode($report)."\n");
        }
        PHP;

    /** @var resource */
    private $process;

    /** @var resource */
    private $output;

    private int $port;

    private string $buffer = '';

    /**
     * @param  string  $certificateAndKey  A file holding the certificate the server presents, followed by its key.
     */
    public function __construct(string $certificateAndKey)
    {
        $pipes = [];

        $process = proc_open(
            [PHP_BINARY, '-r', self::SERVER, $certificateAndKey],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The TLS console upstream could not be started.');
        }

        $this->process = $process;
        $this->output = $pipes[1];
        stream_set_blocking($this->output, false);

        $port = $this->nextLine(10.0);

        if ($port === null || ! ctype_digit($port)) {
            $this->shutdown();

            throw new RuntimeException('The TLS console upstream did not report a port: '.(string) stream_get_contents($pipes[2]));
        }

        $this->port = (int) $port;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * What happened to the next connection, waiting up to $seconds for one.
     *
     * @return array{handshake: bool, request: ?string, authorization: ?string}|null Null if nobody connected.
     */
    public function nextConnection(float $seconds = 10.0): ?array
    {
        $line = $this->nextLine($seconds);

        if ($line === null) {
            return null;
        }

        /** @var array{handshake: bool, request: ?string, authorization: ?string} $report */
        $report = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        return $report;
    }

    public function shutdown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private function nextLine(float $seconds): ?string
    {
        $deadline = microtime(true) + $seconds;

        while (! str_contains($this->buffer, "\n")) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return null;
            }

            $read = [$this->output];
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 0, (int) min(100_000, $remaining * 1_000_000)) > 0) {
                $chunk = fread($this->output, 8192);

                if ($chunk === false || ($chunk === '' && feof($this->output))) {
                    return null;
                }

                $this->buffer .= $chunk;
            }
        }

        $position = (int) strpos($this->buffer, "\n");
        $line = substr($this->buffer, 0, $position);
        $this->buffer = substr($this->buffer, $position + 1);

        return $line;
    }
}
