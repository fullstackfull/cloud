<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dns\Application\Jobs\PublishZone;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DomainName;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * An account says it holds a domain, and the platform starts serving it.
 *
 * ---------------------------------------------------------------------------
 * What this does not do
 * ---------------------------------------------------------------------------
 *
 * **It does not verify that the claimant owns the domain, and it does not
 * pretend to.** There is no way for a control plane to establish ownership on
 * its own: a registry lookup names a registrant this platform cannot
 * authenticate, and a token written into a zone the claimant does not yet
 * serve is a token nobody can publish. What settles it is delegation — a zone
 * here serves nothing at all until the registrar points the domain's
 * nameservers at this provider, and only whoever controls the registration can
 * do that.
 *
 * So a claim is not a grant of authority. It is a request to be ready, and the
 * proof arrives later, from the registrar, in the only currency DNS accepts.
 * Anything on this surface that read as verification would be a lie, which is
 * why nothing here says "verified" and docs/dns.md says so at length.
 *
 * The two things a claim *does* take away from everybody else are the zone
 * name on this platform, and nothing more. That is enforced by a unique index
 * rather than by this check, which exists only so the customer gets a sentence
 * instead of a constraint violation.
 */
final readonly class ClaimZone
{
    public function __construct(
        private DnsProviderFactory $providers,
    ) {}

    /**
     * @throws DnsRefusedException
     * @throws InvalidDomainNameException
     */
    public function execute(Customer $customer, string $name, ?User $actor = null, ?string $serviceId = null): DnsZone
    {
        $domain = DomainName::fromString($name);

        $this->assertNotReserved($domain);
        $this->assertNotReverse($domain);

        $provider = $this->providers->make();

        if (! $provider->canCreateZones()) {
            throw DnsRefusedException::providerCannotCreateZones();
        }

        return DB::transaction(function () use ($customer, $domain, $actor, $serviceId, $provider): DnsZone {
            $ceiling = max(1, (int) config('dns.zones_per_customer', 50));

            $held = DnsZone::query()
                ->where('customer_id', $customer->getKey())
                ->where('state', '!=', DnsState::Deleted->value)
                ->count();

            if ($held >= $ceiling) {
                throw DnsRefusedException::tooManyZones($ceiling);
            }

            /*
             * Read inside the transaction and immediately before the insert.
             * The unique index is what actually guarantees this; the query is
             * here so that the ordinary case — somebody else already holds it —
             * is answered with a sentence rather than a 500.
             */
            $taken = DnsZone::query()
                ->where('name', $domain->value())
                ->where('state', '!=', DnsState::Deleted->value)
                ->exists();

            if ($taken) {
                throw DnsRefusedException::zoneAlreadyClaimed($domain->value());
            }

            $zone = new DnsZone;
            $zone->forceFill([
                'customer_id' => $customer->getKey(),
                'service_id' => $serviceId,
                'name' => $domain->value(),
                'state' => DnsState::Pending,
                'provider' => $provider->name(),
                'created_by_user_id' => $actor?->getKey(),
            ])->save();

            /*
             * After the commit, not inside it. A job that runs before the row
             * is visible finds nothing and returns, and the zone sits pending
             * for ever with no error anywhere.
             */
            DB::afterCommit(static function () use ($zone): void {
                PublishZone::dispatch((string) $zone->getKey());
            });

            return $zone;
        });
    }

    /**
     * @throws DnsRefusedException
     */
    private function assertNotReserved(DomainName $domain): void
    {
        /** @var list<string> $reserved */
        $reserved = (array) config('dns.reserved_zones', []);

        foreach ($reserved as $name) {
            $held = DomainName::fromString($name);

            /*
             * Both directions. Claiming the platform's own zone is the obvious
             * attack; claiming a *parent* of it is the same attack one step
             * out, and it is the one that gets missed — an account holding
             * `example.com` can serve `panel.example.com` whatever the platform
             * thinks it owns.
             */
            if ($domain->equals($held) || $held->isWithin($domain)) {
                throw DnsRefusedException::zoneIsReserved($domain->value());
            }
        }
    }

    /**
     * @throws DnsRefusedException
     */
    private function assertNotReverse(DomainName $domain): void
    {
        foreach (['in-addr.arpa', 'ip6.arpa'] as $suffix) {
            if ($domain->isWithin(DomainName::fromString($suffix))) {
                throw DnsRefusedException::zoneIsReverse($domain->value());
            }
        }
    }
}
