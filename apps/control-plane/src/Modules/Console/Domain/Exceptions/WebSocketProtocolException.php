<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Domain\Exceptions;

use RuntimeException;

/**
 * The peer is not speaking WebSocket.
 *
 * Always fatal to the connection rather than something to recover from: RFC
 * 6455 says to fail the connection on a protocol error, and a proxy that
 * carried on after one would be guessing at frame boundaries between a browser
 * and a root console.
 */
final class WebSocketProtocolException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self(sprintf('The WebSocket peer broke the protocol: %s.', $reason));
    }
}
