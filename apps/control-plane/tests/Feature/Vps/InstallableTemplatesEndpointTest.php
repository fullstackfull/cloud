<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/vps/{vm}/templates.
 *
 * The reinstall endpoint has accepted a `template_id` since the day it was
 * written, and until Wave 3 there was no way for a customer to find out what
 * one looked like: the only list lived in a private method of the controller
 * and the portal offered no choice at all. A customer who wanted a different
 * operating system opened a ticket.
 *
 * The property that matters is not that the list exists. It is that the list
 * and the acceptance are the same predicate: a screen that offered an image
 * this machine cannot be built from would be offering a rebuild the next
 * request refuses, and a portal that hard-coded "Ubuntu 24.04" because Ubuntu
 * is popular would be offering one the platform has never staged.
 */
final class InstallableTemplatesEndpointTest extends VpsApiTestCase
{
    #[Test]
    public function it_lists_the_images_this_machine_could_actually_be_built_from(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        // On this machine's own cluster, and staged: offered.
        $onThisCluster = VmTemplate::factory()->create(['cluster_id' => $machine->cluster_id]);

        // Staged fleet-wide rather than on one cluster: legitimately usable
        // anywhere, so also offered.
        $fleetWide = VmTemplate::factory()->create([
            'cluster_id' => null,
            'provider_reference' => 'local:import/alma-9.qcow2',
        ]);

        // Real catalogue entries that this machine cannot be built from.
        $otherCluster = VmTemplate::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->id,
        ]);
        $retired = VmTemplate::factory()->inactive()->create(['cluster_id' => $machine->cluster_id]);
        $neverStaged = VmTemplate::factory()->unstaged()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/templates')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        sort($ids);
        $expected = [(string) $onThisCluster->id, (string) $fleetWide->id];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains((string) $otherCluster->id, $ids);
        $this->assertNotContains((string) $retired->id, $ids);
        $this->assertNotContains((string) $neverStaged->id, $ids);
        $this->assertSame(2, $response->json('meta.total'));
    }

    #[Test]
    public function nothing_about_the_estate_is_in_a_listed_image(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        VmTemplate::factory()->create(['cluster_id' => $machine->cluster_id]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/templates')
            ->assertOk();

        /** @var array<string, mixed> $template */
        $template = $response->json('data.0');

        // The exact key set. A resource that grew a `parent::toArray()` would
        // publish the provider's handle for the image and the cluster it is
        // staged on, neither of which is the customer's business.
        $this->assertSame([
            'id', 'name', 'os_family', 'os_version', 'architecture',
            'supports_ssh_keys', 'requires_licence',
        ], array_keys($template));

        $body = $response->getContent();
        $this->assertIsString($body);

        foreach (['local:import', 'cluster_id', 'provider_reference', 'checksum'] as $operational) {
            $this->assertStringNotContainsString($operational, $body);
        }
    }

    #[Test]
    public function the_image_it_offers_is_the_image_the_rebuild_accepts(): void
    {
        Queue::fake();

        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        $offered = VmTemplate::factory()->create(['cluster_id' => $machine->cluster_id]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-with-the-offered-image')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', [
                'confirm_hostname' => 'web-01',
                'template_id' => (string) $offered->id,
            ])
            ->assertStatus(202);
    }

    #[Test]
    public function an_image_it_does_not_offer_is_refused_even_though_it_exists(): void
    {
        Queue::fake();

        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        // A real, active, staged image — on somebody else's hardware.
        $elsewhere = VmTemplate::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->id,
        ]);

        /*
         * 404, and the same 404 an id that does not exist at all would get:
         * template ids are ULIDs too, and a client that could tell "not yours"
         * from "not real" could enumerate the fleet's image catalogue.
         */
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-with-a-foreign-image')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', [
                'confirm_hostname' => 'web-01',
                'template_id' => (string) $elsewhere->id,
            ])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function another_accounts_machine_has_no_image_list(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $this->machineFor($mine);
        $someoneElses = $this->machineFor($theirs);

        // Not found rather than forbidden: the scoped query starts from the
        // acting customer, so another tenant's id is never fetched at all.
        $this->actingAs($me)
            ->getJson('/api/v1/vps/'.$someoneElses->id.'/templates')
            ->assertNotFound();
    }

    #[Test]
    public function reading_the_list_needs_only_permission_to_look(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        VmTemplate::factory()->create(['cluster_id' => $machine->cluster_id]);

        // The read-only tier: it may look at the account's servers, so it may
        // see what they could be rebuilt with. Asking for the rebuild is the
        // managed action, and this role cannot.
        $viewer = $this->memberOf($customer, CustomerRole::Member);

        $this->actingAs($viewer)
            ->getJson('/api/v1/vps/'.$machine->id.'/templates')
            ->assertOk();

        $this->actingAs($owner)
            ->getJson('/api/v1/vps/'.$machine->id.'/templates')
            ->assertOk();
    }
}
