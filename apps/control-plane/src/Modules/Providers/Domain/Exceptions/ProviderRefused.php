<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Exceptions;

use Lynomia\Modules\Providers\Domain\DTOs\ReadinessVerdict;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use RuntimeException;

/**
 * Something about a provider instance was refused.
 *
 * Every constructor here names what would have gone wrong rather than which
 * rule fired. A message that says "validation failed" sends an operator to the
 * code; a message that says two enabled registrars means two answers to "where
 * does this registration go" sends them to the decision.
 */
final class ProviderRefused extends RuntimeException
{
    /**
     * @param  list<string>  $known
     */
    public static function unknownDriver(string $driver, array $known): self
    {
        return new self(sprintf(
            'There is no %s adapter. Lynomia can talk to: %s. '
            .'A driver name that is merely stored is a provider nothing can ever reach.',
            $driver,
            implode(', ', $known),
        ));
    }

    public static function categoryMismatch(string $driver, ProviderCategory $declared, ProviderCategory $actual): self
    {
        return new self(sprintf(
            'The %s adapter is a %s provider, not a %s one. '
            .'The category is what the rest of the platform depends on, so it cannot be whatever the form said.',
            $driver,
            $actual->value,
            $declared->value,
        ));
    }

    public static function controlledDriverInProduction(string $driver): self
    {
        return new self(sprintf(
            'The %s driver simulates a provider and must never be registered in production. '
            .'A simulated payment gateway that reports success takes real orders and collects nothing.',
            $driver,
        ));
    }

    public static function needsAServer(string $driver): self
    {
        return new self(sprintf(
            'A %s provider runs on a machine we manage, so one must be named. '
            .'Registering it against no machine would make it permanently unreachable and permanently unexplained.',
            $driver,
        ));
    }

    public static function serverIsElsewhere(string $server, DeploymentEnvironment $serverEnvironment, DeploymentEnvironment $providerEnvironment): self
    {
        return new self(sprintf(
            '%s is a %s machine and this provider is %s. '
            .'Environments are kept apart so that a staging mistake cannot reach a customer.',
            $server,
            $serverEnvironment->value,
            $providerEnvironment->value,
        ));
    }

    public static function notReady(string $provider, ReadinessVerdict $verdict): self
    {
        return new self(sprintf(
            '%s cannot be enabled: %s',
            $provider,
            $verdict->detail,
        ));
    }

    public static function anotherIsEnabled(ProviderCategory $category, DeploymentEnvironment $environment): self
    {
        return new self(sprintf(
            'Another %s provider is already enabled in %s. '
            .'Two would be two answers to the same question, and the platform would pick one of them arbitrarily. '
            .'Disable the current one first, deliberately.',
            $category->value,
            $environment->value,
        ));
    }

    public static function withoutAReason(string $provider): self
    {
        return new self(sprintf(
            'Switching %s off needs a reason. It stops new work reaching this provider, and the next '
            .'person to look at a queue that is not draining will want to know whether that was on purpose.',
            $provider,
        ));
    }
}
