<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Illuminate\Support\Facades\Cache;
use Lynomia\Modules\Domains\Domain\DTOs\AvailabilityAnswer;
use Lynomia\Modules\Domains\Domain\Enums\DomainAvailability;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Domain\Exceptions\InvalidDomainException;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;
use Lynomia\Modules\Domains\Domain\Exceptions\UnknownRegistrarDriverException;
use Lynomia\Modules\Domains\Domain\ValueObjects\RegistrableDomain;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;

/**
 * What a customer typed, and what it would cost, per namespace.
 *
 * ---------------------------------------------------------------------------
 * The answers that are not yes or no
 * ---------------------------------------------------------------------------
 *
 * A registrar that times out has said nothing, and this action passes that
 * through as `Unknown` rather than resolving it. Reporting an unanswered
 * lookup as available invites a customer to buy a name that is taken and get
 * a refusal after their money moved; reporting it as unavailable tells them a
 * name they could have had is gone. The screen says the search did not answer.
 *
 * ---------------------------------------------------------------------------
 * Caching, and what is deliberately not cached
 * ---------------------------------------------------------------------------
 *
 * Availability is cached by name for a minute, because it is a fact about the
 * world rather than about the person asking — two customers searching the same
 * name should cost one provider call, and registrars rate-limit hard.
 *
 * What is never stored is who searched for what. A per-customer cache key
 * would be a per-customer search history in the cache, readable by anything
 * that can enumerate keys, for no gain: the answer is the same either way.
 *
 * Prices are not cached at all. They come from the catalogue on every request,
 * and the authoritative one is the quote row this action's caller writes.
 */
final readonly class SearchDomains
{
    public function __construct(
        private DomainRegistrarFactory $registrars,
    ) {}

    /**
     * @param  list<string>  $alsoTry  Extra namespaces to offer, without dots.
     * @return list<array{domain: RegistrableDomain, tld: DomainTld, answer: AvailabilityAnswer}>
     *
     * @throws InvalidDomainException when the typed name cannot be parsed at all
     */
    public function execute(string $typed, array $alsoTry = []): array
    {
        /** @var list<DomainTld> $sold */
        $sold = DomainTld::query()->where('enabled', true)->orderBy('tld')->get()->all();

        if ($sold === []) {
            return [];
        }

        $known = array_map(static fn (DomainTld $tld): string => $tld->tld, $sold);

        $exact = RegistrableDomain::parse($typed, $known);

        $wanted = $this->namespacesToCheck($exact, $sold, $alsoTry);

        $results = [];

        foreach ($wanted as $tld) {
            $candidate = $exact->tld === $tld->tld
                ? $exact
                : RegistrableDomain::parse($exact->label.'.'.$tld->tld, $known);

            $results[] = [
                'domain' => $candidate,
                'tld' => $tld,
                'answer' => $this->answerFor($candidate, $tld),
            ];
        }

        return $results;
    }

    /**
     * The exact namespace first, then a bounded number of alternatives.
     *
     * Bounded because each one is a provider call: a search box that fanned
     * out across every namespace on sale would be a rate-limit incident with
     * every keystroke.
     *
     * @param  list<DomainTld>  $sold
     * @param  list<string>  $alsoTry
     * @return list<DomainTld>
     */
    private function namespacesToCheck(RegistrableDomain $exact, array $sold, array $alsoTry): array
    {
        $byName = [];

        foreach ($sold as $tld) {
            $byName[$tld->tld] = $tld;
        }

        $chosen = [];

        if (isset($byName[$exact->tld])) {
            $chosen[$exact->tld] = $byName[$exact->tld];
        }

        foreach ($alsoTry as $requested) {
            $requested = strtolower(ltrim(trim($requested), '.'));

            if (isset($byName[$requested])) {
                $chosen[$requested] = $byName[$requested];
            }
        }

        $limit = max(1, (int) config('domains.search.max_suggestions', 6));

        return array_slice(array_values($chosen), 0, $limit);
    }

    /**
     * Whether one name can be bought, from the cache or from the registrar.
     */
    private function answerFor(RegistrableDomain $candidate, DomainTld $tld): AvailabilityAnswer
    {
        if (! $tld->allows_registration) {
            return new AvailabilityAnswer($candidate->name, DomainAvailability::Unsupported);
        }

        /*
         * The catalogue says this platform sells the namespace. The adapter is
         * what says whether anything can actually be done in it.
         *
         * Both are asked, in that order, because they answer different
         * questions and a catalogue row is edited by a person. A `.sy` row
         * switched on by hand would otherwise be offered for sale by a
         * platform holding no registry licence, and the refusal would arrive
         * after the customer's money moved instead of before they saw a price.
         */
        try {
            $provider = $this->registrars->forTld($tld);

            if (! $provider->supports(RegistrarCapability::Availability)
                || ! $provider->supports(RegistrarCapability::Registration)) {
                return new AvailabilityAnswer($candidate->name, DomainAvailability::Unsupported);
            }
        } catch (RegistrarNotAvailableException|UnknownRegistrarDriverException) {
            /*
             * A namespace routed to a registrar this build cannot talk to at
             * all. Unsupported rather than unknown: nothing is going to
             * improve by trying again, and "we could not answer" would invite
             * the customer to retry for ever.
             */
            return new AvailabilityAnswer($candidate->name, DomainAvailability::Unsupported);
        }

        /*
         * A name this platform already holds is taken, and no registrar needs
         * to be asked. It also closes a small leak: a customer searching for a
         * name another account holds gets the same "unavailable" the rest of
         * the internet gets, rather than an answer that varies.
         */
        if ($this->platformHolds($candidate->name)) {
            return AvailabilityAnswer::unavailable($candidate->name);
        }

        $key = 'domains:availability:'.$tld->provider.':'.$candidate->name;
        $seconds = max(0, (int) config('domains.search.cache_seconds', 60));

        /** @var ?AvailabilityAnswer $cached */
        $cached = $seconds > 0 ? Cache::get($key) : null;

        if ($cached instanceof AvailabilityAnswer) {
            return $cached;
        }

        $answer = $this->ask($candidate, $tld);

        /*
         * An unknown answer is never cached. Caching it would turn one
         * registrar timeout into a minute of telling every customer that the
         * platform cannot answer — and the next call might well succeed.
         */
        if ($seconds > 0 && $answer->availability !== DomainAvailability::Unknown) {
            Cache::put($key, $answer, $seconds);
        }

        return $answer;
    }

    private function ask(RegistrableDomain $candidate, DomainTld $tld): AvailabilityAnswer
    {
        try {
            $provider = $this->registrars->forTld($tld);

            foreach ($provider->checkAvailability([$candidate->name]) as $answer) {
                if (strtolower($answer->name) === $candidate->name) {
                    return $answer;
                }
            }

            /*
             * The provider answered about something else, or about nothing.
             * Unknown rather than available: a caller cannot tell a dropped
             * response from a name the provider chose not to mention, and
             * guessing is how an available name is sold twice.
             */
            return AvailabilityAnswer::unknown($candidate->name);
        } catch (DomainRegistrarException) {
            /*
             * Swallowed on purpose, and only here. A search is a read that
             * many customers make at once; one registrar being down must
             * degrade the search rather than fail the request. Every path that
             * spends money lets this exception through.
             */
            return AvailabilityAnswer::unknown($candidate->name);
        }
    }

    private function platformHolds(string $name): bool
    {
        return Domain::query()
            ->where('name', $name)
            ->whereIn('state', DomainState::thatHoldTheName())
            ->exists();
    }
}
