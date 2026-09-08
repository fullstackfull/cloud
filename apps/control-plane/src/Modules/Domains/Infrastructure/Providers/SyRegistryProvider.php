<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Infrastructure\Providers;

use Lynomia\Modules\Domains\Domain\Contracts\DomainRegistrarProvider;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\DTOs\RegisteredDomain;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\DTOs\TransferStatus;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;

/**
 * The seat `.sy` will occupy, and nothing more.
 *
 * ===========================================================================
 * WHY EVERY METHOD HERE REFUSES
 * ===========================================================================
 *
 * Because the Syrian registry's technical contract is not available to this
 * project. Not the protocol, not the endpoints, not the authentication, not
 * the contact requirements, not the term limits, not the grace and redemption
 * rules. Nothing.
 *
 * The brief that authorised this phase is explicit about it, and it is right:
 * writing an EPP client here, or a REST client, or a form-posting scraper,
 * would be inventing a registry's behaviour and then testing that the
 * invention is self-consistent. The tests would pass. The first real
 * registration would fail, and every design decision downstream of the
 * invention — how a contact is shaped, what a transfer costs, when a name
 * enters redemption — would have been made from fiction.
 *
 * So this class exists to prove one thing only: that the platform can name a
 * registrar it cannot yet talk to, route a namespace to it, and refuse
 * honestly at the boundary rather than anywhere expensive. Every capability
 * answers false, so no screen offers a `.sy` action, no order can be placed
 * for one, and the search says the namespace is unsupported instead of
 * pretending a name is available.
 *
 * ===========================================================================
 * WHAT REPLACING THIS LOOKS LIKE
 * ===========================================================================
 *
 * When the licence and the documentation exist, this class gains an
 * implementation and nothing else in the platform changes: the contract, the
 * state machines, the money, the reconciliation and the screens are all
 * written against the interface. That is the entire value of building the
 * boundary now.
 *
 * Status: BLOCKED_LICENCE. It stays that way until a document from the
 * registry says otherwise.
 */
final class SyRegistryProvider implements DomainRegistrarProvider
{
    public const string NAME = 'sy_registry';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Nothing, and deliberately not "everything but the hard parts".
     *
     * A provider that claimed availability checking while refusing
     * registration would put `.sy` names in front of customers with a price
     * beside them and a buy button that throws.
     */
    public function supports(RegistrarCapability $capability): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function supportedTlds(): array
    {
        /*
         * Empty until the registry says otherwise — including the namespace
         * list itself. Whether Lynomia would operate `sy`, `com.sy`, `net.sy`
         * or some subset is part of the licence, and listing them here would
         * be the first invention.
         */
        return [];
    }

    /**
     * @param  list<string>  $names
     * @return list<never>
     */
    public function checkAvailability(array $names): array
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function register(RegistrationRequest $request): RegisteredDomain
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function renew(string $name, int $termYears): RegisteredDomain
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function inspect(string $name): RegisteredDomain
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(string $name, array $nameservers): void
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    /**
     * @param  array<string, ContactDetails>  $contacts
     */
    public function setContacts(string $name, array $contacts): void
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function setTransferLock(string $name, bool $locked): void
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function authorisationCode(string $name): string
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function startTransfer(string $name, string $authorisationCode, array $nameservers = []): TransferStatus
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function transferStatus(string $name): TransferStatus
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    public function redeem(string $name): RegisteredDomain
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }

    /**
     * @return list<never>
     */
    public function heldNames(): array
    {
        throw RegistrarNotAvailableException::forSyRegistry();
    }
}
