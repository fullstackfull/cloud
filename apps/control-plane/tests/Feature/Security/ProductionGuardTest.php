<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Providers\ProviderRegistryServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class ProductionGuardTest extends TestCase
{
    private function guard(): ProviderRegistryServiceProvider
    {
        return new ProviderRegistryServiceProvider($this->app);
    }

    #[Test]
    public function a_production_deployment_with_a_fake_provider_refuses_to_boot(): void
    {
        /*
         * The worst failure this platform can have is a production system that
         * reports payments captured and servers created while doing neither.
         * This guard turns that into a deployment error visible in the first
         * thirty seconds instead of a support ticket a week later.
         */
        config()->set('billing.providers', [
            'payment' => 'stripe',
            'compute' => 'fake',
            'hosting' => 'cpanel',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/compute/');

        $this->guard()->assertNoFakeProviders();
    }

    #[Test]
    public function the_error_names_every_offending_provider_not_just_the_first(): void
    {
        config()->set('billing.providers', [
            'payment' => 'fake',
            'compute' => 'fake',
            'dns' => 'cloudflare',
        ]);

        try {
            $this->guard()->assertNoFakeProviders();
            $this->fail('Expected the guard to refuse this configuration.');
        } catch (RuntimeException $e) {
            // An operator fixing one variable at a time, redeploying between
            // each, is a bad afternoon.
            $this->assertStringContainsString('payment', $e->getMessage());
            $this->assertStringContainsString('compute', $e->getMessage());
            $this->assertStringNotContainsString('dns', $e->getMessage());
        }
    }

    #[Test]
    public function the_check_is_case_insensitive(): void
    {
        config()->set('billing.providers', ['payment' => 'FAKE']);

        $this->expectException(RuntimeException::class);

        $this->guard()->assertNoFakeProviders();
    }

    #[Test]
    public function a_fully_real_configuration_passes(): void
    {
        // `dns` is deliberately absent. This build contains exactly one
        // reverse-DNS driver and it is the fake, so there is no value for that
        // key a production deployment could legally carry — which is a fact
        // about the build, recorded in docs/build-status.md, not a gap in this
        // test. An unset key is how a deployment says it publishes no PTRs.
        config()->set('billing.providers', [
            'payment' => 'stripe',
            'compute' => 'proxmox',
            'dedicated' => 'redfish',
            'hosting' => 'cpanel',
            'backup' => 'proxmox',
        ]);

        $this->guard()->assertNoFakeProviders();

        $this->addToAssertionCount(1);
    }

    /*
     * ---------------------------------------------------------------------
     * A driver that does not exist
     * ---------------------------------------------------------------------
     *
     * Refusing the fake is only half of it. `PAYMENT_PROVIDER=myfatoorah` and
     * `DNS_PROVIDER=cloudflare` are settings this build cannot honour, and they
     * used to pass this guard: production booted, reported healthy, and threw
     * the first time a customer tried to pay or an operator set a PTR. Failing
     * at boot is the whole point of having the guard at all.
     *
     * Only the two families whose driver actually comes from configuration are
     * checked. Compute, dedicated and hosting resolve per row — from the
     * cluster's driver, the endpoint's protocol and the node's panel — so a
     * config value for them names nothing and cannot be validated against
     * anything.
     */

    #[Test]
    public function a_payment_driver_this_build_does_not_contain_refuses_to_boot(): void
    {
        config()->set('billing.providers', ['payment' => 'myfatoorah']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payment.*myfatoorah/s');

        $this->guard()->assertEveryConfiguredDriverExists();
    }

    #[Test]
    public function a_dns_driver_this_build_does_not_contain_refuses_to_boot(): void
    {
        // Route 53 is a real provider with no adapter here. `cloudflare` stood
        // in this test until the adapter was written, and swapping it out is
        // the honest edit: the guarantee under test is "a driver that is not
        // here is refused", not "cloudflare is not here".
        config()->set('billing.providers', ['payment' => 'stripe', 'dns' => 'route53']);

        try {
            $this->guard()->assertEveryConfiguredDriverExists();
            $this->fail('A DNS driver with no adapter was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('route53', $e->getMessage());
            // The message has to say what it will accept, or an operator is
            // left guessing at the one string that would have worked.
            $this->assertStringContainsString('cloudflare', $e->getMessage());
        }
    }

    #[Test]
    public function the_dns_driver_must_exist_on_both_sides_of_the_one_key(): void
    {
        // `billing.providers.dns` drives two adapters — forward DNS and
        // reverse DNS — and a driver present in one list and absent from the
        // other would boot and then fail on whichever half was missing. The
        // guard checks the intersection, so both lists have to hold it.
        config()->set('billing.providers', ['payment' => 'stripe', 'dns' => 'cloudflare']);

        $this->guard()->assertEveryConfiguredDriverExists();

        $this->assertContains('cloudflare', DnsProviderFactory::drivers());
        $this->assertContains('cloudflare', ReverseDnsProviderFactory::drivers());
    }

    #[Test]
    public function drivers_this_build_does_contain_are_accepted(): void
    {
        config()->set('billing.providers', [
            'payment' => 'stripe',
            // The fake exists, so this check accepts it. Refusing it in
            // production is the other method's job, and keeping the two
            // separate is what lets each be tested for one thing.
            'dns' => 'fake',
            // Named here on purpose: these three are not resolved from
            // configuration, so whatever they say must not fail the check.
            'compute' => 'proxmox',
            'dedicated' => 'redfish',
            'hosting' => 'cpanel',
            // `pbs` used to stand here as a plausible-looking value for a
            // driver that did not exist. It does now, and it is called
            // proxmox — the hypervisor is what the platform asks, and PBS is
            // where the archive lands.
            'backup' => 'proxmox',
        ]);

        $this->guard()->assertEveryConfiguredDriverExists();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function development_keeps_using_fakes_without_complaint(): void
    {
        // The guard must not fire outside production, or no one could develop.
        config()->set('billing.providers', ['payment' => 'fake', 'compute' => 'fake']);

        $this->guard()->boot();

        $this->addToAssertionCount(1);
    }
}
