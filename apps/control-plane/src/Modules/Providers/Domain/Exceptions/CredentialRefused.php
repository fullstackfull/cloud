<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use RuntimeException;

/**
 * Something about a credential reference was refused.
 *
 * None of these messages contains a secret, a reference into the secret store,
 * or anything a caller did not already supply. They are shown to operators
 * over HTTP.
 */
final class CredentialRefused extends RuntimeException
{
    public static function unknownBackend(string $backend): self
    {
        return new self(sprintf(
            'There is no %s secret backend. Credentials are references into the deployment '
            .'controller\'s environment; a second backend is a deliberate addition, not a string.',
            $backend,
        ));
    }

    public static function referenceLooksLikeAValue(): self
    {
        return new self(
            'That is not the shape of a reference. A reference is the NAME of a variable on the '
            .'deployment controller — upper-case letters, digits and underscores — never the value. '
            .'If a secret was pasted here, rotate it now: it has been in a request body.',
        );
    }

    public static function revoked(string $credential): self
    {
        return new self(sprintf(
            '%s has been revoked and cannot be attached to anything. '
            .'A revoked credential is kept so that what pointed at it can say why it is blocked; '
            .'record a new one instead.',
            $credential,
        ));
    }

    public static function wrongEnvironment(string $credential, DeploymentEnvironment $has, DeploymentEnvironment $needs): self
    {
        return new self(sprintf(
            '%s is a %s credential and this is %s. Environments are kept apart at the point of '
            .'attachment, not only at the point of use, so a staging token cannot sit on a '
            .'production provider waiting for the day somebody relaxes the check.',
            $credential,
            $has->value,
            $needs->value,
        ));
    }

    public static function withoutAReason(string $credential): self
    {
        return new self(sprintf(
            'Revoking %s needs a reason. It blocks every provider and machine using it, and the '
            .'first question afterwards is whether that was deliberate.',
            $credential,
        ));
    }

    public static function alreadyRevoked(string $credential): self
    {
        return new self(sprintf('%s is already revoked. A revocation is not repeated; record a new credential.', $credential));
    }
}
