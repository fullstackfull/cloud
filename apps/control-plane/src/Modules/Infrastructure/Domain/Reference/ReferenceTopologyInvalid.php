<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Reference;

use RuntimeException;

/**
 * The reference topology does not describe a coherent estate.
 *
 * Carries every violation rather than the first, because a topology with a
 * renamed node has one cause and several symptoms, and an author fixing them
 * one build at a time learns nothing about the shape of the mistake.
 */
final class ReferenceTopologyInvalid extends RuntimeException
{
    /**
     * @param  list<string>  $violations
     */
    private function __construct(public readonly array $violations, string $message)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $violations
     */
    public static function with(array $violations): self
    {
        return new self($violations, sprintf(
            "The reference topology is not valid (%d %s):\n  - %s",
            count($violations),
            count($violations) === 1 ? 'problem' : 'problems',
            implode("\n  - ", $violations),
        ));
    }
}
