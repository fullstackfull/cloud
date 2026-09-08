<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Lynomia\Modules\Domains\Application\Actions\SearchDomains;
use Lynomia\Modules\Domains\Domain\Enums\DomainAvailability;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Exceptions\InvalidDomainException;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The search box, and the answers it is allowed to give.
 */
final class SearchingForADomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * What the fake registrar will admit to serving. Stated rather than
         * assumed, because the factory refuses to route a namespace its driver
         * does not claim — a guard against a catalogue row pointed at the
         * wrong registry.
         */
        config(['domains.fake.tlds' => ['test', 'net.test', 'org.test', 'co.test', 'closed.test']]);

        DomainTld::factory()->onSale()->named('test')->create();
        DomainTld::factory()->onSale()->named('net.test')->create();
    }

    /**
     * @return list<array{domain: mixed, tld: mixed, answer: mixed}>
     */
    private function search(string $typed, array $alsoTry = []): array
    {
        return app(SearchDomains::class)->execute($typed, $alsoTry);
    }

    #[Test]
    public function a_free_name_comes_back_available(): void
    {
        $results = $this->search('somethingfree.test');

        $this->assertCount(1, $results);
        $this->assertSame('somethingfree.test', $results[0]['domain']->name);
        $this->assertSame(DomainAvailability::Available, $results[0]['answer']->availability);
    }

    #[Test]
    public function a_taken_name_comes_back_unavailable(): void
    {
        // The fake registrar's marker for a name somebody else holds.
        $results = $this->search('already-taken.test');

        $this->assertSame(DomainAvailability::Unavailable, $results[0]['answer']->availability);
    }

    #[Test]
    public function a_registrar_that_never_answered_is_reported_as_unknown(): void
    {
        $results = $this->search('slow-unreachable.test');

        /*
         * The single most important assertion in this file. A timeout is not
         * an answer, and the two ways of pretending it is are both defects
         * that cost money: "available" sells a name that is taken, and
         * "unavailable" turns away a customer who could have bought it.
         */
        $this->assertSame(DomainAvailability::Unknown, $results[0]['answer']->availability);
    }

    #[Test]
    public function an_unknown_answer_is_never_cached(): void
    {
        $this->search('slow-unreachable.test');

        $keys = Cache::get('domains:availability:fake:slow-unreachable.test');

        // Caching a non-answer would extend one registrar hiccup into a minute
        // of the platform refusing to answer anybody.
        $this->assertNull($keys);
    }

    #[Test]
    public function an_answer_is_cached_by_name_and_never_by_who_asked(): void
    {
        $this->search('cacheable.test');

        $answer = Cache::get('domains:availability:fake:cacheable.test');

        $this->assertNotNull($answer);
        $this->assertSame(DomainAvailability::Available, $answer->availability);

        // Availability is a fact about the world; storing it per customer
        // would be storing a search history for no gain.
        $this->assertStringNotContainsString('customer', 'domains:availability:fake:cacheable.test');
    }

    #[Test]
    public function a_registrar_that_says_it_does_not_know_is_not_improved_upon(): void
    {
        // Distinct from the timeout above: here the provider answered, and its
        // answer was "I cannot tell you". Passed through unchanged.
        $results = $this->search('cannot-say-unknown.test');

        $this->assertSame(DomainAvailability::Unknown, $results[0]['answer']->availability);
    }

    #[Test]
    public function a_premium_name_says_so_and_carries_its_price(): void
    {
        $results = $this->search('very-premium.test');

        $this->assertSame(DomainAvailability::Premium, $results[0]['answer']->availability);
        $this->assertNotNull($results[0]['answer']->premiumCost);
    }

    #[Test]
    public function a_name_this_platform_already_holds_is_unavailable_without_asking_anyone(): void
    {
        $customer = Customer::factory()->create();

        Domain::factory()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'ours.test',
            'tld' => 'test',
            'state' => DomainState::Active,
        ]);

        $results = $this->search('ours.test');

        $this->assertSame(DomainAvailability::Unavailable, $results[0]['answer']->availability);
    }

    #[Test]
    public function a_name_held_by_a_registration_still_in_flight_is_also_unavailable(): void
    {
        $customer = Customer::factory()->create();

        Domain::factory()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'inflight.test',
            'tld' => 'test',
            'state' => DomainState::RegistrationPending,
        ]);

        /*
         * The window this closes is small and real: two customers searching
         * the same name in the seconds between the first order being paid and
         * the registrar answering. The second must not be told it is free.
         */
        $results = $this->search('inflight.test');

        $this->assertSame(DomainAvailability::Unavailable, $results[0]['answer']->availability);
    }

    #[Test]
    public function a_name_this_platform_lost_is_available_again(): void
    {
        $customer = Customer::factory()->create();

        Domain::factory()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'lost.test',
            'tld' => 'test',
            'state' => DomainState::TransferredAway,
        ]);

        // Somebody else holds it now, and the registrar is the one to ask.
        $results = $this->search('lost.test');

        $this->assertSame(DomainAvailability::Available, $results[0]['answer']->availability);
    }

    #[Test]
    public function alternative_namespaces_are_offered_alongside_the_one_typed(): void
    {
        $results = $this->search('spread.test', ['net.test']);

        $names = array_map(static fn (array $row): string => $row['domain']->name, $results);

        $this->assertSame(['spread.test', 'spread.net.test'], $names);
    }

    #[Test]
    public function the_number_of_provider_calls_one_search_can_make_is_bounded(): void
    {
        config(['domains.search.max_suggestions' => 2]);

        DomainTld::factory()->onSale()->named('org.test')->create();
        DomainTld::factory()->onSale()->named('co.test')->create();

        $results = $this->search('bounded.test', ['net.test', 'org.test', 'co.test']);

        // A search box that fanned out over every namespace on sale would be a
        // rate-limit incident on every keystroke.
        $this->assertCount(2, $results);
    }

    #[Test]
    public function a_namespace_that_is_sold_but_closed_to_new_registrations_says_so(): void
    {
        DomainTld::factory()->onSale()->named('closed.test')->create(['allows_registration' => false]);

        $results = $this->search('anything.closed.test');

        $this->assertSame(DomainAvailability::Unsupported, $results[0]['answer']->availability);
    }

    #[Test]
    public function a_namespace_routed_to_a_registry_that_does_not_run_it_is_not_offered(): void
    {
        DomainTld::factory()->onSale()->named('wrong.test')->create();

        /*
         * The catalogue says this is on sale and the driver has never heard of
         * it. A person edited a row; the platform must not sell against it.
         * Unsupported rather than an error, because the customer did nothing
         * wrong and a retry will not help.
         */
        $results = $this->search('anything.wrong.test');

        $this->assertSame(DomainAvailability::Unsupported, $results[0]['answer']->availability);
    }

    #[Test]
    public function nonsense_is_refused_rather_than_searched_for(): void
    {
        $this->expectException(InvalidDomainException::class);

        $this->search('not a domain at all');
    }

    #[Test]
    public function a_subdomain_is_refused_because_it_is_not_a_thing_anyone_can_register(): void
    {
        $this->expectException(InvalidDomainException::class);

        $this->search('www.something.test');
    }
}
