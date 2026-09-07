<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A list endpoint must cost the same whether it returns one row or fifty.
 *
 * The N+1 query is the defect that does not show up in development, where every
 * account has three invoices, and does show up on the first customer with three
 * hundred — as a page that takes four seconds and a database that is busy doing
 * nothing useful. It is invisible to every other test in this suite, because a
 * test that asserts the JSON is right cannot see how many queries producing it
 * took.
 *
 * The measurement is a comparison, never an absolute. Asserting "at most nine
 * queries" would break every time an eager load is added for good reason and
 * would say nothing about scaling; asserting that the count for fifty rows
 * equals the count for one says exactly the thing that matters, and keeps
 * saying it as the endpoints change.
 */
final class ListEndpointsDoNotQueryPerRowTest extends TestCase
{
    use RefreshDatabase;

    /** How many rows the "many" pass creates. Well inside the default page. */
    private const int MANY = 12;

    /**
     * @return array{0: Customer, 1: User}
     */
    private function account(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }

    private function queriesFor(User $user, string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->getJson($uri)->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  callable(int): void  $create  writes one row, given its index
     */
    private function assertConstantCost(User $user, string $uri, callable $create): void
    {
        $create(0);

        /*
         * One request before the first measurement, discarded. The first call in
         * a process warms caches that have nothing to do with the endpoint — the
         * permission registry among them — and counting that warm-up as part of
         * the one-row cost made the one-row figure larger than the many-row one,
         * which reads as "it got cheaper with more data" and hides an N+1
         * rather than finding one.
         */
        $this->queriesFor($user, $uri);

        $one = $this->queriesFor($user, $uri);

        for ($i = 1; $i < self::MANY; $i++) {
            $create($i);
        }

        $many = $this->queriesFor($user, $uri);

        $this->assertSame(
            $one,
            $many,
            sprintf(
                '%s issued %d queries for 1 row and %d for %d: it queries per row.',
                $uri,
                $one,
                $many,
                self::MANY,
            ),
        );
    }

    #[Test]
    public function the_invoice_list_does_not_query_per_invoice(): void
    {
        [$customer, $user] = $this->account();

        $this->assertConstantCost(
            $user,
            '/api/v1/invoices',
            function () use ($customer): void {
                Invoice::factory()->create(['customer_id' => $customer->id, 'currency' => 'KWD']);
            },
        );
    }

    #[Test]
    public function the_order_list_does_not_query_per_order(): void
    {
        [$customer, $user] = $this->account();

        $this->assertConstantCost(
            $user,
            '/api/v1/orders',
            function () use ($customer): void {
                Order::factory()->create(['customer_id' => $customer->id, 'currency' => 'KWD']);
            },
        );
    }

    #[Test]
    public function the_service_list_does_not_query_per_service(): void
    {
        [$customer, $user] = $this->account();

        $this->assertConstantCost(
            $user,
            '/api/v1/services',
            function () use ($customer): void {
                Service::factory()->active()->create(['customer_id' => $customer->id]);
            },
        );
    }

    #[Test]
    public function the_subscription_list_does_not_query_per_subscription(): void
    {
        [$customer, $user] = $this->account();

        $this->assertConstantCost(
            $user,
            '/api/v1/subscriptions',
            function () use ($customer): void {
                Subscription::factory()->create(['customer_id' => $customer->id]);
            },
        );
    }

    #[Test]
    public function the_vps_list_does_not_query_per_machine(): void
    {
        [$customer, $user] = $this->account();
        $node = ComputeNode::factory()->create();

        $this->assertConstantCost(
            $user,
            '/api/v1/vps',
            function () use ($customer, $node): void {
                $service = Service::factory()->active()->create([
                    'customer_id' => $customer->id,
                    'kind' => 'vps',
                ]);

                VirtualMachine::factory()
                    ->onNode($node)
                    ->forService($service)
                    ->create();
            },
        );
    }

    #[Test]
    public function the_address_list_does_not_query_per_address(): void
    {
        [$customer, $user] = $this->account();

        $this->assertConstantCost(
            $user,
            '/api/v1/ips',
            function () use ($customer): void {
                IpAssignment::factory()->create(['customer_id' => $customer->id]);
            },
        );
    }

    #[Test]
    public function the_dedicated_list_does_not_query_per_server(): void
    {
        [$customer, $user] = $this->account();

        $this->assertConstantCost(
            $user,
            '/api/v1/dedicated',
            function () use ($customer): void {
                $service = Service::factory()->active()->create([
                    'customer_id' => $customer->id,
                    'kind' => 'dedicated',
                ]);

                DedicatedServer::factory()->create(['service_id' => $service->id]);
            },
        );
    }

    #[Test]
    public function the_hosting_list_does_not_query_per_account(): void
    {
        [$customer, $user] = $this->account();

        $this->assertConstantCost(
            $user,
            '/api/v1/hosting',
            function () use ($customer): void {
                $service = Service::factory()->active()->create([
                    'customer_id' => $customer->id,
                    'kind' => 'shared_hosting',
                ]);

                HostingAccount::factory()->create([
                    'customer_id' => $customer->id,
                    'service_id' => $service->id,
                ]);
            },
        );
    }

    private function operator(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }

    #[Test]
    public function the_admin_customer_list_does_not_query_per_customer(): void
    {
        $operator = $this->operator();

        $this->assertConstantCost(
            $operator,
            '/api/admin/customers',
            function (): void {
                Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
            },
        );
    }

    #[Test]
    public function the_admin_transaction_list_does_not_query_per_transaction(): void
    {
        $operator = $this->operator();
        [$customer] = $this->account();

        $this->assertConstantCost(
            $operator,
            '/api/admin/transactions',
            function () use ($customer): void {
                Transaction::factory()->create(['customer_id' => $customer->id]);
            },
        );
    }

    #[Test]
    public function the_provisioning_queue_does_not_query_per_job(): void
    {
        $operator = $this->operator();
        [$customer] = $this->account();

        $this->assertConstantCost(
            $operator,
            '/api/admin/provisioning/jobs',
            function () use ($customer): void {
                $service = Service::factory()->active()->create(['customer_id' => $customer->id]);

                ProvisioningJob::factory()->create(['service_id' => $service->id]);
            },
        );
    }

    #[Test]
    public function the_node_list_does_not_query_per_node(): void
    {
        $operator = $this->operator();

        $this->assertConstantCost(
            $operator,
            '/api/admin/infrastructure/nodes',
            function (): void {
                ComputeNode::factory()->create();
            },
        );
    }
}
