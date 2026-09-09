<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use RuntimeException;

/**
 * Something about a licence was refused.
 */
final class LicenceRefused extends RuntimeException
{
    public static function wrongEnvironment(string $what, DeploymentEnvironment $has, DeploymentEnvironment $needs): self
    {
        return new self(sprintf(
            '%s is %s and this licence is %s. A licence bought for one environment does not cover another, '
            .'and a cPanel licence that turns out to belong to staging is found out on the day production stops serving.',
            $what,
            $has->value,
            $needs->value,
        ));
    }

    public static function invalid(string $product): self
    {
        return new self(sprintf(
            'The %s licence has been marked invalid by its vendor and cannot be attached. '
            .'Record the renewal first; a licence that failed at the vendor does not become good by being pointed at something.',
            $product,
        ));
    }

    public static function withoutAReason(string $product): self
    {
        return new self(sprintf(
            'Marking the %s licence invalid needs a reason. It blocks every provider using it, and the reason is '
            .'what the next person reads beside that blocker.',
            $product,
        ));
    }

    public static function renewalInThePast(string $product): self
    {
        return new self(sprintf(
            'A renewal of the %s licence has to expire in the future. A renewal that is already expired is a '
            .'record of the old term, and the old term is already on the row.',
            $product,
        ));
    }

    public static function expiryBeforeStart(string $product): self
    {
        return new self(sprintf('The %s licence would expire before it starts.', $product));
    }
}
