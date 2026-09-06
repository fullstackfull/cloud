<?php

declare(strict_types=1);

namespace Tests\Unit\Dedicated;

use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\FakeDedicatedProviderInProductionException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fake controller, and the one thing it must never do.
 */
final class FakeDedicatedProviderTest extends TestCase
{
    #[Test]
    public function it_refuses_to_be_constructed_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        /*
         * It reports hardware as healthy and accepts power operations without
         * contacting anything. In production it would mark servers active and
         * hand over credentials for machines that were never installed — and,
         * worse in the other direction, tell an operator that a failing disk
         * is fine. There is no safer degraded behaviour, so it refuses to
         * exist.
         */
        $this->expectException(FakeDedicatedProviderInProductionException::class);

        new FakeDedicatedProvider;
    }

    #[Test]
    public function the_refusal_names_the_environment_variable_that_fixes_it(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            new FakeDedicatedProvider;

            $this->fail('The fake provider was constructed in production.');
        } catch (FakeDedicatedProviderInProductionException $e) {
            $this->assertStringContainsString('DEDICATED_PROVIDER', $e->getMessage());
            $this->assertSame('dedicated.fake_provider_in_production', $e->errorCode());
        }
    }

    #[Test]
    public function it_is_constructible_in_every_non_production_environment(): void
    {
        foreach (['local', 'testing', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            $this->assertSame(BmcProtocol::Redfish, (new FakeDedicatedProvider)->protocol());
        }
    }

    #[Test]
    public function it_reports_the_protocol_it_stands_in_for(): void
    {
        // Callers branch on the protocol, so a test of the iLO path has to be
        // able to see iLO even when the adapter behind it is the fake.
        $this->assertSame(BmcProtocol::Ipmi, (new FakeDedicatedProvider(BmcProtocol::Ipmi))->protocol());
    }

    #[Test]
    public function power_state_is_remembered_per_endpoint(): void
    {
        $provider = new FakeDedicatedProvider;
        $endpoint = $this->endpoint('192.0.2.30');

        // Freshly racked machines are off, which is what makes the handler's
        // power-on branch the default one.
        $this->assertSame(PowerState::Off, $provider->powerState($endpoint));

        $provider->powerOn($endpoint);
        $this->assertSame(PowerState::On, $provider->powerState($endpoint));

        $provider->powerOff($endpoint);
        $this->assertSame(PowerState::Off, $provider->powerState($endpoint));
    }

    #[Test]
    public function a_one_time_override_is_consumed_by_the_boot_it_arms(): void
    {
        $provider = new FakeDedicatedProvider;
        $endpoint = $this->endpoint('192.0.2.31');

        $provider->setOneTimePxeBoot($endpoint);

        $this->assertTrue($provider->isPxeArmed($endpoint));
        $this->assertSame('Pxe', $provider->bootOrder($endpoint)[0]);

        $provider->reset($endpoint);

        // The machine does not reinstall itself on the next reboot, which is
        // the entire difference between "Once" and a boot-order change.
        $this->assertFalse($provider->isPxeArmed($endpoint));
        $this->assertNotSame('Pxe', $provider->bootOrder($endpoint)[0]);
    }

    #[Test]
    public function an_address_carrying_the_timeout_marker_fails_indeterminately(): void
    {
        $provider = new FakeDedicatedProvider;
        $endpoint = $this->endpoint(FakeDedicatedProvider::addressWith('192.0.2.32', FakeDedicatedProvider::TIMEOUT_MARKER));

        try {
            $provider->reset($endpoint);

            $this->fail('The timeout marker did not produce a failure.');
        } catch (DedicatedProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function an_address_carrying_the_failure_marker_fails_definitely(): void
    {
        $provider = new FakeDedicatedProvider;
        $endpoint = $this->endpoint(FakeDedicatedProvider::addressWith('192.0.2.33', FakeDedicatedProvider::PROVIDER_FAILURE_MARKER));

        try {
            $provider->powerOn($endpoint);

            $this->fail('The failure marker did not produce a failure.');
        } catch (DedicatedProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
        }
    }

    #[Test]
    public function the_mac_address_is_stable_for_a_given_endpoint(): void
    {
        $provider = new FakeDedicatedProvider;
        $endpoint = $this->endpoint('192.0.2.34');

        // A PXE authorisation is granted to a MAC. A fake that produced a new
        // address per call would make every provisioning test authorise a
        // machine that then could not boot.
        $first = $provider->hardwareHealth($endpoint)->provisioningMacAddress();
        $second = $provider->hardwareHealth($endpoint)->provisioningMacAddress();

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function the_unhealthy_marker_reports_a_failing_disk(): void
    {
        $provider = new FakeDedicatedProvider;
        $endpoint = $this->endpoint(FakeDedicatedProvider::addressWith('192.0.2.35', FakeDedicatedProvider::UNHEALTHY_MARKER));

        $this->assertSame(ComponentHealth::Critical, $provider->hardwareHealth($endpoint)->effectiveHealth());
    }

    private function endpoint(string $address): BmcEndpoint
    {
        $endpoint = new BmcEndpoint;

        $endpoint->forceFill([
            'protocol' => BmcProtocol::Redfish,
            'address' => $address,
            'verify_tls' => true,
        ]);

        $endpoint->id = '01JBMC'.strtoupper(substr(md5($address), 0, 20));

        return $endpoint;
    }
}
