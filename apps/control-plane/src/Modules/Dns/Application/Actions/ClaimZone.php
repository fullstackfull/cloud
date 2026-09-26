<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Dns\Application\Jobs\PublishZone;
use Lynomia\Modules\Dns\Application\Services\ConfiguredReservedZones;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DomainName;
use Lynomia\Modules\Dns\Domain\ValueObjects\ReservedZones;
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
        private ConfiguredReservedZones $reserved,
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
     * The platform's own names, every parent of one and everything beneath one.
     *
     * What is reserved, and why all three directions, is written once, on
     * {@see ReservedZones}; this is where it is enforced. The list is the one
     * the estate preflight reports on, read from the same place, so an
     * operator who is told what is held is told what this refuses.
     *
     * A list with an entry that is not a name holds everything: the claim is
     * refused whatever it names, because the guard cannot tell what the
     * operator meant to hold (I-3). The claimant's own name has already been
     * read by then, so a name they did mistype is still answered as theirs.
     * The refusal is the platform's condition — `dns.zone.unavailable`, 503,
     * disclosing nothing — and the operator is told here, at error level,
     * with counts and never the entries, and by the preflight's failure.
     *
     * In production, a list that reads but holds too little is refused the
     * same way: nothing reserved at all, or a host the platform answers on
     * whose names beside it any account could claim
     * ({@see ReservedZones::holdsTooLittle()} — exactly the states the estate
     * preflight blocks a production estate on). The preflight used to be the
     * only thing that knew, and nothing consumed its verdict: a production
     * estate on the shipped empty list took `www.` and `mail.` beside its own
     * control plane from any account (F-26). The log line names the
     * variables the hosts came from and counts the entries; it never names a
     * host or an entry. Outside production nothing changes — a rehearsal is
     * warned by the preflight, not stopped.
     *
     * @throws DnsRefusedException
     */
    private function assertNotReserved(DomainName $domain): void
    {
        $reserved = $this->reserved->read();
        $malformed = $reserved->malformed();

        if ($malformed > 0) {
            Log::error('A zone claim was refused because DNS_RESERVED_ZONES holds an entry that is not a domain name; every claim by every account is refused until it is corrected.', [
                'malformed_entries' => $malformed,
                'entries' => count($reserved->configured()),
                'preflight_finding' => 'dns.reserved_zones',
            ]);

            throw DnsRefusedException::reservationUnreadable();
        }

        if (app()->isProduction() && $reserved->holdsTooLittle()) {
            Log::error('A zone claim was refused because this production estate reserves too little of its own names (DNS_RESERVED_ZONES is empty, or a host the platform answers on has claimable names beside it); every claim by every account is refused until it is corrected.', [
                'entries' => count($reserved->configured()),
                'derived' => array_keys($reserved->derived()),
                'preflight_finding' => 'dns.reserved_zones',
            ]);

            throw DnsRefusedException::reservationIncomplete();
        }

        if ($reserved->protects($domain)) {
            throw DnsRefusedException::zoneIsReserved($domain->value());
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
