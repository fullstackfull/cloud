<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

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
        config()->set('billing.providers', [
            'payment' => 'stripe',
            'compute' => 'proxmox',
            'dedicated' => 'redfish',
            'hosting' => 'cpanel',
            'dns' => 'cloudflare',
            'backup' => 'pbs',
        ]);

        $this->guard()->assertNoFakeProviders();

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
