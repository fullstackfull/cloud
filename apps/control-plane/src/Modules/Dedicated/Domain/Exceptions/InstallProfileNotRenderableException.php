<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An OS install profile could not be turned into a configuration the installer
 * will accept.
 *
 * Rendering fails loudly rather than emitting a template with holes in it. An
 * autoinstall file containing a literal "{{ hostname }}" does not fail at the
 * point of the mistake: the installer runs, partitions the disks, and produces
 * a machine that is wrong in a way that is only discoverable after the data
 * that used to be on those disks is gone.
 */
final class InstallProfileNotRenderableException extends DomainException
{
    /**
     * @param  list<string>  $missing
     */
    public static function missingVariables(string $profileSlug, array $missing): self
    {
        sort($missing);

        $exception = new self(sprintf(
            'The install profile "%s" needs values for: %s.',
            $profileSlug,
            implode(', ', $missing),
        ));

        return $exception->withContext([
            'profile' => $profileSlug,
            'missing' => implode(', ', $missing),
        ]);
    }

    public static function inactiveProfile(string $profileSlug): self
    {
        $exception = new self(sprintf(
            'The install profile "%s" is not active and must not be used for a new install.',
            $profileSlug,
        ));

        return $exception->withContext(['profile' => $profileSlug]);
    }

    public function errorCode(): string
    {
        return 'dedicated.install_profile_not_renderable';
    }
}
