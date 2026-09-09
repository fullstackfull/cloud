<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The strings an attacker with the provider-manage permission would type,
 * and what happens to each. Pure: nothing here resolves a real name except
 * where the test says so.
 */
final class AnEndpointIsNotAWayIntoTheNetworkTest extends TestCase
{
    private EndpointPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new EndpointPolicy;
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: string}>
     */
    public static function refusedProviderEndpoints(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1:8006/', true, 'reserved'];
        yield 'loopback v6' => ['https://[::1]/', true, 'reserved'];
        yield 'localhost by name' => ['https://localhost/', true, 'this host'];
        yield 'metadata' => ['https://169.254.169.254/latest/meta-data/', true, 'metadata'];
        yield 'link-local' => ['https://169.254.10.10/', true, 'reserved'];
        yield 'unspecified' => ['https://0.0.0.0/', true, 'reserved'];
        yield 'multicast' => ['https://224.0.0.1/', true, 'reserved'];
        yield 'plain http' => ['http://api.cloudflare.example/', false, 'HTTPS'];
        yield 'file' => ['file:///etc/shadow', false, 'not a URL'];
        yield 'gopher' => ['gopher://internal/', false, 'HTTPS'];
        yield 'userinfo' => ['https://admin:hunter2@panel.example.test:2087/', true, 'credential in an endpoint'];
        yield 'internal suffix' => ['https://vault.internal/', false, 'metadata'];
        yield 'private for a cloud provider' => ['https://10.66.0.5/', false, 'not on our network'];
        yield 'private 172 for a cloud provider' => ['https://172.16.0.9/', false, 'not on our network'];
        yield 'private 192 for a cloud provider' => ['https://192.168.1.1/', false, 'not on our network'];
        yield 'shared address space for a cloud provider' => ['https://100.64.0.1/', false, 'never a provider'];
        yield 'not a url' => ['panel.example.test', false, 'not a URL'];
    }

    #[Test]
    #[DataProvider('refusedProviderEndpoints')]
    public function a_real_driver_is_refused_these(string $endpoint, bool $onOurHardware, string $reason): void
    {
        try {
            $this->policy->assertProviderEndpoint($endpoint, controlledDriver: false, onOurHardware: $onOurHardware, production: false);
            $this->fail($endpoint.' was accepted');
        } catch (EndpointRefused $refused) {
            $this->assertStringContainsString($reason, $refused->getMessage());
            $this->assertSame('endpoint_refused', $refused->errorCode());
            $this->assertSame(409, $refused->httpStatus());
        }
    }

    #[Test]
    public function a_provider_on_our_hardware_may_be_private_and_a_cloud_provider_may_be_public(): void
    {
        $this->policy->assertProviderEndpoint('https://10.66.0.5:8006/', controlledDriver: false, onOurHardware: true, production: true);
        $this->policy->assertProviderEndpoint('https://panel.example.test:2087/', controlledDriver: false, onOurHardware: true, production: true);
        $this->policy->assertProviderEndpoint('https://api.cloudflare.example/client/v4', controlledDriver: false, onOurHardware: false, production: true);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_controlled_driver_takes_a_marker_and_nothing_else_and_never_in_production(): void
    {
        $this->policy->assertProviderEndpoint('fake://connected', controlledDriver: true, onOurHardware: false, production: false);

        foreach (['https://127.0.0.1/', 'fake://../x', 'fake://connected/extra', 'http://x'] as $endpoint) {
            try {
                $this->policy->assertProviderEndpoint($endpoint, controlledDriver: true, onOurHardware: false, production: false);
                $this->fail($endpoint.' was accepted for a controlled driver');
            } catch (EndpointRefused) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(EndpointRefused::class);
        $this->policy->assertProviderEndpoint('fake://connected', controlledDriver: true, onOurHardware: false, production: true);
    }

    #[Test]
    public function a_machine_address_is_a_host_and_may_be_private_but_never_this_host(): void
    {
        $this->policy->assertMachineAddress('10.66.0.2', production: true);
        $this->policy->assertMachineAddress('bmc-01.mgmt.example.test', production: true);
        $this->policy->assertMachineAddress('fake://connected', production: false);

        foreach (['127.0.0.1', '::1', 'localhost', '169.254.169.254', 'https://10.66.0.2', 'user@10.66.0.2', '10.66.0.2/admin', 'fake://connected'] as $address) {
            try {
                $this->policy->assertMachineAddress($address, production: $address === 'fake://connected');
                $this->fail($address.' was accepted as a machine address');
            } catch (EndpointRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
