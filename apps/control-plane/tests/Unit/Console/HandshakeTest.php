<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Lynomia\Modules\Console\Domain\Exceptions\WebSocketProtocolException;
use Lynomia\Modules\Console\Infrastructure\WebSocket\Handshake;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandshakeTest extends TestCase
{
    #[Test]
    public function the_accept_key_matches_the_rfc_worked_example(): void
    {
        /*
         * RFC 6455 §1.3 spells out this exact pair. Asserting against it
         * rather than against our own output is the point: a server that
         * hashes without the magic GUID, or echoes the nonce, will still
         * satisfy a permissive client and will fail against every browser.
         */
        $this->assertSame(
            's3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
            Handshake::accept('dGhlIHNhbXBsZSBub25jZQ=='),
        );
    }

    #[Test]
    public function a_request_is_not_parsed_until_its_head_is_complete(): void
    {
        $buffer = "GET /console?session=abc HTTP/1.1\r\nHost: gateway\r\n";

        $this->assertNull(Handshake::parseRequest($buffer));
        $this->assertNotSame('', $buffer, 'A partial head consumed the buffer.');

        $buffer .= "Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: k\r\n\r\n";

        $request = Handshake::parseRequest($buffer);

        $this->assertNotNull($request);
        $this->assertSame('/console', $request->path());
        $this->assertSame('abc', $request->query('session'));
        $this->assertTrue(Handshake::isUpgrade($request));
    }

    #[Test]
    public function header_names_are_matched_regardless_of_capitalisation(): void
    {
        // Browsers do not agree on capitalisation, and the specification says
        // they need not.
        $buffer = "GET /console HTTP/1.1\r\nhost: gateway\r\nUPGRADE: WebSocket\r\n"
            ."connection: keep-alive, Upgrade\r\nSec-Websocket-Version: 13\r\nSEC-WEBSOCKET-KEY: k\r\n\r\n";

        $request = Handshake::parseRequest($buffer);

        $this->assertNotNull($request);
        $this->assertTrue(Handshake::isUpgrade($request));
    }

    #[Test]
    public function a_plain_get_is_not_an_upgrade(): void
    {
        $buffer = "GET /console HTTP/1.1\r\nHost: gateway\r\n\r\n";

        $request = Handshake::parseRequest($buffer);

        $this->assertNotNull($request);
        $this->assertFalse(Handshake::isUpgrade($request));
    }

    #[Test]
    public function an_upstream_that_returns_the_wrong_accept_key_is_refused(): void
    {
        /*
         * An upstream answering 101 without the right accept value is not a
         * WebSocket server — it may be a cache or a proxy replaying a
         * response — and proxying a customer's keystrokes into it would be
         * sending them somewhere unknown.
         */
        $buffer = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n"
            ."Sec-WebSocket-Accept: not-the-right-value\r\n\r\n";

        $this->expectException(WebSocketProtocolException::class);

        Handshake::verifyResponse($buffer, 'dGhlIHNhbXBsZSBub25jZQ==');
    }

    #[Test]
    public function a_correct_upstream_response_is_accepted_and_leaves_the_frames_behind(): void
    {
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';

        $buffer = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n"
            .'Sec-WebSocket-Accept: '.Handshake::accept($key)."\r\n\r\n"
            .'FRAMEBYTES';

        $this->assertTrue(Handshake::verifyResponse($buffer, $key));
        $this->assertSame('FRAMEBYTES', $buffer, 'The verifier ate the first frame.');
    }

    #[Test]
    public function a_refused_upgrade_is_an_error_rather_than_a_wait(): void
    {
        $buffer = "HTTP/1.1 403 Forbidden\r\n\r\n";

        $this->expectException(WebSocketProtocolException::class);

        Handshake::verifyResponse($buffer, 'k');
    }
}
