<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Http\Requests\ListHostingAccountsRequest;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/hosting.
 *
 * A list endpoint on a multi-tenant platform is the place where a scoping
 * mistake is widest: one forgotten clause and every customer sees the whole
 * fleet. So the first assertion here is not that the list works, it is that it
 * contains nothing that is not the caller's.
 */
final class ListHostingAccountsEndpointTest extends HostingApiTestCase
{
    #[Test]
    public function it_lists_the_acting_customers_accounts(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $mine = $this->hostingAccountFor($customer, atPanel: false);

        $this->actingAs($user)
            ->getJson('/api/v1/hosting')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.username', $mine->username)
            ->assertJsonPath('data.0.primary_domain', $mine->primary_domain)
            ->assertJsonPath('data.0.status', HostingAccountStatus::Active->value)
            ->assertJsonPath('data.0.package.slug', 'hosting-starter')
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function another_customers_accounts_are_not_in_the_list(): void
    {
        [$mineCustomer, $user] = $this->accountWithOwner();
        [$theirsCustomer] = $this->accountWithOwner();

        $mine = $this->hostingAccountFor($mineCustomer, atPanel: false);
        $theirs = $this->hostingAccountFor($theirsCustomer, username: 'neighbour1', atPanel: false);

        $body = $this->actingAs($user)
            ->getJson('/api/v1/hosting')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->getContent();

        // Not merely absent from `data` — absent from the response entirely.
        // A neighbour's username or domain leaking through a `meta` count or a
        // debug key is the same disclosure.
        $this->assertIsString($body);
        $this->assertStringNotContainsString($theirs->id, $body);
        $this->assertStringNotContainsString($theirs->username, $body);
        $this->assertStringNotContainsString($theirs->primary_domain, $body);
    }

    #[Test]
    public function the_page_size_is_bounded_however_much_is_asked_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        foreach (range(1, 3) as $index) {
            $this->hostingAccountFor($customer, username: 'acmepage'.$index, atPanel: false);
        }

        $this->actingAs($user)
            ->getJson('/api/v1/hosting?per_page=100000')
            ->assertOk()
            // Clamped rather than refused: the caller meant "as many as I can
            // have", and the query is never handed the number they asked for.
            ->assertJsonPath('meta.per_page', ListHostingAccountsRequest::MAX_PER_PAGE)
            ->assertJsonPath('meta.max_per_page', ListHostingAccountsRequest::MAX_PER_PAGE)
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function a_nonsense_page_size_falls_back_to_the_default_rather_than_walking_everything(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->hostingAccountFor($customer, atPanel: false);

        $this->actingAs($user)
            ->getJson('/api/v1/hosting?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    #[Test]
    public function an_unknown_status_filter_is_a_422_naming_the_field(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/hosting?status=definitely-not-a-status')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['status']], 'request_id']]);
    }

    #[Test]
    public function a_page_below_one_is_a_422_naming_the_field(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/hosting?page=0')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['page']]]]);
    }

    #[Test]
    public function the_status_filter_selects_within_the_account(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $active = $this->hostingAccountFor($customer, username: 'acmelive', atPanel: false);
        $this->hostingAccountFor($customer, HostingAccountStatus::Suspended, 'acmestop', atPanel: false);

        $this->actingAs($user)
            ->getJson('/api/v1/hosting?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    #[Test]
    public function the_list_carries_nothing_about_the_node_the_accounts_are_on(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->hostingAccountFor($customer, atPanel: false);

        $response = $this->actingAs($user)->getJson('/api/v1/hosting')->assertOk();

        $response
            ->assertJsonMissingPath('data.0.hosting_node_id')
            ->assertJsonMissingPath('data.0.node')
            ->assertJsonMissingPath('data.0.customer_id')
            ->assertJsonMissingPath('data.0.ip_address_id')
            ->assertJsonMissingPath('data.0.package.panel_package_name');

        $body = $response->getContent();
        $this->assertIsString($body);

        // The specific facts, not a shape: the machine's name, the config key
        // its root API token is read from, and the panel package every
        // customer on this plan shares.
        $this->assertStringNotContainsString($this->node()->hostname, $body);
        $this->assertStringNotContainsString((string) $this->node()->api_endpoint, $body);
        $this->assertStringNotContainsString((string) $this->node()->credentials_reference, $body);
        $this->assertStringNotContainsString($this->package()->panel_package_name, $body);
        $this->assertStringNotContainsString($this->node()->id, $body);
    }

    #[Test]
    public function a_login_that_is_not_a_member_of_the_account_gets_nothing(): void
    {
        [$customer] = $this->accountWithOwner();
        $this->hostingAccountFor($customer, atPanel: false);

        // A verified user with no membership anywhere: the acting-customer
        // middleware has no account to resolve, so the request never reaches
        // the controller.
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson('/api/v1/hosting')
            ->assertStatus(403);
    }
}
