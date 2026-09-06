<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\FakeDedicatedProviderInProductionException;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IloDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IpmiDedicatedProvider;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\RedfishDedicatedProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Choosing the adapter for one controller.
 *
 * Resolution is per endpoint rather than per application, because every
 * controller has its own address, certificate policy and credential — and
 * because the one shape of mistake that matters here, an operation aimed at
 * the wrong physical machine, is what a per-endpoint signature makes
 * impossible.
 */
final class DedicatedProviderFactoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dedicated.provider', 'redfish');
        config()->set('dedicated.credentials.test-bmc', [
            'username' => 'lynomia-svc',
            'password' => 'a-real-password-8Hs2',
        ]);
    }

    #[Test]
    public function it_resolves_an_adapter_from_the_endpoints_protocol(): void
    {
        $this->assertInstanceOf(RedfishDedicatedProvider::class, $this->resolve(BmcProtocol::Redfish));
        $this->assertInstanceOf(IloDedicatedProvider::class, $this->resolve(BmcProtocol::Ilo));
        $this->assertInstanceOf(IpmiDedicatedProvider::class, $this->resolve(BmcProtocol::Ipmi));
    }

    #[Test]
    public function each_adapter_reports_the_protocol_its_row_names(): void
    {
        // Callers and rows must agree, or an inventory report would describe a
        // fleet that has migrated off IPMI when it has not.
        foreach (BmcProtocol::cases() as $protocol) {
            $this->assertSame($protocol, $this->resolve($protocol)->protocol());
        }
    }

    #[Test]
    public function an_adapter_is_memoised_per_endpoint(): void
    {
        $endpoint = $this->endpoint(BmcProtocol::Redfish);
        $factory = app(DedicatedProviderFactory::class);

        // An inventory sync makes several calls against one controller, and
        // rebuilding the HTTP stack for each would be pure waste on a device
        // whose whole CPU is slower than a phone's.
        $this->assertSame($factory->for($endpoint), $factory->for($endpoint));
    }

    #[Test]
    public function two_endpoints_get_two_adapters(): void
    {
        $factory = app(DedicatedProviderFactory::class);

        $this->assertNotSame(
            $factory->for($this->endpoint(BmcProtocol::Redfish)),
            $factory->for($this->endpoint(BmcProtocol::Redfish)),
        );
    }

    #[Test]
    public function an_endpoint_with_no_configured_credentials_is_refused_rather_than_tried_anonymously(): void
    {
        $endpoint = $this->endpoint(BmcProtocol::Redfish, credentialsReference: 'not-configured');

        try {
            app(DedicatedProviderFactory::class)->for($endpoint);

            $this->fail('An adapter was built with no credentials.');
        } catch (BmcNotConfiguredException $e) {
            // Vendor defaults are published. A controller reached with them is
            // a complete out-of-band computer — power control and virtual media
            // — handed to whoever tried them first.
            $this->assertSame('not-configured', $e->context()['credentials_reference']);
        }
    }

    #[Test]
    public function credentials_are_never_read_from_the_endpoint_row(): void
    {
        $endpoint = $this->endpoint(BmcProtocol::Redfish);

        // There is no password column, and there is deliberately nowhere for
        // one to be added: a BMC password in the database is a BMC password in
        // every backup, replica and support export.
        $this->assertArrayNotHasKey('password', $endpoint->getAttributes());
        $this->assertNotContains('password', array_keys($endpoint->getAttributes()));
    }

    #[Test]
    public function configuration_can_replace_every_adapter_with_the_fake(): void
    {
        config()->set('dedicated.provider', 'fake');

        $provider = $this->resolve(BmcProtocol::Ipmi);

        $this->assertInstanceOf(FakeDedicatedProvider::class, $provider);
        // It still reports the protocol the row names, so a test of the IPMI
        // path can see IPMI.
        $this->assertSame(BmcProtocol::Ipmi, $provider->protocol());
    }

    #[Test]
    public function an_absent_provider_setting_never_means_fake(): void
    {
        // A platform that silently faked its BMC layer would report servers as
        // installed without installing them, so the default has to be a real
        // adapter and never a convenient one.
        config()->set('dedicated.provider', null);

        $this->assertInstanceOf(RedfishDedicatedProvider::class, $this->resolve(BmcProtocol::Redfish));
    }

    #[Test]
    public function the_fake_cannot_be_resolved_in_production(): void
    {
        config()->set('dedicated.provider', 'fake');

        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(FakeDedicatedProviderInProductionException::class);

        $this->resolve(BmcProtocol::Redfish);
    }

    #[Test]
    public function a_swapped_adapter_replaces_only_that_endpoints(): void
    {
        $factory = app(DedicatedProviderFactory::class);

        $swapped = $this->endpoint(BmcProtocol::Redfish);
        $other = $this->endpoint(BmcProtocol::Redfish);

        $double = new FakeDedicatedProvider;
        $factory->swap($swapped, $double);

        $this->assertSame($double, $factory->for($swapped));
        $this->assertInstanceOf(RedfishDedicatedProvider::class, $factory->for($other));
    }

    private function resolve(BmcProtocol $protocol): DedicatedProvider
    {
        return app(DedicatedProviderFactory::class)->for($this->endpoint($protocol));
    }

    private function endpoint(BmcProtocol $protocol, string $credentialsReference = 'test-bmc'): BmcEndpoint
    {
        return BmcEndpoint::factory()->create([
            'protocol' => $protocol,
            'credentials_reference' => $credentialsReference,
        ]);
    }
}
