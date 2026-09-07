<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Support\Facades\Cache;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Vps\Application\Actions\RedeemConsoleSession;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use Lynomia\Modules\Vps\Domain\Exceptions\ConsoleSessionInvalidException;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/vps/{vm}/console.
 *
 * A console is a keyboard attached to somebody's root shell, so the properties
 * asserted here are the security ones: short-lived, single-use, scoped to one
 * machine, and carrying nothing that is valid against the hypervisor.
 */
final class VpsConsoleEndpointTest extends VpsApiTestCase
{
    #[Test]
    public function a_session_is_issued_with_a_token_and_a_deadline(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $body = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201)
            ->assertJsonPath('data.virtual_machine_id', $machine->id)
            ->assertJsonPath('data.single_use', true)
            ->assertJsonPath('data.ttl_seconds', ConsoleSessionStore::TTL_SECONDS)
            ->json('data');

        $this->assertIsString($body['token']);
        $this->assertGreaterThanOrEqual(32, strlen($body['token']));
        $this->assertLessThanOrEqual(ConsoleSessionStore::TTL_SECONDS, $body['expires_in']);
        $this->assertGreaterThan(0, $body['expires_in']);
    }

    #[Test]
    public function the_session_is_short_lived(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        // A minute is the window between the API answering and a browser
        // opening a socket. Anything longer is a credential sitting in a tab.
        $this->assertLessThanOrEqual(60, ConsoleSessionStore::TTL_SECONDS);

        $issued = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201)
            ->json('data');

        $this->travel(ConsoleSessionStore::TTL_SECONDS + 1)->seconds();

        $this->expectException(ConsoleSessionInvalidException::class);
        app(RedeemConsoleSession::class)->execute($issued['id'], $issued['token']);
    }

    #[Test]
    public function a_session_may_be_redeemed_exactly_once(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $issued = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201)
            ->json('data');

        $redeemed = app(RedeemConsoleSession::class)->execute($issued['id'], $issued['token']);

        $this->assertSame($machine->id, $redeemed->virtualMachineId);
        $this->assertSame($customer->id, $redeemed->customerId);
        // Redemption hands back the claims, never the secret again.
        $this->assertNull($redeemed->token);

        // Two consoles on one permit is one console the customer did not open.
        $this->expectException(ConsoleSessionInvalidException::class);
        app(RedeemConsoleSession::class)->execute($issued['id'], $issued['token']);
    }

    #[Test]
    public function a_wrong_token_is_refused_and_does_not_burn_the_session(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $issued = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201)
            ->json('data');

        try {
            // The session id travels in a URL; the token does not. If a wrong
            // token consumed the session, anyone who saw the id could destroy
            // permits as fast as they are issued.
            app(RedeemConsoleSession::class)->execute($issued['id'], 'not-the-token');
            $this->fail('A wrong token must not be accepted.');
        } catch (ConsoleSessionInvalidException) {
            // expected
        }

        $redeemed = app(RedeemConsoleSession::class)->execute($issued['id'], $issued['token']);
        $this->assertSame($machine->id, $redeemed->virtualMachineId);
    }

    #[Test]
    public function the_token_is_not_stored_in_the_clear(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $issued = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201)
            ->json('data');

        /** @var array<string, mixed> $record */
        $record = Cache::get('vps:console:session:'.$issued['id']);

        $this->assertIsArray($record);
        $this->assertArrayNotHasKey('token', $record);
        $this->assertSame(hash('sha256', $issued['token']), $record['token_hash']);
    }

    #[Test]
    public function nothing_the_hypervisor_would_accept_reaches_the_browser(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $node = $this->node();
        $cluster = $node->cluster()->firstOrFail();
        $cluster->forceFill([
            'api_endpoint' => 'https://pve-secret.internal:8006',
            'credentials_reference' => 'vault://clusters/pve-01',
        ])->save();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201);

        $body = $response->getContent() ?: '';

        /*
         * A Proxmox vncproxy ticket is a bearer credential for a root console
         * on the node, and the node's own address is the thing that makes it
         * usable. Neither ever leaves the platform: the gateway obtains them
         * server-side, on redemption, on its own connection.
         */
        foreach ([
            $node->provider_name,
            'pve-secret.internal',
            'vault://clusters/pve-01',
            (string) $machine->provider_id,
            (string) $machine->node_id,
            (string) $machine->cluster_id,
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        foreach (['ticket', 'csrf', 'vncticket', 'password', 'credentials'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json('data'));
        }
    }

    #[Test]
    public function another_customers_machine_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = $this->machineFor($theirs);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$foreign->id.'/console')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function a_stopped_machine_still_gets_a_console(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, powerState: PowerState::Stopped);

        // The console is exactly what a customer needs when the guest will not
        // boot, so it is not gated on the guest being up.
        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201);
    }

    #[Test]
    public function a_machine_the_hypervisor_has_never_confirmed_has_no_console(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->unprovisionedMachineFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.console_unavailable');
    }

    #[Test]
    public function the_gateway_url_is_published_when_one_is_configured(): void
    {
        /*
         * This field was read from a config file that did not exist, so it was
         * null in every deployment — a portal that could never offer a console
         * however the platform was set up. The test pins it to the key the
         * gateway itself uses.
         */
        config()->set('console_gateway.public_url', 'wss://console.lynomia.test/console');

        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201)
            ->assertJsonPath('data.gateway', 'wss://console.lynomia.test/console');
    }

    #[Test]
    public function no_gateway_is_published_as_null_rather_than_invented(): void
    {
        // A fabricated URL would have the portal ship a connect button that
        // fails in the browser, which a customer reads as their server being
        // broken.
        config()->set('console_gateway.public_url', null);

        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201)
            ->assertJsonPath('data.gateway', null);
    }

    #[Test]
    public function a_member_may_not_open_a_console(): void
    {
        [$customer, $viewer] = $this->accountWithOwner(CustomerRole::Member);
        $machine = $this->machineFor($customer);

        // A console is root access. Read-only membership is not root.
        $this->actingAs($viewer)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    #[Test]
    public function two_sessions_for_one_machine_do_not_share_a_token(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $first = $this->actingAs($user)->getJson('/api/v1/vps/'.$machine->id.'/console')->json('data');
        $second = $this->actingAs($user)->getJson('/api/v1/vps/'.$machine->id.'/console')->json('data');

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertNotSame($first['token'], $second['token']);

        // And spending one does not spend the other.
        app(RedeemConsoleSession::class)->execute($first['id'], $first['token']);
        $this->assertSame($machine->id, app(RedeemConsoleSession::class)->execute($second['id'], $second['token'])->virtualMachineId);
    }
}
