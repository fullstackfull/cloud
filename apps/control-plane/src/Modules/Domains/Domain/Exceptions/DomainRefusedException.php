<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Exceptions;

use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Something the platform will not do with a name, and why.
 *
 * Every one of these is a sentence a customer can act on — the namespace is
 * not sold here, the term is longer than the registry allows, the quote has
 * expired, the domain is locked. None of them is a bug, and none of them is a
 * registrar's refusal; that is {@see DomainRegistrarException}.
 */
final class DomainRefusedException extends DomainException
{
    /** Never `$code`: Exception already declares an untyped one, and typing it is fatal at class load. */
    private string $errorCode = 'domain.refused';

    private int $status = 422;

    public static function becauseTheTldIsNotSold(string $tld): self
    {
        return (new self(sprintf('This platform does not sell .%s domains.', $tld)))
            ->as('domain.tld_not_sold')
            ->withContext(['tld' => $tld]);
    }

    public static function becauseTheOperationIsNotOffered(string $tld, DomainOperationKind $kind): self
    {
        return (new self(sprintf('.%s domains cannot be %sed here.', $tld, $kind->value)))
            ->as('domain.operation_not_offered')
            ->withContext(['tld' => $tld, 'operation' => $kind->value]);
    }

    public static function becauseTheTermIsNotPermitted(string $tld, int $years): self
    {
        return (new self(sprintf('.%s domains cannot be registered for %d years.', $tld, $years)))
            ->as('domain.term_not_permitted')
            ->withContext(['tld' => $tld, 'term_years' => $years]);
    }

    /**
     * The price the customer was shown is no longer one the platform stands
     * behind.
     *
     * A 409 rather than a 422: nothing about the request is malformed, and the
     * client's correct response is to search again rather than to edit a
     * field.
     */
    public static function becauseTheQuoteHasExpired(string $quoteId): self
    {
        return (new self('That price is no longer current. Search again for today\'s price.'))
            ->as('domain.quote_expired')
            ->status(409)
            ->withContext(['quote_id' => $quoteId]);
    }

    public static function becauseTheQuoteIsSpent(string $quoteId): self
    {
        return (new self('That price has already been used for an order.'))
            ->as('domain.quote_spent')
            ->status(409)
            ->withContext(['quote_id' => $quoteId]);
    }

    /**
     * Somebody else bought the name between the search and the checkout.
     *
     * Not a system failure, and it must never be reported as one: it is the
     * ordinary outcome of a namespace where anybody may buy anything at any
     * moment, and the honest answer is that the name is gone.
     */
    public static function becauseItIsNoLongerAvailable(string $name): self
    {
        return (new self(sprintf('%s was registered by somebody else while you were buying it.', $name)))
            ->as('domain.no_longer_available')
            ->status(409)
            ->withContext(['name' => $name]);
    }

    public static function becauseTheStateForbidsIt(string $name, DomainState $state): self
    {
        return (new self(sprintf('%s cannot be changed while it is %s.', $name, $state->value)))
            ->as('domain.state_forbids_it')
            ->status(409)
            ->withContext(['name' => $name, 'state' => $state->value]);
    }

    public static function becauseItIsLocked(string $name): self
    {
        return (new self(sprintf('%s is locked against transfer. Unlock it first.', $name)))
            ->as('domain.transfer_locked')
            ->status(409)
            ->withContext(['name' => $name]);
    }

    /**
     * A registration filed without somebody to file it against.
     *
     * Every registry records a registrant, and one submitted without it comes
     * back refused — after the customer has paid, and after the platform has
     * spent an idempotency key on it. Refused here instead.
     */
    public static function becauseAContactIsMissing(string $role): self
    {
        return (new self('This registration needs contact details before it can be filed.'))
            ->withContext(['role' => $role])
            ->as('domain.contact_missing')
            ->status(422);
    }

    /**
     * A delegation no registry would accept.
     *
     * Two nameservers is the floor every registry enforces and thirteen the
     * ceiling. Checked here so the customer reads a sentence instead of a
     * registry status code, and so a one-nameserver delegation — which
     * resolves right up until that host reboots — is refused rather than
     * filed.
     */
    public static function becauseTheNameserversAreNotUsable(string $name, int $given): self
    {
        return (new self('A domain needs between two and thirteen nameservers.'))
            ->withContext(['domain' => $name, 'given' => $given])
            ->as('domain.nameservers_unusable')
            ->status(422);
    }

    public static function becauseTheProviderCannot(string $capability): self
    {
        return (new self('The registrar holding this domain does not offer that.'))
            ->as('domain.capability_unsupported')
            ->status(409)
            ->withContext(['capability' => $capability]);
    }

    public static function becauseAnOperationIsAlreadyRunning(string $name): self
    {
        return (new self(sprintf('Something is already being done to %s. Wait for it to finish.', $name)))
            ->as('domain.operation_in_flight')
            ->status(409)
            ->withContext(['name' => $name]);
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
