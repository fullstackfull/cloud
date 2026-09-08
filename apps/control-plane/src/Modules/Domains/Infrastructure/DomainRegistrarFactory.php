<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Infrastructure;

use Lynomia\Modules\Domains\Domain\Contracts\DomainRegistrarProvider;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;
use Lynomia\Modules\Domains\Domain\Exceptions\UnknownRegistrarDriverException;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Domains\Infrastructure\Providers\SyRegistryProvider;

/**
 * Builds the registrar adapter a namespace is served by.
 *
 * ---------------------------------------------------------------------------
 * Why this resolves by name and the DNS factory does not
 * ---------------------------------------------------------------------------
 *
 * Because domains route per TLD and DNS does not. A deployment has one forward
 * DNS provider; it does not have one registrar, and it never will — `.sy` will
 * be served by a Syrian authority and `.com` by a commercial reseller for as
 * long as this platform exists. So the driver comes from the TLD row rather
 * than from a single configuration key, and this factory holds one instance
 * per driver.
 *
 * That also means the routing question the plan deferred stays deferred
 * honestly: a TLD names its provider, and if a second provider ever serves the
 * same namespace, a router earns its place then. One map, no engine.
 */
final class DomainRegistrarFactory
{
    /** @var array<string, DomainRegistrarProvider> */
    private array $resolved = [];

    /**
     * The drivers this build contains.
     *
     * Read here and by the production boot guard, from one list, because two
     * lists would eventually disagree and the way anyone would find out is a
     * deployment that booted clean and failed on its first registration.
     *
     * @return list<string>
     */
    public static function drivers(): array
    {
        return [FakeDomainRegistrarProvider::NAME, SyRegistryProvider::NAME];
    }

    /**
     * @throws UnknownRegistrarDriverException
     */
    public function make(string $driver): DomainRegistrarProvider
    {
        $driver = strtolower(trim($driver));

        if (isset($this->resolved[$driver])) {
            return $this->resolved[$driver];
        }

        return $this->resolved[$driver] = match ($driver) {
            FakeDomainRegistrarProvider::NAME => new FakeDomainRegistrarProvider,
            SyRegistryProvider::NAME => new SyRegistryProvider,
            default => throw UnknownRegistrarDriverException::named($driver, self::drivers()),
        };
    }

    /**
     * The adapter serving a namespace.
     */
    public function forTld(DomainTld $tld): DomainRegistrarProvider
    {
        $provider = $this->make($tld->provider);

        /*
         * The adapter has to agree that it serves this namespace.
         *
         * A TLD row is edited by a person, and the failure this catches is a
         * quiet one: a `.com` row pointed at the wrong driver would send real
         * registrations to a registry that does not run `.com`, and the first
         * symptom would be a customer's paid order refused by a registrar
         * nobody meant to ask. Refusing here costs one array lookup and turns
         * a configuration mistake into an error at the boundary.
         *
         * A driver that names no namespaces serves whatever it is pointed at:
         * that is how a test double stands in for a registrar without having
         * to restate the catalogue.
         */
        $serves = $provider->supportedTlds();

        if ($serves !== [] && ! in_array($tld->tld, $serves, strict: true)) {
            throw RegistrarNotAvailableException::doesNotServe($provider->name(), $tld->tld);
        }

        return $provider;
    }

    /**
     * Replace an adapter for the life of the container.
     *
     * For tests that need a provider to behave in a way the fake's markers
     * cannot express — chiefly a provider that says no to a capability, which
     * is how the "the screen must not offer what the registrar cannot do"
     * paths get proven.
     */
    public function swap(DomainRegistrarProvider $provider): void
    {
        $this->resolved[$provider->name()] = $provider;
    }
}
