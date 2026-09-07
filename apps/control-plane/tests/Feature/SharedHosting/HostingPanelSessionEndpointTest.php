<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\SharedHosting\Domain\Contracts\HostingProvider;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SsoSession;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/hosting/{account}/sso.
 *
 * The response body is a live credential: for its lifetime, whoever holds the
 * URL is inside the customer's control panel. So the properties asserted here
 * are the security ones — that the link belongs to the caller's own account,
 * that it points at the node the platform dialled and nowhere else, that a
 * read-only member cannot obtain one, and that nothing travelling beside it
 * would help anybody attack the node.
 */
final class HostingPanelSessionEndpointTest extends HostingApiTestCase
{
    #[Test]
    public function it_returns_a_link_and_a_username(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        $body = $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(201)
            ->assertJsonPath('data.username', $account->username)
            ->assertJsonPath('data.single_use', true)
            ->json('data');

        $this->assertIsString($body['url']);
        $this->assertStringStartsWith('https://', $body['url']);
        $this->assertGreaterThan(0, $body['expires_in']);
    }

    #[Test]
    public function the_link_points_at_the_node_the_account_lives_on(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        $url = $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(201)
            ->json('data.url');

        /*
         * The adapter checks this before the session is ever built — see
         * CpanelHostingProvider::assertSsoUrlBelongsToNode and the regression
         * in tests/Feature/Security/PanelSuppliedSsoUrlIsValidatedTest. It is
         * asserted again at the edge because this is the response the portal
         * turns into a button the customer clicks: a panel that could name any
         * host would be a phishing page delivered by the platform itself.
         */
        $this->assertIsString($url);
        $this->assertSame($this->node()->hostname, parse_url($url, PHP_URL_HOST));
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
    }

    /**
     * Two calls in a row both answer 201 against a real panel.
     *
     * Only that. The fake derives its URL from the node and the username, so
     * it answers both calls identically and this test cannot tell a fresh
     * session from a cached one — see
     * {@see every_call_reaches_the_panel_rather_than_a_cached_link}, which
     * uses a panel that answers differently each time and is where that
     * property is actually asserted.
     */
    #[Test]
    public function a_second_call_is_answered_the_same_way_as_the_first(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        $first = $this->actingAs($user)->postJson('/api/v1/hosting/'.$account->id.'/sso')->assertStatus(201);
        $second = $this->actingAs($user)->postJson('/api/v1/hosting/'.$account->id.'/sso')->assertStatus(201);

        // Never a 200 and never a 409: a spent link is not a platform fault
        // and a second request is not a conflict.
        $this->assertNotNull($first->json('data.url'));
        $this->assertNotNull($second->json('data.url'));
    }

    #[Test]
    public function another_customers_account_is_not_found(): void
    {
        [, $user] = $this->accountWithOwner();
        [$neighbour] = $this->accountWithOwner();
        $theirs = $this->hostingAccountFor($neighbour, username: 'neighbour3');

        $response = $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$theirs->id.'/sso')
            // 404 rather than 403: a 403 confirms the id names a real account,
            // and this endpoint is the one worth guessing ids against.
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($theirs->username, $body);
    }

    #[Test]
    public function a_read_only_member_may_not_open_a_panel_session(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $watcher = $this->memberOf($customer, CustomerRole::Member);
        $account = $this->hostingAccountFor($customer);

        $this->actingAs($watcher)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        // The same account, the same id: what differs is the role, not the row.
        $this->actingAs($owner)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(201);
    }

    #[Test]
    public function the_refusal_for_a_read_only_member_does_not_depend_on_the_id_being_real(): void
    {
        [$customer] = $this->accountWithOwner();
        $watcher = $this->memberOf($customer, CustomerRole::Member);

        // The permission check runs before any lookup, so an unauthorised
        // caller cannot use the difference between 403 and 404 to find out
        // which ids exist.
        $this->actingAs($watcher)
            ->postJson('/api/v1/hosting/01JBNOSUCHACCOUNT00000000/sso')
            ->assertStatus(403);
    }

    #[Test]
    public function a_suspended_account_is_refused_with_a_reason_rather_than_a_gateway_error(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, HostingAccountStatus::Suspended, 'acmestop');

        $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.panel_session_unavailable')
            ->assertJsonPath('error.details.status', 'suspended');
    }

    #[Test]
    public function a_terminated_account_has_nothing_to_sign_in_to(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, HostingAccountStatus::Terminated, 'acmegone', atPanel: false);

        $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.panel_session_unavailable')
            ->assertJsonPath('error.details.status', 'terminated');
    }

    #[Test]
    public function an_account_the_panel_has_not_confirmed_yet_is_refused(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, HostingAccountStatus::Pending, 'acmewait', atPanel: false);

        $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(409)
            ->assertJsonPath('error.details.status', 'pending');
    }

    #[Test]
    public function an_offline_node_is_reported_without_naming_the_machine(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        $this->node()->forceFill(['status' => HostingNodeStatus::Offline])->save();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.panel_session_unavailable');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($this->node()->hostname, $body);
        $this->assertStringNotContainsString($this->node()->id, $body);
    }

    #[Test]
    public function nothing_beside_the_link_would_help_anybody_reach_the_node(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(201);

        $response
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.api_token')
            ->assertJsonMissingPath('data.credentials_reference')
            ->assertJsonMissingPath('data.node')
            ->assertJsonMissingPath('data.hostname')
            ->assertJsonMissingPath('data.service')
            ->assertJsonMissingPath('data.hosting_node_id');

        $body = $response->getContent();
        $this->assertIsString($body);

        // The node's own hostname is inside the session URL, which is
        // unavoidable — it is where the customer is going. What must not be
        // here is the API endpoint, the config key its root token is read
        // from, or the node's id.
        $this->assertStringNotContainsString((string) $this->node()->api_endpoint, $body);
        $this->assertStringNotContainsString((string) $this->node()->credentials_reference, $body);
        $this->assertStringNotContainsString($this->node()->id, $body);
    }

    /*
     * ---------------------------------------------------------------------
     * What the platform says when the panel will not co-operate
     * ---------------------------------------------------------------------
     *
     * This is the one endpoint on the module that talks to a node while a
     * customer waits, so it is the one place where the node can answer back
     * into a customer-facing response. Every adapter puts the node's hostname,
     * the WHM function it called and the panel's own words into the exception
     * context, because that context is what an operator reads in the log — and
     * the shared renderer publishes a DomainException's context verbatim as
     * `error.details`. Left alone, a failed SSO tells the customer which
     * machine their neighbours are on, in a response the whole rest of this
     * module is built to avoid.
     */

    #[Test]
    public function a_panel_failure_does_not_name_the_machine_it_happened_on(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // The row exists and the panel has never heard of the account: a node
        // restored from a stale backup, or a create that half-finished. The
        // fake refuses exactly as a real node does.
        $account = $this->hostingAccountFor($customer, atPanel: false);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'hosting.provider_request_failed');

        $response
            ->assertJsonMissingPath('error.details.node')
            ->assertJsonMissingPath('error.details.provider_message')
            ->assertJsonMissingPath('error.details.panel')
            ->assertJsonMissingPath('error.details.operation');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($this->node()->hostname, $body);
        $this->assertStringNotContainsString($this->node()->id, $body);
        $this->assertStringNotContainsString((string) $this->node()->api_endpoint, $body);
        $this->assertStringNotContainsString((string) $this->node()->credentials_reference, $body);
    }

    #[Test]
    public function nothing_an_adapter_puts_in_its_context_reaches_the_customer(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        /*
         * Asserted against a context built by hand rather than against the
         * fake's, because the point is the boundary and not any one adapter:
         * whatever an adapter finds it useful to record for an operator —
         * and CpanelHostingProvider records the config key its root API token
         * is read from — must stop at the edge.
         */
        $panel = Mockery::mock(HostingProvider::class);
        $panel->shouldReceive('createSsoSession')
            ->once()
            ->andThrow(HostingProviderException::requestFailed('cpanel', 'create_sso_session', [
                'node' => $this->node()->hostname,
                'function' => 'create_user_session',
                'credentials_reference' => 'services.hosting.whm_token_for_node_seven',
                'provider_message' => 'access denied for user root from 10.0.4.11',
            ]));

        app(HostingProviderFactory::class)->swap($this->node(), $panel);

        $body = $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(502)
            ->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsString('create_user_session', $body);
        $this->assertStringNotContainsString('services.hosting.whm_token_for_node_seven', $body);
        $this->assertStringNotContainsString('access denied', $body);
        $this->assertStringNotContainsString('10.0.4.11', $body);
        $this->assertStringNotContainsString($this->node()->hostname, $body);
    }

    #[Test]
    public function a_panel_call_that_timed_out_is_answered_once_and_never_repeated(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        /*
         * `once()` is the assertion. A timeout means the platform stopped
         * waiting, not that the panel stopped working, so a second call from
         * inside the same request would be a second live session for the same
         * account — one of which nobody would ever see spent.
         */
        $panel = Mockery::mock(HostingProvider::class);
        $panel->shouldReceive('createSsoSession')
            ->once()
            ->andThrow(HostingProviderException::requestFailed('cpanel', 'create_sso_session', [
                'node' => $this->node()->hostname,
            ], indeterminate: true));

        app(HostingProviderFactory::class)->swap($this->node(), $panel);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/hosting/'.$account->id.'/sso')
            ->assertStatus(502);

        // Whether the platform may retry is an operational verdict recorded on
        // the exception for the log; it is not advice to give a customer, and
        // it is not something the response should invite a client to act on.
        $response->assertJsonMissingPath('error.details.indeterminate');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($this->node()->hostname, $body);
    }

    #[Test]
    public function every_call_reaches_the_panel_rather_than_a_cached_link(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        /*
         * The fake panel derives its URL from the node and the username, so it
         * answers two calls identically — which means a controller that cached
         * the first answer would pass a test that only compared the two bodies.
         * The double answers differently on purpose, so the assertion is that
         * the panel was asked twice and that the second answer, not the first,
         * is what the second caller got.
         */
        $panel = Mockery::mock(HostingProvider::class);
        $panel->shouldReceive('createSsoSession')
            ->twice()
            ->andReturn(
                new SsoSession(
                    url: 'https://'.$this->node()->hostname.':2083/cpsess1111111111111111/',
                    username: $account->username,
                    expiresAt: CarbonImmutable::now()->addMinutes(15),
                ),
                new SsoSession(
                    url: 'https://'.$this->node()->hostname.':2083/cpsess2222222222222222/',
                    username: $account->username,
                    expiresAt: CarbonImmutable::now()->addMinutes(15),
                ),
            );

        app(HostingProviderFactory::class)->swap($this->node(), $panel);

        $first = $this->actingAs($user)->postJson('/api/v1/hosting/'.$account->id.'/sso')->assertStatus(201);
        $second = $this->actingAs($user)->postJson('/api/v1/hosting/'.$account->id.'/sso')->assertStatus(201);

        $this->assertSame('https://'.$this->node()->hostname.':2083/cpsess1111111111111111/', $first->json('data.url'));
        $this->assertSame('https://'.$this->node()->hostname.':2083/cpsess2222222222222222/', $second->json('data.url'));
        $this->assertNotSame($first->json('data.url'), $second->json('data.url'));
    }
}
