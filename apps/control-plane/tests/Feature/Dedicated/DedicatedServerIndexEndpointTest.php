<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Http\Requests\ListDedicatedServersRequest;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/dedicated.
 *
 * The acting customer's machines, and nobody else's — including nobody else's
 * by accident, which on this table is a real possibility: `customer_id` is not
 * cleared when a machine is wiped and returned to stock.
 */
final class DedicatedServerIndexEndpointTest extends DedicatedApiTestCase
{
    #[Test]
    public function it_lists_the_acting_customers_machines(): void
    {
        [$customer, $user] = $this->accountWith();

        $mine = $this->serverFor($customer, ['serial' => 'SNMINE00001']);

        $this->actingAs($user)
            ->getJson('/api/v1/dedicated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.serial', 'SNMINE00001')
            ->assertJsonPath('data.0.status', DedicatedServerStatus::Active->value)
            ->assertJsonPath('data.0.power_state', 'on')
            ->assertJsonPath('data.0.is_powered_on', true);
    }

    #[Test]
    public function another_customers_machine_is_not_in_the_list(): void
    {
        [$mine, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $this->serverFor($mine);
        $hers = $this->serverFor($theirs);

        $response = $this->actingAs($user)->getJson('/api/v1/dedicated')->assertOk();

        $this->assertNotContains($hers->id, array_column((array) $response->json('data'), 'id'));
        $response->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_machine_that_has_been_wiped_and_returned_to_stock_is_gone_from_the_list(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer);

        /*
         * The lifecycle is active → maintenance → available, and only the next
         * reservation overwrites customer_id. Between the wipe and the next
         * sale the row still names this customer, and a scope of
         * "customer_id = me" alone would keep showing them a machine that is
         * back on the shelf — and let them power cycle it while an operator is
         * standing in front of it.
         */
        $server->forceFill([
            'status' => DedicatedServerStatus::Available,
            'service_id' => null,
        ])->save();

        $this->assertSame($customer->id, $server->refresh()->customer_id);

        $this->actingAs($user)
            ->getJson('/api/v1/dedicated')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_machine_under_maintenance_is_still_the_customers_and_still_listed(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer, ['status' => DedicatedServerStatus::Maintenance]);

        // Refusing to show it would leave the customer with no way to find out
        // why their server stopped answering.
        $this->actingAs($user)
            ->getJson('/api/v1/dedicated')
            ->assertOk()
            ->assertJsonPath('data.0.id', $server->id)
            ->assertJsonPath('data.0.status', DedicatedServerStatus::Maintenance->value);
    }

    #[Test]
    public function the_page_size_is_bounded_however_large_the_request(): void
    {
        [$customer, $user] = $this->accountWith();

        foreach (range(1, 3) as $ignored) {
            $this->serverFor($customer);
        }

        $response = $this->actingAs($user)
            ->getJson('/api/v1/dedicated?per_page=100000')
            ->assertOk();

        // Clamped rather than refused: "as many as I can have" is answered
        // with the ceiling, and the query never sees the number asked for.
        $this->assertSame(ListDedicatedServersRequest::MAX_PER_PAGE, $response->json('meta.per_page'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(ListDedicatedServersRequest::MAX_PER_PAGE, $response->json('meta.max_per_page'));
    }

    #[Test]
    public function a_nonsense_page_size_falls_back_rather_than_walking_the_collection_one_row_at_a_time(): void
    {
        [$customer, $user] = $this->accountWith();
        $this->serverFor($customer);

        $response = $this->actingAs($user)->getJson('/api/v1/dedicated?per_page=0')->assertOk();

        $this->assertSame(25, $response->json('meta.per_page'));
    }

    #[Test]
    public function a_page_size_that_is_not_a_number_is_a_422_naming_the_field(): void
    {
        [, $user] = $this->accountWith();

        $this->actingAs($user)
            ->getJson('/api/v1/dedicated?per_page=all')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['per_page']]]]);
    }

    #[Test]
    public function nothing_about_the_platforms_own_estate_is_in_the_list(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer);
        $server->forceFill([
            'notes' => 'PSU 2 replaced by hand on the night shift',
            'rack_unit' => 21,
            'last_seen_at' => now(),
        ])->save();

        $endpoint = $server->bmcEndpoints()->firstOrFail();

        $response = $this->actingAs($user)->getJson('/api/v1/dedicated')->assertOk();

        /** @var array<string, mixed> $document */
        $document = ((array) $response->json('data'))[0];

        foreach ([
            'datacenter_id',
            'rack_id',
            'rack_unit',
            'height_units',
            'customer_id',
            'notes',
            'last_seen_at',
            'reserved_until',
            'reserved_by_order_id',
            'retired_at',
            'bmc_endpoints',
            'bmc',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $document, $field.' is in the customer document');
        }

        // The controller's address and the name of the configuration key its
        // password is read from must not appear anywhere in the body, however
        // it got there.
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($endpoint->address, $body);
        $this->assertStringNotContainsString('bmc-secret-key-name', $body);
        $this->assertStringNotContainsString('PSU 2 replaced', $body);
    }

    #[Test]
    public function a_member_who_may_only_watch_the_account_can_still_read_the_list(): void
    {
        [$customer, $user] = $this->accountWith(CustomerRole::Member);
        $this->serverFor($customer);

        $this->actingAs($user)->getJson('/api/v1/dedicated')->assertOk();
    }

    #[Test]
    public function an_unauthenticated_caller_gets_nothing(): void
    {
        $this->getJson('/api/v1/dedicated')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'auth.unauthenticated');
    }

    #[Test]
    public function machines_that_belong_to_nobody_are_not_listed_for_anybody(): void
    {
        [, $user] = $this->accountWith();

        // Stock: racked, sellable, and with no customer on the row at all.
        DedicatedServer::factory()->create(['status' => DedicatedServerStatus::Available]);

        $this->actingAs($user)
            ->getJson('/api/v1/dedicated')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
