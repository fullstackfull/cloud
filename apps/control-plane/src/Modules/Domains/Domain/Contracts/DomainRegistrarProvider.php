<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Contracts;

use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Domains\Domain\DTOs\AvailabilityAnswer;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\DTOs\RegisteredDomain;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\DTOs\TransferStatus;
use Lynomia\Modules\Domains\Domain\Enums\DomainAvailability;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Ipam\Domain\Contracts\ReverseDnsProvider;

/**
 * Whatever holds names on this platform's behalf.
 *
 * ---------------------------------------------------------------------------
 * A third authority, not a variation on the other two
 * ---------------------------------------------------------------------------
 *
 * The platform already has {@see DnsProvider}
 * and {@see ReverseDnsProvider}, and
 * this is deliberately a third interface rather than an extension of either.
 * Holding a name, serving its forward DNS and controlling the reverse
 * delegation for the addresses it resolves to are three different authorities
 * held by three different parties, and the ordinary case proves it: a customer
 * can register `example.com` through this platform and serve its DNS at
 * Cloudflare, or register it at another registrar and serve its DNS here.
 * Either would be impossible if one interface covered both.
 *
 * ---------------------------------------------------------------------------
 * Capability-driven
 * ---------------------------------------------------------------------------
 *
 * Every method below except the first three may be unsupported. Callers ask
 * {@see self::supports()} first and offer the customer what the answer allows;
 * an implementation that cannot do something throws rather than pretending,
 * and the screen never shows a button that was going to fail.
 *
 * ---------------------------------------------------------------------------
 * The rules every implementation is bound by
 * ---------------------------------------------------------------------------
 *
 *  - **A timeout is indeterminate, never a failure.** This is the money rule.
 *    A registrar that took a registration and lost the response has taken a
 *    year of somebody's money, and an adapter reporting that as failed invites
 *    the platform to buy it again. Throw
 *    {@see DomainRegistrarException::indeterminate()} and let reconciliation
 *    settle it.
 *
 *  - **A refusal is a refusal.** "This name is taken" is final, and saying so
 *    is what lets the platform refund promptly instead of leaving money in
 *    limbo.
 *
 *  - **No credential in a message or a context.** A reseller API key is
 *    authority over every name this platform holds.
 *
 *  - **Reads never mutate.** {@see self::inspect()} and
 *    {@see self::checkAvailability()} run on schedules across the whole
 *    portfolio; a side effect in either would be a portfolio-wide side effect.
 *
 *  - **No contact detail in an exception.** These calls carry a real person's
 *    home address; an adapter that puts the request body in the error message
 *    puts it in the log.
 */
interface DomainRegistrarProvider
{
    /**
     * The driver name, as configuration spells it. Persisted on every domain
     * and operation row, so it must not change once rows exist.
     */
    public function name(): string;

    /**
     * Whether this provider can be asked to do something.
     */
    public function supports(RegistrarCapability $capability): bool;

    /**
     * The namespaces this provider serves, without leading dots.
     *
     * Used to route a TLD to a provider and to refuse early: a customer
     * searching for a namespace nobody here serves should be told so by the
     * search rather than by a registrar at checkout.
     *
     * @return list<string>
     */
    public function supportedTlds(): array;

    /**
     * Can these names be bought.
     *
     * Answers one entry per name asked about, in any order. A name the
     * provider could not answer for comes back
     * {@see DomainAvailability::Unknown}
     * rather than being omitted — a caller cannot tell an omission from a
     * dropped response, and guessing which is how an available name is shown
     * as taken.
     *
     * @param  list<string>  $names
     * @return list<AvailabilityAnswer>
     *
     * @throws DomainRegistrarException
     */
    public function checkAvailability(array $names): array;

    /**
     * Take a name.
     *
     * @throws DomainRegistrarException on refusal, and with
     *                                  {@see DomainRegistrarException::isIndeterminate()}
     *                                  when the outcome is unknown
     */
    public function register(RegistrationRequest $request): RegisteredDomain;

    /**
     * Extend a name's term.
     *
     * The returned expiry is the registry's own. A renewal that answers with
     * the date the platform already held is a renewal that did not happen, and
     * the caller checks for exactly that rather than trusting the acceptance.
     *
     * @throws DomainRegistrarException
     */
    public function renew(string $name, int $termYears): RegisteredDomain;

    /**
     * What the registry believes about a name this platform holds.
     *
     * The read reconciliation is built on, and the read a renewal is verified
     * against.
     *
     * @throws DomainRegistrarException
     */
    public function inspect(string $name): RegisteredDomain;

    /**
     * Change the delegation.
     *
     * @param  list<string>  $nameservers
     *
     * @throws DomainRegistrarException
     */
    public function setNameservers(string $name, array $nameservers): void;

    /**
     * Change the contact set filed with the registry.
     *
     * @param  array<string, ContactDetails>  $contacts  Keyed by role value.
     *
     * @throws DomainRegistrarException
     */
    public function setContacts(string $name, array $contacts): void;

    /**
     * Set the registry's transfer lock.
     *
     * @throws DomainRegistrarException
     */
    public function setTransferLock(string $name, bool $locked): void;

    /**
     * The code that authorises moving this name to another registrar.
     *
     * A credential. The caller shows it to the holder and to nobody else, and
     * it is never logged, never sent in a notification and never written into
     * an audit context — the audit records that somebody asked for it.
     *
     * @throws DomainRegistrarException
     */
    public function authorisationCode(string $name): string;

    /**
     * Start bringing a name in from another registrar.
     *
     * @param  list<string>  $nameservers
     *
     * @throws DomainRegistrarException
     */
    public function startTransfer(string $name, string $authorisationCode, array $nameservers = []): TransferStatus;

    /**
     * Where a transfer has got to.
     *
     * Polled, because a transfer takes days and no registrar calls back.
     *
     * @throws DomainRegistrarException
     */
    public function transferStatus(string $name): TransferStatus;

    /**
     * Recover a name from the registry's redemption period.
     *
     * Its own method rather than a flag on renew, because it is a different
     * operation at a different price and a registry that was sent a renewal
     * for a redeemed name refuses it.
     *
     * @throws DomainRegistrarException
     */
    public function redeem(string $name): RegisteredDomain;

    /**
     * Every name this provider holds for the platform's account.
     *
     * What finds a name the platform does not know it owns — the other half of
     * an indeterminate registration, and the only way that case is ever
     * resolved without a person reading a registrar's control panel.
     *
     * @return list<string>
     *
     * @throws DomainRegistrarException
     */
    public function heldNames(): array;
}
