<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An input the parser will not read at all — as opposed to a line it read
 * and refused, which is reported inside the plan. These are the bounds:
 * size, shape and encoding. They answer 422 with a code a client can act on.
 */
final class ZoneFileRefusedException extends DomainException
{
    private string $errorCode = 'dns.zone_file.refused';

    public static function tooLarge(int $maxBytes, ?int $maxLines = null): self
    {
        $exception = new self(sprintf(
            'The zone file is too large. At most %d KiB%s.',
            intdiv($maxBytes, 1024),
            $maxLines === null ? '' : sprintf(' and %d lines', $maxLines),
        ));
        $exception->errorCode = 'dns.zone_file.too_large';

        return $exception;
    }

    public static function lineTooLong(int $line, int $max): self
    {
        $exception = new self(sprintf('Line %d is longer than %d characters.', $line, $max));
        $exception->errorCode = 'dns.zone_file.line_too_long';

        return $exception->withContext(['line' => $line]);
    }

    public static function notText(string $why): self
    {
        $exception = new self(sprintf('The zone file could not be read: %s.', $why));
        $exception->errorCode = 'dns.zone_file.not_text';

        return $exception;
    }

    public static function planChanged(): self
    {
        $exception = new self('The zone changed since this plan was previewed. Preview it again before applying.');
        $exception->errorCode = 'dns.import.plan_changed';

        return $exception;
    }

    public static function planNotApplicable(int $refused): self
    {
        $exception = new self(sprintf('The plan has %d refused line(s) and nothing is applied until every one is corrected.', $refused));
        $exception->errorCode = 'dns.import.refused';

        return $exception->withContext(['refused' => $refused]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            'dns.import.plan_changed', 'dns.import.refused' => 409,
            default => 422,
        };
    }
}
