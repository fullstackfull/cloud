<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Domains\Domain\Enums\DomainAvailability;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainQuote;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The search box and the price beside it, over the wire.
 */
final class SearchingAndQuotingOverHttpTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        DomainTld::factory()->onSale()->named('test')->create();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = $this->memberOf($this->customer, CustomerRole::Owner);
    }

    private function memberOf(Customer $customer, CustomerRole $role): User
    {
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function actingFor(Customer $customer): array
    {
        return ['X-Lynomia-Customer' => (string) $customer->getKey()];
    }

    #[Test]
    public function a_search_returns_an_answer_and_a_price(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->getJson('/api/v1/domains/search?name=findme.test');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'findme.test')
            ->assertJsonPath('data.0.availability', DomainAvailability::Available->value)
            ->assertJsonPath('data.0.is_orderable', true)
            ->assertJsonPath('data.0.price_minor', 3_500);
    }

    #[Test]
    public function an_unanswered_search_is_published_as_unknown_and_is_not_orderable(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->getJson('/api/v1/domains/search?name=gone-unreachable.test');

        /*
         * The screen has to be able to draw the third answer. A payload that
         * folded this into available or unavailable would make an honest
         * screen impossible to build, whatever the frontend did.
         */
        $response->assertOk()
            ->assertJsonPath('data.0.availability', DomainAvailability::Unknown->value)
            ->assertJsonPath('data.0.is_orderable', false)
            ->assertJsonPath('data.0.price_minor', null);
    }

    #[Test]
    public function a_premium_name_is_published_at_its_own_price(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->getJson('/api/v1/domains/search?name=rich-premium.test');

        $response->assertOk()
            ->assertJsonPath('data.0.availability', DomainAvailability::Premium->value)
            ->assertJsonPath('data.0.premium', true);

        // Not the list price. The whole point of the fifth answer.
        $this->assertGreaterThan(3_500, (int) $response->json('data.0.price_minor'));
    }

    #[Test]
    public function the_search_never_publishes_what_the_platform_pays(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->getJson('/api/v1/domains/search?name=margin.test');

        $body = (string) $response->getContent();

        // The wholesale price and the registrar's name are the platform's
        // business arrangement, and neither helps a customer buy a domain.
        $this->assertStringNotContainsString('cost', $body);
        $this->assertStringNotContainsString('provider', $body);
        $this->assertStringNotContainsString('2800', $body);
    }

    #[Test]
    public function a_quote_is_written_by_the_server_and_carries_no_price_from_the_client(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/domains/quotes', [
                'name' => 'buyme.test',
                'operation' => DomainOperationKind::Register->value,
                'term_years' => 2,

                // Sent, ignored, and not merely trimmed by validation: there is
                // no price field anywhere on the path this request takes.
                'price_minor' => 1,
                'currency' => 'KWD',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'buyme.test')
            ->assertJsonPath('data.term_years', 2)
            ->assertJsonPath('data.price_minor', 7_000);

        $quote = DomainQuote::query()->where('name', 'buyme.test')->firstOrFail();

        $this->assertSame(7_000, $quote->price_minor);
        $this->assertSame((string) $this->customer->getKey(), (string) $quote->customer_id);
    }

    #[Test]
    public function a_premium_name_cannot_be_quoted_at_the_ordinary_price_by_asking_nicely(): void
    {
        /*
         * The attack in one request. The client names a premium domain and
         * states an ordinary price; the server asks the registrar itself and
         * writes down what it will honour.
         */
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/domains/quotes', [
                'name' => 'jackpot-premium.test',
                'operation' => DomainOperationKind::Register->value,
                'price_minor' => 3_500,
                'premium' => false,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.premium', true);

        $this->assertGreaterThan(100_000, (int) $response->json('data.price_minor'));
    }

    #[Test]
    public function the_quote_response_does_not_publish_the_cost_or_the_registrar(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/domains/quotes', [
                'name' => 'discreet.test',
                'operation' => DomainOperationKind::Register->value,
            ]);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('cost', $body);
        $this->assertStringNotContainsString('provider', $body);
    }

    #[Test]
    public function a_name_in_a_namespace_this_platform_does_not_sell_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/domains/quotes', [
                'name' => 'somewhere.example',
                'operation' => DomainOperationKind::Register->value,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function nonsense_is_a_validation_failure_rather_than_a_crash(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->getJson('/api/v1/domains/search?name='.urlencode('http://not a name/'))
            ->assertStatus(422);
    }

    #[Test]
    public function a_signed_out_visitor_cannot_search(): void
    {
        $this->getJson('/api/v1/domains/search?name=anon.test')->assertUnauthorized();
    }

    #[Test]
    public function a_member_of_another_account_cannot_quote_against_this_one(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/domains/quotes', [
                'name' => 'notyours.test',
                'operation' => DomainOperationKind::Register->value,
            ])
            ->assertForbidden();
    }
}
