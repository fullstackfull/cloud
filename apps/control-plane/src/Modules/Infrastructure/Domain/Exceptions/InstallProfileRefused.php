<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An OS install profile an operator tried to record that no build could
 * install from.
 *
 * An operator code with no entry in the customer catalogue: only the operator
 * route raises it, so the operator is answered with this sentence, which names
 * the placeholders nothing supplies.
 */
final class InstallProfileRefused extends DomainException
{
    private string $refusal = 'infrastructure.install_profile_incomplete';

    /**
     * @param  list<string>  $keys
     */
    public static function becauseItDefaultsWhatThePlatformOwns(string $slug, array $keys): self
    {
        sort($keys);

        $exception = new self(sprintf(
            'The profile "%s" gives a default for %s, which only the platform supplies: a default would be '
            .'installed onto a machine whenever the platform had no value, an address or a name nobody '
            .'allocated. Remove the default.',
            $slug,
            implode(', ', $keys),
        ));

        $exception->refusal = 'infrastructure.install_profile_default_not_allowed';

        return $exception->withContext(['slug' => $slug, 'keys' => implode(', ', $keys)]);
    }

    /**
     * @param  list<string>  $uncovered
     * @param  list<string>  $supplied
     */
    public static function becauseNothingSupplies(string $slug, array $uncovered, array $supplied): self
    {
        sort($uncovered);

        $exception = new self(sprintf(
            'The template for "%s" asks for %s, and neither a build nor the profile\'s defaults supply it. '
            .'A build supplies %s; give the rest a default.',
            $slug,
            implode(', ', $uncovered),
            implode(', ', $supplied),
        ));

        return $exception->withContext([
            'slug' => $slug,
            'uncovered' => implode(', ', $uncovered),
        ]);
    }

    public function errorCode(): string
    {
        return $this->refusal;
    }
}
