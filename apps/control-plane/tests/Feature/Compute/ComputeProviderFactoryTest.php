<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Domain\Exceptions\ClusterNotConfiguredException;
use Lynomia\Modules\Compute\Domain\Exceptions\FakeProviderInProductionException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxComputeProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which adapter a cluster is driven through, and where its credential comes
 * from.
 *
 * The signature takes a cluster rather than a driver name on purpose: every
 * cluster has its own endpoint, certificate policy and token, so resolving
 * "the Proxmox provider" without saying which one would make the one mistake
 * that matters — an operation aimed at the wrong cluster — expressible.
 */
final class ComputeProviderFactoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_fake_cluster_resolves_the_fake_adapter(): void
    {
        $provider = $this->factory()->for(ComputeCluster::factory()->create());

        $this->assertInstanceOf(FakeComputeProvider::class, $provider);
        $this->assertSame('fake', $provider->name());
    }

    #[Test]
    public function a_fake_cluster_is_refused_in_production(): void
    {
        $cluster = ComputeCluster::factory()->create();

        $this->app->detectEnvironment(fn (): string => 'production');

        // The cluster row is the path config inspection cannot see: the
        // platform can be configured for Proxmox everywhere and still have one
        // row left on the fake.
        $this->expectException(FakeProviderInProductionException::class);

        $this->factory()->for($cluster);
    }

    #[Test]
    public function a_proxmox_cluster_resolves_an_adapter_pointed_at_its_own_endpoint(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        config()->set('compute.credentials.kw-cluster', [
            'token_id' => 'lynomia@pve!control-plane',
            'token_secret' => 'b7f3c1de-4a2e-4f0c',
        ]);

        $cluster = ComputeCluster::factory()->proxmox('kw-cluster', 'https://pve-kw.test:8006')->create();

        $provider = $this->factory()->for($cluster);
        $this->assertInstanceOf(ProxmoxComputeProvider::class, $provider);

        $provider->listNodes();

        Http::assertSent(fn (Request $request): bool => str_starts_with(
            $request->url(),
            'https://pve-kw.test:8006/api2/json/nodes',
        ));
    }

    #[Test]
    public function a_cluster_whose_credentials_were_never_deployed_is_refused_by_name(): void
    {
        $cluster = ComputeCluster::factory()->proxmox('not-deployed-yet')->create();

        try {
            $this->factory()->for($cluster);

            $this->fail('An adapter was built with no credentials.');
        } catch (ClusterNotConfiguredException $e) {
            $this->assertSame('compute.cluster_not_configured', $e->errorCode());
            // Naming the missing key is the difference between a five-minute
            // fix and an afternoon spent believing the cluster revoked our
            // access, which is what an empty token looks like from the logs.
            $this->assertSame('not-deployed-yet', $e->context()['credentials_reference']);
        }
    }

    #[Test]
    public function a_cluster_with_no_endpoint_is_refused(): void
    {
        config()->set('compute.credentials.test-cluster', [
            'token_id' => 'lynomia@pve!control-plane',
            'token_secret' => 'b7f3c1de-4a2e-4f0c',
        ]);

        $cluster = ComputeCluster::factory()->proxmox()->create(['api_endpoint' => null]);

        $this->expectException(ClusterNotConfiguredException::class);

        $this->factory()->for($cluster);
    }

    #[Test]
    public function the_adapter_for_one_cluster_is_built_once(): void
    {
        $factory = $this->factory();
        $cluster = ComputeCluster::factory()->create();
        $other = ComputeCluster::factory()->create();

        // An inventory sync makes several calls; rebuilding the HTTP stack for
        // each would be pure waste.
        $this->assertSame($factory->for($cluster), $factory->for($cluster));
        $this->assertNotSame($factory->for($cluster), $factory->for($other));
    }

    #[Test]
    public function an_adapter_can_be_swapped_for_one_that_misbehaves(): void
    {
        $factory = $this->factory();
        $cluster = ComputeCluster::factory()->create();

        $double = new FakeComputeProvider;
        $factory->swap($cluster, $double);

        $this->assertSame($double, $factory->for($cluster));
    }

    private function factory(): ComputeProviderFactory
    {
        return app(ComputeProviderFactory::class);
    }
}
