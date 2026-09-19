<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\DTOs;

/**
 * A line the parser would not take, or would not act on, and the reason in
 * words a person can fix. The line's text is echoed back truncated, so a
 * refusal about line 40 shows line 40 rather than sending somebody counting.
 */
final readonly class ParsedProblem
{
    public function __construct(
        public int $line,
        public string $text,
        public string $reason,
    ) {}

    public static function at(int $line, string $text, string $reason): self
    {
        return new self($line, mb_strimwidth($text, 0, 120, '…'), $reason);
    }
}
