<?php

declare(strict_types=1);

namespace Tests\Unit\Compute;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * The Proxmox adapter keeps the occupied-id contract — on an assumed answer.
 *
 * The cluster below is a stand-in written for this test, and the one thing it
 * is not entitled to claim is how a real cluster words or codes the refusal:
 * that is unestablished here (see the contract's docblock). It answers a
 * create at a held id with a 500 and an `errors` body, which is a refusal
 * outside the statuses the adapter reads as "no answer" (502, 503, 504, 408,
 * 429). What the arm pins is the adapter's translation of such a refusal —
 * determinate, and never an operation — so a change that read it as success
 * or as an unknown outcome is red here beside the simulator's arm.
 */
final class TheProxmoxAdapterSurfacesAnOccupiedIdTest extends ACreateAtAnOccupiedIdContractTestCase
{
    /** @var array<string, array{name: string, vcpu: int}> */
    private array $held = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && preg_match('#/nodes/[^/]+/qemu$#', parse_url($request->url(), PHP_URL_PATH) ?: '') === 1) {
                $vmId = (string) ($request->data()['vmid'] ?? '');

                if (isset($this->held[$vmId])) {
                    // ASSUMED wording and status; see the class docblock.
                    return Http::response(['data' => null, 'errors' => ['vmid' => 'the id is already in use (assumed answer)']], 500);
                }

                return Http::response(['data' => 'UPID:pve-01:0000A1B2:00C3D4E5:65F0A1B2:qmcreate:'.$vmId.':lynomia@pve!control-plane:']);
            }

            if (preg_match('#/nodes/([^/]+)/qemu/(\d+)/status/current$#', parse_url($request->url(), PHP_URL_PATH) ?: '', $m) === 1) {
                $machine = $this->held[$m[2]] ?? null;

                return $machine === null
                    ? Http::response(['data' => null], 404)
                    : Http::response(['data' => ['name' => $machine['name'], 'cpus' => $machine['vcpu'], 'status' => 'running']]);
            }

            return Http::response(['data' => []]);
        });
    }

    protected function provider(): ComputeProvider
    {
        return new ProxmoxComputeProvider(
            new ProxmoxConnection('https://pve.test:8006', 'lynomia@pve!control-plane', 'b7f3c1de-4a2e-4f0c-9f77-0c1d2e3f4a5b'),
            new SecretRedactor,
        );
    }

    protected function occupy(string $nodeName, int $vmId, string $name, int $vcpu): void
    {
        $this->held[(string) $vmId] = ['name' => $name, 'vcpu' => $vcpu];
    }
}
