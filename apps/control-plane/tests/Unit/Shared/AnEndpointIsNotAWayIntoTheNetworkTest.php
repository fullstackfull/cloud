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
        /*
         * The sample hostnames are not under `.example` or `.test`, and that
         * matters because these three lines assert acceptance in PRODUCTION.
         * Gap 4 made the endpoint policy refuse a name under a reserved domain
         * when production is true — nothing will ever answer one — so a sample
         * that used a reserved domain would now be asserting that a reserved
         * domain is acceptable in production, which is the opposite of what
         * this file wants to say. The subject here is private-versus-public
         * addressing, and it is unchanged.
         */
        $this->policy->assertProviderEndpoint('https://10.66.0.5:8006/', controlledDriver: false, onOurHardware: true, production: true);
        $this->policy->assertProviderEndpoint('https://panel.lynomia-hosting.net:2087/', controlledDriver: false, onOurHardware: true, production: true);
        $this->policy->assertProviderEndpoint('https://api.some-dns-provider.net/client/v4', controlledDriver: false, onOurHardware: false, production: true);

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
        // Not a reserved domain, for the reason given in the provider test
        // above: this line asserts acceptance in production.
        $this->policy->assertMachineAddress('bmc-01.mgmt.lynomia-fleet.net', production: true);
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

    #[Test]
    public function a_port_on_a_machine_address_does_not_switch_off_every_check_below_it(): void
    {
        /*
         * =====================================================================
         * A REAL BYPASS, FOUND BY PROBING RATHER THAN BY READING
         * =====================================================================
         *
         * Every one of these was accepted before Phase 30B-SIM, and the
         * mechanism is worth writing down because it is the kind that survives
         * review.
         *
         * assertHost asked whether the string was an IP literal.
         * `169.254.169.254:80` is not one — the colon makes it fail
         * FILTER_VALIDATE_IP — so it fell through to name resolution. Name
         * resolution cannot resolve a string with a port in it either, and
         * returned no addresses at all. So the loop that refuses loopback,
         * link-local, multicast and the cloud metadata services ran **zero
         * times**, and the address was accepted with no address check having
         * happened.
         *
         * The name blocklist still caught `localhost:80` by name. Nothing
         * caught the literals, which is the half that matters: the cloud
         * metadata service is reached by address, it answers on port 80, and
         * a "connection test" pointed at it is a request to it with a
         * credential attached.
         *
         * A BMC on a non-standard port is an ordinary thing to have, so the
         * fix parses the port rather than forbidding one — and then checks the
         * port in its own right, because a machine address is dialled and a
         * port outside 1-65535 is not a thing that can be dialled.
         */
        $bypasses = [
            '169.254.169.254:80' => 'the cloud metadata service, reached by address on the port it answers on',
            '127.0.0.1:8443' => 'this host, which runs the control plane\'s own services',
            '[::1]:443' => 'this host again, over IPv6',
            '0.0.0.0:1' => 'the unspecified address',
            'metadata:80' => 'the metadata service by name',
            '169.254.1.1:443' => 'link-local',
        ];

        foreach ($bypasses as $address => $what) {
            try {
                $this->policy->assertMachineAddress($address, production: true);
                $this->fail($address.' was accepted as a machine address. It is '.$what.'.');
            } catch (EndpointRefused) {
                $this->addToAssertionCount(1);
            }
        }

        // And a port that is not a port.
        foreach (['10.66.0.2:0', '10.66.0.2:70000', '10.66.0.2:ssh', '10.66.0.2:-1'] as $address) {
            try {
                $this->policy->assertMachineAddress($address, production: true);
                $this->fail($address.' was accepted, and its port is not a port number.');
            } catch (EndpointRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_machine_on_a_non_standard_port_is_still_allowed(): void
    {
        /*
         * The positive twin. A fix that refused every port would have closed
         * the bypass by making the platform unable to reach a BMC on 8443 —
         * which is most of them behind a management proxy — and somebody would
         * have reverted it.
         */
        $this->policy->assertMachineAddress('10.66.0.2:8006', production: true);
        $this->policy->assertMachineAddress('10.66.0.2:623', production: true);
        $this->policy->assertMachineAddress('bmc-01.mgmt.lynomia-fleet.net:8443', production: true);

        // A bare IPv6 literal, whose colons are part of the address rather
        // than a port separator. Splitting on the last one would turn a
        // routable address into an unresolvable name and a loopback literal
        // into something nothing checked.
        $this->policy->assertMachineAddress('2001:4860:4860::8888', production: true);
        $this->policy->assertMachineAddress('[2001:4860:4860::8888]:8443', production: true);

        $this->addToAssertionCount(1);
    }
}
