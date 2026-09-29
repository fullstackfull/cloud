<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A hosting build, or a correction to one, over a name the platform will not
 * serve twice.
 *
 * Two shapes, and both are refused before the panel is asked for anything:
 *
 *  - `hosting.domain_in_use` — another live account (one the panel still
 *    holds: pending, active or suspended) already serves this name. DNS can
 *    point at one of them, so building a second is building an account that
 *    cannot work, for a customer who has paid for it. The partial unique index
 *    on `hosting_accounts.primary_domain` is the guarantee; this is the
 *    readable refusal in front of it, and the one a concurrent loser is
 *    translated into.
 *
 *  - `hosting.account_serves_another_domain` — this job's own earlier attempt
 *    left a row the panel may hold, under a different name from the one the
 *    job now asks for. A pending row exists from the moment the slot is taken,
 *    before the panel is called, so a worker that died either side of the
 *    create leaves an identical row; nothing on it says which. Carrying on
 *    would either hand the panel the row's old name on a job reporting
 *    success, or ask the panel to create a username it already holds and
 *    retry that for ever. The way out is to name the job back to the row's
 *    own name. The other way out — establishing through the panel's own
 *    account list that it never held the row, and only then releasing it — is
 *    a new operator capability and is deliberately not built here.
 *
 * Neither carries a provider reference and neither takes a slot, so the
 * operator's remedy — correct the name on the job, then retry it — is not
 * refused by the retry guard that protects builds which reached a provider.
 *
 * The context names the domain the request asked for and nothing about the
 * account that holds it: which customer serves a name is not the requester's
 * business.
 */
final class HostingDomainConflictException extends DomainException
{
    private string $errorCode = 'hosting.domain_in_use';

    public static function forDomain(string $domain): self
    {
        $exception = new self(sprintf('The domain "%s" is already served by a live hosting account.', $domain));

        return $exception->withContext(['primary_domain' => $domain]);
    }

    public static function becauseTheAccountServesAnotherName(string $requested, string $served, string $username): self
    {
        $exception = new self(sprintf(
            'The account "%s" left by an earlier attempt serves "%s", not "%s". The panel may already hold it '
            .'under that name, so it is not built under another; name the job back to "%s" to carry on.',
            $username,
            $served,
            $requested,
            $served,
        ));
        $exception->errorCode = 'hosting.account_serves_another_domain';

        return $exception->withContext([
            'primary_domain' => $requested,
            'account_primary_domain' => $served,
            'username' => $username,
        ]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * A conflict over a name the request chose, not a fault of the platform.
     */
    public function httpStatus(): int
    {
        return 409;
    }
}
