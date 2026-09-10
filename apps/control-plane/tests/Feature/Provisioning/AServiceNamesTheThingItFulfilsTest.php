<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;

/**
 * A service says what it is, and where the thing it fulfils lives.
 *
 * The audit found the services index next to useless: every row carried the
 * catalogue's label — "Cloud VPS — Starter" — which is the same string for
 * every customer who bought that plan, so two servers on one plan were two
 * identical rows, and no row led anywhere.
 *
 * Wave 3 publishes two facts that fix both halves. `identity` is the name the
 * customer knows the thing by. `resource` is `{kind, id}` for the row that
 * fulfils the service — and the id in it is the machine's, the account's or
 * the chassis's, never the service's, because a client that built a link from
 * a service id would send somebody to a page for a resource that does not
 * exist.
 *
 * Both are resolved in one query per family for a whole page, which is the
 * other thing asserted here: a page of twenty-five services must not become
 * twenty-five lookups.
 */
final class AServiceNamesTheThingItFulfilsTest extends ServiceApiTestCase
{
    #[Test]
    public function a_machine_service_carries_the_hostname_and_the_machines_own_id(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer, ['kind' => 'vps', 'status' => ServiceStatus::Active]);
        $machine = $this->machineOn($service, 'web-kw-01');

        $this->actingAs($user)
            ->getJson('/api/v1/services/'.$service->id)
            ->assertOk()
            ->assertJsonPath('data.identity', 'web-kw-01')
            ->assertJsonPath('data.resource.kind', 'vps')
            // The machine's id, and demonstrably not the service's.
            ->assertJsonPath('data.resource.id', (string) $machine->getKey())
            ->assertJsonPath('data.id', (string) $service->getKey());

        $this->assertNotSame((string) $machine->getKey(), (string) $service->getKey());
    }

    #[Test]
    public function each_family_reports_the_word_the_portal_addresses_it_by(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $vps = $this->serviceFor($customer, ['kind' => 'vps', 'status' => ServiceStatus::Active]);
        $this->machineOn($vps, 'web-kw-02');

        $hosting = $this->serviceFor($customer, ['kind' => 'shared_hosting', 'status' => ServiceStatus::Active]);
        $account = $this->hostingAccountOn($hosting, $customer, 'shop.test');

        $dedicated = $this->serviceFor($customer, ['kind' => 'dedicated', 'status' => ServiceStatus::Active]);
        $chassis = DedicatedServer::factory()->create([
            'service_id' => $dedicated->getKey(),
            'customer_id' => $customer->getKey(),
            'serial' => 'SN-TEST-0001',
        ]);

        $rows = collect($this->actingAs($user)->getJson('/api/v1/services')->assertOk()->json('data'))
            ->keyBy('id');

        /*
         * The customer-facing family, not the fulfilling module's name: the
         * portal's own addresses are /vps, /hosting and /dedicated, and the
         * client owns the map from a kind to a route.
         */
        $this->assertSame('vps', $rows[$vps->getKey()]['resource']['kind']);

        $this->assertSame('hosting', $rows[$hosting->getKey()]['resource']['kind']);
        $this->assertSame((string) $account->getKey(), $rows[$hosting->getKey()]['resource']['id']);
        $this->assertSame('shop.test', $rows[$hosting->getKey()]['identity']);

        $this->assertSame('dedicated', $rows[$dedicated->getKey()]['resource']['kind']);
        $this->assertSame((string) $chassis->getKey(), $rows[$dedicated->getKey()]['resource']['id']);
        $this->assertSame('SN-TEST-0001', $rows[$dedicated->getKey()]['identity']);
    }

    #[Test]
    public function a_service_with_nothing_built_yet_says_so_rather_than_inventing_a_name(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // Ordered, paid, and still being provisioned: there is no machine.
        $service = $this->serviceFor($customer, [
            'kind' => 'vps',
            'status' => ServiceStatus::Provisioning,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/services/'.$service->id)
            ->assertOk()
            ->assertJsonPath('data.identity', null)
            // Null rather than a handle pointing at nothing: a client renders
            // "being created" and offers no link, because there is nothing to
            // open.
            ->assertJsonPath('data.resource', null);
    }

    #[Test]
    public function a_page_of_services_costs_one_lookup_per_family_and_not_one_per_row(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        for ($i = 0; $i < 12; $i++) {
            $service = $this->serviceFor($customer, ['kind' => 'vps', 'status' => ServiceStatus::Active]);
            $this->machineOn($service, sprintf('web-kw-%02d', $i));
        }

        // Warm anything the framework resolves once — the session, the acting
        // customer — so the count below is the page's own cost.
        $this->actingAs($user)->getJson('/api/v1/services')->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->actingAs($user)->getJson('/api/v1/services')->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(12, $response->json('data'));

        /*
         * The bound is deliberately loose — authentication and the acting
         * customer are in here too — and the point is the shape rather than
         * the number: twelve machines must not be twelve lookups, and the
         * identities and handles arrive in one query per family for the whole
         * page. It fails loudly if anybody makes this per-row.
         */
        $this->assertLessThan(
            20,
            count($queries),
            'A page of services is issuing a query per row: '.count($queries).' queries for 12 rows.',
        );

        // And every row still carries its own name and its own handle.
        foreach ($response->json('data') as $row) {
            $this->assertIsString($row['identity']);
            $this->assertSame('vps', $row['resource']['kind']);
        }
    }

    private function machineOn(Service $service, string $hostname): VirtualMachine
    {
        $node = ComputeNode::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->id,
        ]);

        return VirtualMachine::factory()
            ->onNode($node)
            ->forService($service)
            ->create(['hostname' => $hostname]);
    }

    private function hostingAccountOn(Service $service, Customer $customer, string $domain): HostingAccount
    {
        return HostingAccount::factory()->create([
            'service_id' => $service->getKey(),
            'customer_id' => $customer->getKey(),
            'hosting_node_id' => HostingNode::factory()->create()->getKey(),
            'hosting_package_id' => HostingPackage::factory()->create()->getKey(),
            'primary_domain' => $domain,
        ]);
    }
}
