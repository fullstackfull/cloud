<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The administrative surface.
 *
 * It authenticates with the same guard as the customer API, which means the
 * prefix protects nothing: what separates the two is the permission on each
 * route. These tests exercise that from both sides — a verified customer must
 * be refused everywhere, and an operator must be refused everywhere they lack
 * the specific grant rather than everywhere they lack a role.
 */
final class AdminSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * @return list<array{string, string}>
     */
    private function everyAdminRoute(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            $method = $route->methods()[0] ?? 'GET';
            $routes[] = [$method, '/'.str_replace(['{customer}', '{transaction}'], '01jexampleexampleexample00', $route->uri())];
        }

        return $routes;
    }

    #[Test]
    public function a_verified_customer_is_refused_on_every_administrative_route(): void
    {
        $customer = User::factory()->create();
        $customer->syncRoles([Role::Customer->value]);

        $routes = $this->everyAdminRoute();
        $this->assertNotEmpty($routes, 'No administrative routes were found to test.');

        foreach ($routes as [$method, $uri]) {
            $response = $this->actingAs($customer)->json($method, $uri);

            // 403, never 404 and never 200: a customer must not be able to tell
            // an administrative endpoint that exists from one that does not,
            // and must certainly not reach one.
            $response->assertStatus(403);
            $response->assertJsonPath('error.code', 'auth.forbidden');
        }
    }

    #[Test]
    public function an_operator_without_the_specific_grant_is_refused_that_route(): void
    {
        // Support can read customers and cannot refund money. The distinction
        // is per-permission, not per-role: a role is a bundle, and what the
        // route checks is one item in it.
        $user = User::factory()->create();
        $user->syncRoles([Role::Support->value]);

        $this->assertTrue($user->can(Permission::CustomerViewAny->value));
        $this->assertFalse($user->can(Permission::PaymentRefund->value));

        $this->actingAs($user)->getJson('/api/admin/customers')->assertOk();

        $this->actingAs($user)
            ->postJson('/api/admin/transactions/01jexampleexampleexample00/refunds', [
                'amount_minor' => 100,
                'reason' => 'goodwill',
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function an_operator_sees_every_account_and_not_only_their_own(): void
    {
        Customer::factory()->create(['display_name' => 'First Customer']);
        Customer::factory()->create(['display_name' => 'Second Customer']);

        // The whole difference between this surface and /api/v1: no tenant
        // scope, because an operator answering a ticket needs the account that
        // raised it.
        $this->actingAs($this->operator())
            ->getJson('/api/admin/customers')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function searching_for_a_literal_wildcard_finds_the_text_rather_than_everything(): void
    {
        Customer::factory()->create(['display_name' => 'Ordinary Company']);
        Customer::factory()->create(['display_name' => '100% Cotton Ltd']);

        $this->actingAs($this->operator())
            ->getJson('/api/admin/customers?q='.urlencode('100%'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.display_name', '100% Cotton Ltd');
    }

    #[Test]
    public function suspending_an_account_requires_a_reason_and_records_the_new_status(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);

        $this->actingAs($this->operator())
            ->putJson("/api/admin/customers/{$customer->id}/status", ['status' => 'suspended'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed');

        $this->actingAs($this->operator())
            ->putJson("/api/admin/customers/{$customer->id}/status", [
                'status' => 'suspended',
                'reason' => 'chargeback under investigation',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertSame(CustomerStatus::Suspended, $customer->fresh()->status);
    }

    #[Test]
    public function the_hosting_node_screen_never_publishes_where_its_credentials_live(): void
    {
        HostingNode::factory()->create([
            'api_endpoint' => 'https://shared-1.example.test:2087',
            'credentials_reference' => 'vault://hosting/shared-1',
        ]);

        $body = $this->actingAs($this->operator())
            ->getJson('/api/admin/infrastructure/hosting-nodes')
            ->assertOk()
            ->getContent();

        // An operator screen has no use for either, and a support tool that
        // displays them is a support tool that puts them in a screenshot.
        $this->assertStringNotContainsString('credentials_reference', (string) $body);
        $this->assertStringNotContainsString('vault://', (string) $body);
        $this->assertStringNotContainsString('api_endpoint', (string) $body);
        $this->assertStringNotContainsString(':2087', (string) $body);
    }

    #[Test]
    public function a_transaction_listing_does_not_publish_the_providers_raw_payload(): void
    {
        Transaction::factory()->create([
            'status' => TransactionStatus::Succeeded,
            'provider_metadata' => ['api_key' => 'must-not-appear', 'reason' => 'ok'],
        ]);

        $body = $this->actingAs($this->operator())
            ->getJson('/api/admin/transactions')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('provider_metadata', (string) $body);
        $this->assertStringNotContainsString('must-not-appear', (string) $body);
    }

    #[Test]
    public function a_refund_is_recorded_against_the_capture_and_names_who_issued_it(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD']);
        $invoice = Invoice::factory()->for($customer)->totalling(Money::of('10.000', 'KWD'))->create([
            'status' => InvoiceStatus::Paid,
            'amount_paid_minor' => 10000,
        ]);

        $capture = Transaction::factory()->create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 10000,
            'currency' => 'KWD',
        ]);

        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson("/api/admin/transactions/{$capture->id}/refunds", [
                'amount_minor' => 2500,
                'reason' => 'partial service outage',
            ])
            ->assertCreated()
            ->assertJsonPath('data.amount.minor_units', 2500)
            ->assertJsonPath('data.amount.currency', 'KWD');

        $refund = Refund::query()->sole();

        // "Who refunded this?" has to survive the operator leaving.
        $this->assertSame($operator->id, $refund->issued_by_user_id);
    }

    #[Test]
    public function only_a_succeeded_capture_can_be_refunded(): void
    {
        $pending = Transaction::factory()->create([
            'status' => TransactionStatus::Pending,
            'amount_minor' => 10000,
            'currency' => 'KWD',
        ]);

        $this->actingAs($this->operator())
            ->postJson("/api/admin/transactions/{$pending->id}/refunds", [
                'amount_minor' => 1000,
                'reason' => 'too early',
            ])
            ->assertStatus(422);

        $this->assertSame(0, Refund::query()->count());
    }

    #[Test]
    public function a_page_size_beyond_the_ceiling_is_clamped_rather_than_obeyed(): void
    {
        Customer::factory()->count(3)->create();

        $this->actingAs($this->operator())
            ->getJson('/api/admin/customers?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.max_per_page', 100);
    }
}
