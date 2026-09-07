<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/hosting/{account}.
 *
 * The cross-tenant case is the reason this file exists, and the assertion is
 * 404 rather than 403 on purpose: account ids are ULIDs, and a 403 tells the
 * caller that the id they guessed names a real account. Answering identically
 * for "no such account" and "not your account" is what stops the endpoint
 * being an enumeration oracle over the whole fleet.
 */
final class ShowHostingAccountEndpointTest extends HostingApiTestCase
{
    #[Test]
    public function it_shows_one_of_the_acting_customers_accounts(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.username', $account->username)
            ->assertJsonPath('data.primary_domain', $account->primary_domain)
            ->assertJsonPath('data.status', HostingAccountStatus::Active->value)
            ->assertJsonPath('data.package.disk_quota_mib', 10_240)
            ->assertJsonPath('data.ssl.status', 'active');
    }

    #[Test]
    public function another_customers_account_is_not_found(): void
    {
        [, $user] = $this->accountWithOwner();
        [$neighbour] = $this->accountWithOwner();

        $theirs = $this->hostingAccountFor($neighbour, username: 'neighbour1', atPanel: false);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$theirs->id)
            // 404, never 403: a 403 would confirm the id is real.
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($theirs->username, $body);
        $this->assertStringNotContainsString($theirs->primary_domain, $body);
    }

    #[Test]
    public function an_id_that_names_nothing_answers_exactly_as_a_neighbours_id_does(): void
    {
        [, $user] = $this->accountWithOwner();
        [$neighbour] = $this->accountWithOwner();
        $theirs = $this->hostingAccountFor($neighbour, username: 'neighbour2', atPanel: false);

        $missing = $this->actingAs($user)->getJson('/api/v1/hosting/01JBNOSUCHACCOUNT00000000');
        $foreign = $this->actingAs($user)->getJson('/api/v1/hosting/'.$theirs->id);

        $this->assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame($missing->json('error.code'), $foreign->json('error.code'));
        $this->assertSame($missing->json('error.message'), $foreign->json('error.message'));
    }

    #[Test]
    public function a_suspended_account_states_when_its_data_may_be_released(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, HostingAccountStatus::Suspended, 'acmestop', atPanel: false);
        $account->forceFill(['suspended_at' => now(), 'suspension_reason' => 'invoice 4471 unpaid'])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'invoice 4471 unpaid')
            ->assertJsonPath(
                'data.retention_releases_at',
                now()->addDays(30)->toImmutable()->toIso8601String(),
            );
    }

    #[Test]
    public function it_carries_nothing_internal(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);

        $response = $this->actingAs($user)->getJson('/api/v1/hosting/'.$account->id)->assertOk();

        $response
            ->assertJsonMissingPath('data.hosting_node_id')
            ->assertJsonMissingPath('data.hosting_package_id')
            ->assertJsonMissingPath('data.customer_id')
            ->assertJsonMissingPath('data.ip_address_id')
            ->assertJsonMissingPath('data.node')
            ->assertJsonMissingPath('data.package.panel_package_name')
            ->assertJsonMissingPath('data.password');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($this->node()->hostname, $body);
        $this->assertStringNotContainsString((string) $this->node()->credentials_reference, $body);
        $this->assertStringNotContainsString((string) $this->node()->api_endpoint, $body);
        $this->assertStringNotContainsString($this->package()->panel_package_name, $body);
    }
}
