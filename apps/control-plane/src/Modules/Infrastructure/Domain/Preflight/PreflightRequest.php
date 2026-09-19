<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

use InvalidArgumentException;

/**
 * What was asked of a preflight.
 *
 * The mode has no default, and that is the single most important line in this
 * file. Every call site — the CLI, the Admin endpoint, every test — has to say
 * which world it is asking about, because the difference between "the software
 * works" and "the estate works" is the difference this whole phase exists to
 * keep.
 */
final readonly class PreflightRequest
{
    public function __construct(
        public PreflightMode $mode,
        public PreflightScope $scope,
        public ?string $target = null,
    ) {
        if ($scope->needsTarget() && ($target === null || trim($target) === '')) {
            throw new InvalidArgumentException(sprintf(
                'A %s preflight needs a target: it is about %s.',
                $scope->value,
                $scope->label(),
            ));
        }
    }

    public static function estate(PreflightMode $mode): self
    {
        return new self($mode, PreflightScope::Estate);
    }
}
