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

    public static function becauseThePanelCannotCopy(string $panel): self
    {
        return (new self('This site is on a panel whose toolkit cannot copy WordPress sites. Nothing else about the site is affected.'))
            ->withContext(['panel' => $panel])
            ->as('wordpress.panel_cannot_copy')
            ->status(409);
    }

    public static function becauseTheSiteIsNotReady(string $domain, string $state): self
    {
        return (new self(sprintf('Only a finished, verified site can be copied; this one is %s.', str_replace('_', ' ', $state))))
            ->withContext(['domain' => $domain, 'state' => $state])
            ->as('wordpress.site_not_ready');
    }

    public static function becauseAnOperationIsInFlight(string $domain): self
    {
        return (new self('A copy or a push involving this site is already running. Wait for it to finish, or for our team to settle one that did not answer.'))
            ->withContext(['domain' => $domain])
            ->as('wordpress.operation_in_flight')
            ->status(409);
    }

    public static function becauseOnlyAStagingCopyCanBePushed(string $domain): self
    {
        return (new self('Only a staging copy can be pushed to production.'))
            ->withContext(['domain' => $domain])
            ->as('wordpress.not_a_staging_copy');
    }

    public static function becauseTheProductionSiteIsGone(string $domain): self
    {
        return (new self('The production site this copy was made from no longer exists on the platform.'))
            ->withContext(['domain' => $domain])
            ->as('wordpress.production_gone')
            ->status(409);
    }

    public static function becauseTheConfirmationDoesNotMatch(): self
    {
        return (new self('Type the production site\'s domain exactly to confirm. A push overwrites it.'))
            ->as('wordpress.push_confirmation_mismatch');
    }

    public static function becauseAStagingCopyAlreadyExists(string $domain): self
    {
        return (new self('This site already has a staging copy. Push it, or ask our team to remove it, before making another.'))
            ->withContext(['domain' => $domain])
            ->as('wordpress.staging_exists')
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
