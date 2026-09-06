<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A node row names a panel the running code has no adapter for.
 *
 * Reached when the panel enum gains a case ahead of its adapter, which is how
 * a node row comes to name something this deployment cannot drive.
 */
final class UnknownHostingPanelException extends DomainException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $panel, array $known): self
    {
        $exception = new self(sprintf(
            'No hosting adapter is registered for the panel "%s"; this build knows %s.',
            $panel,
            implode(', ', $known),
        ));

        return $exception->withContext([
            'panel' => $panel,
            'known_panels' => implode(', ', $known),
        ]);
    }

    public function errorCode(): string
    {
        return 'hosting.unknown_panel';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
