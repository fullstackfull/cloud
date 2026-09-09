<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A WordPress site the platform will not build, and why.
 *
 * Every refusal here is one a customer can act on: use a different name, wait
 * for a registration to finish, or point the one they have. None of them are
 * internal errors dressed up as advice.
 */
final class WordPressRefusedException extends DomainException
{
    private string $errorCode = 'wordpress.refused';

    private int $status = 422;

    public static function becauseTheDomainIsUnusable(string $domain): self
    {
        return (new self('That does not look like a domain name.'))
            ->withContext(['domain' => $domain])
            ->as('wordpress.domain_unusable');
    }

    public static function becauseTheDomainIsAlreadyUsed(string $domain): self
    {
        return (new self('There is already a site on this platform using that name.'))
            ->withContext(['domain' => $domain])
            ->as('wordpress.domain_in_use')
            ->status(409);
    }

    public static function becauseTheDomainIsNotHeldHere(string $domain): self
    {
        return (new self(
            'This account does not hold that domain here yet. '
            .'If you have just ordered it, wait for the registration to finish; '
            .'if it is registered elsewhere, choose the external option.',
        ))
            ->withContext(['domain' => $domain])
            ->as('wordpress.domain_not_held')
            ->status(409);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }

    private function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }
}
