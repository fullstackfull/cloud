<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Infrastructure\WebSocket;

/**
 * One WebSocket frame, as RFC 6455 defines it.
 *
 * A value object rather than an array because three of its fields are easy to
 * confuse and each confusion is a bug that only shows up against a real peer:
 * `opcode` is not the payload's meaning, `fin` is not "this is the whole
 * message", and a masked frame's payload is not the bytes on the wire.
 *
 * @immutable
 */
final readonly class Frame
{
    public const int CONTINUATION = 0x0;

    public const int TEXT = 0x1;

    public const int BINARY = 0x2;

    public const int CLOSE = 0x8;

    public const int PING = 0x9;

    public const int PONG = 0xA;

    public function __construct(
        public int $opcode,
        public string $payload,
        public bool $fin = true,
    ) {}

    /**
     * Whether this frame carries protocol machinery rather than console bytes.
     *
     * Control frames are never fragmented and never carry more than 125 bytes,
     * which is what makes it safe for the proxy to answer a ping itself
     * without buffering.
     */
    public function isControl(): bool
    {
        return $this->opcode >= self::CLOSE;
    }
}
