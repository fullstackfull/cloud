<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dates a customer sees are the customer's dates.
 *
 * A dedicated_servers row is stock. It exists before anybody buys it, and it
 * survives being wiped and re-sold. Publishing its own created_at gives a client
 * something it will label "created" and a customer will read as "when I got this
 * machine" — and on a re-sold chassis that is neither. It is how long the
 * platform has had the hardware, which is the platform's business.
 */
final class ServerDatesAreTheCustomersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_server_publishes_the_delivery_date_and_not_the_racking_date(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $racked = now()->subYears(3);
        $delivered = now()->subDays(9);

        $service = Service::query()->create([
            'customer_id' => $customer->id,
            'kind' => 'dedicated',
            'status' => ServiceStatus::Active,
            'resources' => [],
            'activated_at' => $delivered,
        ]);

        $server = DedicatedServer::factory()->create([
            'datacenter_id' => Datacenter::factory()->create()->id,
            'customer_id' => $customer->id,
            'status' => DedicatedServerStatus::Active,
            'service_id' => $service->id,
            'created_at' => $racked,
            'updated_at' => $racked,
        ]);

        $body = $this->actingAs($user)
            ->getJson('/api/v1/dedicated/'.$server->id)
            ->assertOk()
            ->json('data');

        $this->assertSame($delivered->toIso8601String(), $body['activated_at']);

        // And the inventory age is not in the payload under any name.
        $this->assertArrayNotHasKey('created_at', $body);
        $this->assertStringNotContainsString(
            (string) $racked->year,
            json_encode($body, JSON_THROW_ON_ERROR),
            'The chassis racking date is still reachable in the response.',
        );
    }
}
