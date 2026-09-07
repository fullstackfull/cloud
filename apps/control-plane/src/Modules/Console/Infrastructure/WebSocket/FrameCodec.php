<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Infrastructure\WebSocket;

use Lynomia\Modules\Console\Domain\Exceptions\WebSocketProtocolException;

/**
 * Reads and writes RFC 6455 frames.
 *
 * Written here rather than pulled in, because the gateway is the one process
 * that stands between a customer's browser and a root console, and its wire
 * handling is exactly the part that must be readable by whoever reviews this
 * platform's security.
 *
 * Three rules the RFC states and implementations get wrong:
 *
 *  - **A client's frames are always masked; a server's never are.** A server
 *    that accepts an unmasked client frame, or masks its own, is talking to
 *    something that is not a browser, and the specification says to fail the
 *    connection rather than tolerate it.
 *  - **The length field has three forms** — 7 bits, 7+16 and 7+64 — and a
 *    payload that fits a shorter form must use it. Accepting a longer encoding
 *    of a short length is how two peers disagree about frame boundaries.
 *  - **A frame can arrive in pieces.** `decode()` therefore consumes from a
 *    buffer and returns null when the buffer does not yet hold a whole frame,
 *    leaving the buffer untouched. A decoder that assumed one read equals one
 *    frame works on a loopback socket and fails on a real network.
 */
final class FrameCodec
{
    /**
     * The largest payload the gateway will accept in one frame.
     *
     * A console is keystrokes and screen updates; nothing legitimate here is
     * megabytes. The cap exists so a peer cannot make the gateway allocate an
     * arbitrary buffer by announcing a 2^63-byte frame.
     */
    public const int MAX_PAYLOAD_BYTES = 1_048_576;

    /**
     * Take one frame off the front of the buffer.
     *
     * @param  string  $buffer  Consumed in place: whatever is left is the start of the next frame.
     *
     * @throws WebSocketProtocolException
     */
    public function decode(string &$buffer, bool $expectMasked): ?Frame
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);

        $fin = ($first & 0x80) !== 0;
        $reserved = $first & 0x70;
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7F;

        if ($reserved !== 0) {
            // No extension has been negotiated, so a reserved bit set means
            // the peer is speaking a protocol this gateway did not agree to.
            throw WebSocketProtocolException::because('a reserved bit was set on a frame');
        }

        if ($masked !== $expectMasked) {
            throw WebSocketProtocolException::because($expectMasked
                ? 'a client frame arrived unmasked'
                : 'a server frame arrived masked');
        }

        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < $offset + 2) {
                return null;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('n', substr($buffer, $offset, 2));
            $length = $unpacked[1];
            $offset += 2;
        } elseif ($length === 127) {
            if (strlen($buffer) < $offset + 8) {
                return null;
            }

            /** @var array{1: int} $unpacked */
            $unpacked = unpack('J', substr($buffer, $offset, 8));
            $length = $unpacked[1];
            $offset += 8;

            if ($length < 0) {
                // The high bit must be zero. A negative value here is a peer
                // announcing more than 2^63 bytes, which is not a frame.
                throw WebSocketProtocolException::because('a frame announced a negative length');
            }
        }

        if ($length > self::MAX_PAYLOAD_BYTES) {
            throw WebSocketProtocolException::because(sprintf(
                'a frame announced %d bytes, over the %d-byte limit',
                $length,
                self::MAX_PAYLOAD_BYTES,
            ));
        }

        if ($opcode >= Frame::CLOSE && ($length > 125 || ! $fin)) {
            // Control frames are never fragmented and never long. Tolerating
            // either would let a peer smuggle a payload past the buffering
            // rules the proxy relies on.
            throw WebSocketProtocolException::because('a control frame was fragmented or too long');
        }

        $maskLength = $masked ? 4 : 0;

        if (strlen($buffer) < $offset + $maskLength + $length) {
            return null;
        }

        $mask = $masked ? substr($buffer, $offset, 4) : '';
        $offset += $maskLength;

        $payload = substr($buffer, $offset, $length);
        $offset += $length;

        if ($masked) {
            $payload = self::applyMask($payload, $mask);
        }

        $buffer = substr($buffer, $offset);

        return new Frame($opcode, $payload, $fin);
    }

    /**
     * @param  bool  $mask  True when writing as a client, false as a server.
     */
    public function encode(Frame $frame, bool $mask): string
    {
        $length = strlen($frame->payload);

        $out = chr(($frame->fin ? 0x80 : 0x00) | $frame->opcode);

        $maskBit = $mask ? 0x80 : 0x00;

        if ($length <= 125) {
            $out .= chr($maskBit | $length);
        } elseif ($length <= 0xFFFF) {
            $out .= chr($maskBit | 126).pack('n', $length);
        } else {
            $out .= chr($maskBit | 127).pack('J', $length);
        }

        if (! $mask) {
            return $out.$frame->payload;
        }

        /*
         * A fresh random mask per frame, from the CSPRNG. The mask is not a
         * secret and does not need to be unguessable — it exists to stop
         * intermediaries from being tricked into interpreting frame contents —
         * but a predictable or constant mask defeats even that.
         */
        $key = random_bytes(4);

        return $out.$key.self::applyMask($frame->payload, $key);
    }

    private static function applyMask(string $payload, string $mask): string
    {
        $out = '';
        $length = strlen($payload);

        for ($i = 0; $i < $length; $i++) {
            $out .= $payload[$i] ^ $mask[$i % 4];
        }

        return $out;
    }
}
