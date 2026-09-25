<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;
use PHPUnit\Framework\Attributes\Test;

/**
 * F-29: a BMC row is asked about again at the moment it is dialled.
 *
 * `RecordBmcEndpoint` checks an address when an operator writes it. A row that
 * arrived by any other road — a seeder, an import, SQL, a row written before
 * the policy refused what it now refuses — was dialled with the machine's
 * credential and nothing asked. The factory that builds the connection now
 * asks, and a refused address never becomes a connection.
 *
 * The refusal names the address, which is right for an operator and wrong for
 * a customer: a power request is a customer route, and the address of a
 * machine on the management network is what the dedicated resources are
 * written to withhold. So the refusal is translated before it reaches the
 * customer — the factory raises the module's own "cannot build a connection"
 * exception naming the endpoint id and not the address, the power action turns
 * that into the customer's own server id and verb, and the address stays on
 * the original, which is kept as `previous` for the log.
 */
final class ABmcAddressIsAskedAboutAgainBeforeItIsDialledTest extends DedicatedApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The real factory, with a credential configured, so the only thing
        // standing between the row and a request is the address check.
        config()->set('dedicated.provider', null);
        config()->set('dedicated.credentials.bmc-secret-key-name', [
            'username' => 'lynomia-svc',
            'password' => 'a-real-password-8Hs2',
        ]);

        Http::fake();
    }

    #[Test]
    public function a_customer_power_request_never_reaches_a_refused_address_and_never_learns_it(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);
        $server->bmcEndpoints()->firstOrFail()->forceFill(['address' => '169.254.169.254'])->save();

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'dedicated-power-f29-001')
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'dedicated.server_control_unavailable');

        Http::assertNothingSent();

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('169.254.169.254', $body);
        $this->assertStringNotContainsString('metadata', $body);
    }

    #[Test]
    public function the_factory_refuses_to_build_a_connection_to_a_refused_address_and_says_so_without_the_address(): void
    {
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);
        $endpoint = $server->bmcEndpoints()->firstOrFail();
        $endpoint->forceFill(['address' => '0x7f000001'])->save();

        try {
            $this->app->make(DedicatedProviderFactory::class)->for($endpoint);
            $this->fail('A connection was built to a spelling of this host.');
        } catch (BmcNotConfiguredException $e) {
            $this->assertStringNotContainsString('0x7f000001', $e->getMessage());
            $this->assertStringNotContainsString('0x7f000001', (string) json_encode($e->context()));
            $this->assertInstanceOf(EndpointRefused::class, $e->getPrevious(), 'The policy\'s own refusal is kept for the log.');
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function an_ordinary_address_still_gets_its_connection(): void
    {
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);
        $endpoint = $server->bmcEndpoints()->firstOrFail();
        $endpoint->forceFill(['address' => '10.20.0.7', 'port' => 8443])->save();

        $this->app->make(DedicatedProviderFactory::class)->for($endpoint);

        $this->addToAssertionCount(1);
    }
}
