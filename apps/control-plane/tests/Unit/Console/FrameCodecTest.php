<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Lynomia\Modules\Console\Domain\Exceptions\WebSocketProtocolException;
use Lynomia\Modules\Console\Infrastructure\WebSocket\Frame;
use Lynomia\Modules\Console\Infrastructure\WebSocket\FrameCodec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wire format between a customer's browser and a root console.
 *
 * Written from RFC 6455 rather than pulled in, so it is tested against the
 * RFC's own rules rather than against another implementation's habits. The
 * cases below are the ones where a plausible-looking decoder is wrong in a way
 * that only shows up against a real browser or a real hypervisor.
 */
final class FrameCodecTest extends TestCase
{
    private FrameCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameCodec;
    }

    #[Test]
    public function a_masked_client_frame_round_trips(): void
    {
        $encoded = $this->codec->encode(new Frame(Frame::BINARY, 'hello console'), mask: true);

        // Masked on the wire: the payload must not appear in the bytes.
        $this->assertStringNotContainsString('hello console', $encoded);

        $frame = $this->codec->decode($encoded, expectMasked: true);

        $this->assertNotNull($frame);
        $this->assertSame('hello console', $frame->payload);
        $this->assertSame(Frame::BINARY, $frame->opcode);
        $this->assertSame('', $encoded, 'The decoder left bytes behind after a complete frame.');
    }

    #[Test]
    public function a_server_frame_is_not_masked(): void
    {
        $encoded = $this->codec->encode(new Frame(Frame::BINARY, 'screen update'), mask: false);

        // A server frame carries its payload in the clear, which is how a
        // browser knows the peer is a server.
        $this->assertStringContainsString('screen update', $encoded);

        $frame = $this->codec->decode($encoded, expectMasked: false);
        $this->assertSame('screen update', $frame?->payload);
    }

    #[Test]
    public function a_client_frame_that_arrives_unmasked_is_refused(): void
    {
        /*
         * The specification says to fail the connection. A gateway that
         * tolerated it would be proxying for something that is not a browser,
         * and would do so while believing it was.
         */
        $encoded = $this->codec->encode(new Frame(Frame::BINARY, 'x'), mask: false);

        $this->expectException(WebSocketProtocolException::class);

        $this->codec->decode($encoded, expectMasked: true);
    }

    #[Test]
    public function a_server_frame_that_arrives_masked_is_refused(): void
    {
        $encoded = $this->codec->encode(new Frame(Frame::BINARY, 'x'), mask: true);

        $this->expectException(WebSocketProtocolException::class);

        $this->codec->decode($encoded, expectMasked: false);
    }

    #[Test]
    public function a_frame_split_across_reads_is_not_decoded_until_it_is_whole(): void
    {
        /*
         * The failure that works on loopback and breaks on a real network. A
         * decoder that assumed one read is one frame would hand the upstream
         * half a keystroke.
         */
        $encoded = $this->codec->encode(new Frame(Frame::BINARY, str_repeat('a', 200)), mask: true);

        $buffer = substr($encoded, 0, 10);
        $this->assertNull($this->codec->decode($buffer, expectMasked: true));
        $this->assertSame(substr($encoded, 0, 10), $buffer, 'A partial frame consumed bytes from the buffer.');

        $buffer .= substr($encoded, 10);

        $frame = $this->codec->decode($buffer, expectMasked: true);
        $this->assertSame(str_repeat('a', 200), $frame?->payload);
    }

    #[Test]
    public function two_frames_in_one_read_are_both_decoded(): void
    {
        $buffer = $this->codec->encode(new Frame(Frame::BINARY, 'one'), mask: true)
            .$this->codec->encode(new Frame(Frame::BINARY, 'two'), mask: true);

        $this->assertSame('one', $this->codec->decode($buffer, expectMasked: true)?->payload);
        $this->assertSame('two', $this->codec->decode($buffer, expectMasked: true)?->payload);
        $this->assertNull($this->codec->decode($buffer, expectMasked: true));
    }

    #[Test]
    public function each_length_form_is_encoded_and_read_back(): void
    {
        // 125 is the last 7-bit length, 126 the first 16-bit one, and 65 536
        // the first 64-bit one. Off-by-one here desynchronises two peers.
        foreach ([125, 126, 65_535, 65_536] as $length) {
            $payload = str_repeat('x', $length);
            $buffer = $this->codec->encode(new Frame(Frame::BINARY, $payload), mask: true);

            $this->assertSame($payload, $this->codec->decode($buffer, expectMasked: true)?->payload);
        }
    }

    #[Test]
    public function a_frame_larger_than_the_cap_is_refused_without_allocating_it(): void
    {
        /*
         * The header is two bytes plus a length; a peer can announce a
         * gigabyte in eight of them. The decoder must refuse on the header
         * rather than wait for a payload it would have to hold in memory.
         */
        $header = chr(0x82).chr(0x80 | 127).pack('J', FrameCodec::MAX_PAYLOAD_BYTES + 1).'mask';

        $this->expectException(WebSocketProtocolException::class);

        $this->codec->decode($header, expectMasked: true);
    }

    #[Test]
    public function a_negative_length_is_refused(): void
    {
        // The high bit of a 64-bit length must be zero. PHP reads the rest as
        // a negative integer, which would otherwise pass a "less than the cap"
        // check and then be used as a substring length.
        $header = chr(0x82).chr(0x80 | 127).pack('J', PHP_INT_MAX + 1).'mask';

        $this->expectException(WebSocketProtocolException::class);

        $this->codec->decode($header, expectMasked: true);
    }

    #[Test]
    public function a_fragmented_control_frame_is_refused(): void
    {
        // Control frames are never fragmented. Allowing it would let a peer
        // smuggle a payload past the rule that makes answering a ping safe.
        $buffer = $this->codec->encode(new Frame(Frame::PING, 'x', fin: false), mask: true);

        $this->expectException(WebSocketProtocolException::class);

        $this->codec->decode($buffer, expectMasked: true);
    }

    #[Test]
    public function a_reserved_bit_is_refused(): void
    {
        // No extension was negotiated, so a reserved bit means the peer is
        // speaking a protocol this gateway did not agree to.
        $buffer = chr(0xC2).chr(0x80).'mask';

        $this->expectException(WebSocketProtocolException::class);

        $this->codec->decode($buffer, expectMasked: true);
    }

    #[Test]
    public function each_masked_frame_uses_a_different_key(): void
    {
        // A constant mask defeats the only thing masking is for.
        $first = $this->codec->encode(new Frame(Frame::BINARY, str_repeat('a', 16)), mask: true);
        $second = $this->codec->encode(new Frame(Frame::BINARY, str_repeat('a', 16)), mask: true);

        $this->assertNotSame($first, $second);
    }
}
