<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\BmcConnection;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\RedfishDedicatedProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\CpanelHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REGRESSION — a managed device does not get to choose where the platform's
 * next request goes.
 *
 * RedfishDedicatedProvider::resolveLink() refuses to follow an `@odata.id`
 * that names a host other than the controller itself, and that check is
 * documented as the mitigation for a compromised BMC turning the queue worker
 * — which sits on the management network — into an SSRF proxy.
 *
 * It used to be bypassable by an HTTP redirect. None of the four adapters
 * passed `allow_redirects => false`, so Guzzle's redirect middleware followed
 * up to five redirects per request to any host the answering device named, and
 * no link validation was involved at all because the URL never became a link
 * in a body.
 *
 * Every adapter now disables redirect following and refuses a 3xx outright,
 * flagged indeterminate — a device that answered a reset or a createacct with
 * a redirect may still have acted on it.
 */
final class ProviderAdaptersRefuseOffHostRedirectsTest extends TestCase
{
    private const string METADATA_HOST = 'http://169.254.169.254/latest/meta-data/iam/security-credentials/';

    #[Test]
    public function a_controller_redirect_is_not_followed_onto_the_cloud_metadata_endpoint(): void
    {
        Http::fake([
            // The controller answers the very first Redfish GET with a
            // redirect to somewhere it has no business naming.
            '192.0.2.10/*' => Http::response('', 302, ['Location' => self::METADATA_HOST]),

            // In production this is a real host on the network the queue
            // worker sits on. Nothing may reach it.
            '169.254.169.254/*' => Http::response([
                'PowerState' => 'On',
                'Status' => ['Health' => 'OK'],
            ], 200, ['Content-Type' => 'application/json']),
        ]);

        try {
            $this->redfish()->powerState($this->bmcEndpoint());

            $this->fail('The adapter accepted a redirect as an answer.');
        } catch (DedicatedProviderException $e) {
            $this->assertStringContainsString('redirect', $e->getMessage());
            $this->assertTrue($e->isIndeterminate(), 'A redirected reset may still have been accepted.');
        }

        $this->assertNothingReached('169.254.169.254');
    }

    #[Test]
    public function a_cluster_redirect_is_not_followed_off_host(): void
    {
        Http::fake([
            'pve.test:8006/*' => Http::response('', 301, ['Location' => self::METADATA_HOST]),
            '169.254.169.254/*' => Http::response(['data' => []], 200),
        ]);

        try {
            (new ProxmoxComputeProvider(
                new ProxmoxConnection('https://pve.test:8006', 'lynomia@pve!cp', 'a-token-secret'),
                new SecretRedactor,
            ))->startVm('pve-01', '101');

            $this->fail('The adapter accepted a redirect as an answer.');
        } catch (ComputeProviderException $e) {
            $this->assertStringContainsString('redirect', $e->getMessage());
            $this->assertTrue($e->isIndeterminate());
        }

        $this->assertNothingReached('169.254.169.254');
    }

    #[Test]
    public function a_whm_redirect_is_not_followed_off_host(): void
    {
        config(['hosting.credentials.node-a' => ['username' => 'root', 'api_token' => 'a-whm-api-token-value']]);

        Http::fake([
            'node-a.lynomia.test:2087/*' => Http::response('', 307, ['Location' => self::METADATA_HOST]),
            '169.254.169.254/*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'ok']], 200),
        ]);

        try {
            (new CpanelHostingProvider(new SecretRedactor))->suspendAccount($this->whmNode(), 'acme', 'non-payment');

            $this->fail('The adapter accepted a redirect as an answer.');
        } catch (HostingProviderException $e) {
            $this->assertStringContainsString('redirect', $e->getMessage());
            $this->assertTrue($e->isIndeterminate());
        }

        $this->assertNothingReached('169.254.169.254');
    }

    private function assertNothingReached(string $host): void
    {
        Http::assertSent(function (Request $request) use ($host): bool {
            $this->assertStringNotContainsString(
                $host,
                $request->url(),
                'The adapter followed a redirect off the device and fetched an arbitrary internal URL.',
            );

            return true;
        });
    }

    private function whmNode(): HostingNode
    {
        return (new HostingNode)->forceFill([
            'id' => '01JBHOSTINGNODE000000000A',
            'slug' => 'node-a',
            'hostname' => 'node-a.lynomia.test',
            'panel' => HostingPanel::Cpanel,
            'api_endpoint' => 'https://node-a.lynomia.test:2087',
            'credentials_reference' => 'node-a',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);
    }

    private function bmcEndpoint(): BmcEndpoint
    {
        $endpoint = new BmcEndpoint;

        $endpoint->forceFill([
            'protocol' => BmcProtocol::Redfish,
            'address' => '192.0.2.10',
            'port' => 443,
            'username' => 'lynomia-svc',
            'verify_tls' => true,
        ]);

        $endpoint->id = '01JBMCENDPOINT00000000000A';

        return $endpoint;
    }

    private function redfish(): RedfishDedicatedProvider
    {
        return new RedfishDedicatedProvider(
            new BmcConnection(
                endpointId: '01JBMCENDPOINT00000000000A',
                protocol: BmcProtocol::Redfish,
                address: '192.0.2.10',
                port: 443,
                username: 'lynomia-svc',
                password: 'a-real-bmc-password-9f3c',
                verifyTls: true,
                timeoutSeconds: 5,
            ),
            new SecretRedactor,
        );
    }
}
