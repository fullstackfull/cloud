<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/dedicated/{server}.
 *
 * One machine and the parts inside it. The id in the path is the whole risk on
 * this endpoint, so most of what follows is about what happens when it names
 * somebody else's chassis.
 */
final class DedicatedServerShowEndpointTest extends DedicatedApiTestCase
{
    #[Test]
    public function it_returns_one_of_the_acting_customers_machines(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer, [
            'serial' => 'SNSHOW00001',
            'manufacturer' => 'HPE',
            'model' => 'ProLiant DL360 Gen10',
            'hardware_profile' => 'ded-epyc-64',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/dedicated/{$server->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $server->id)
            ->assertJsonPath('data.serial', 'SNSHOW00001')
            ->assertJsonPath('data.manufacturer', 'HPE')
            ->assertJsonPath('data.model', 'ProLiant DL360 Gen10')
            ->assertJsonPath('data.status', DedicatedServerStatus::Active->value)
            ->assertJsonPath('data.service_id', $server->service_id);
    }

    #[Test]
    public function the_parts_inside_the_machine_come_with_it(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer);

        ServerComponent::factory()->forServer($server)->create([
            'kind' => ComponentKind::Disk,
            'model' => 'INTEL SSDSC2KB960G8',
            'health' => ComponentHealth::Critical,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/dedicated/{$server->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.components')
            ->assertJsonPath('data.components.0.kind', 'disk')
            ->assertJsonPath('data.components.0.health', 'critical');

        // The single most useful thing a hardware API can say, and it is
        // asked of the enum that decides rather than recomputed here.
        $this->assertTrue($response->json('data.components.0.needs_attention'));
    }

    #[Test]
    public function another_customers_machine_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $hers = $this->serverFor($theirs);

        // 403 would confirm the row exists, which on ULIDs is an enumeration
        // oracle over every machine the platform owns.
        $this->actingAs($user)
            ->getJson("/api/v1/dedicated/{$hers->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function a_real_machine_and_an_invented_id_are_refused_identically(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $real = $this->actingAs($user)->getJson('/api/v1/dedicated/'.$this->serverFor($theirs)->id);
        $invented = $this->actingAs($user)->getJson('/api/v1/dedicated/01JZZZZZZZZZZZZZZZZZZZZZZZ');

        // Byte for byte the same, or the difference sorts real ids from
        // invented ones.
        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function nothing_about_the_platforms_own_infrastructure_is_in_the_document(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer);
        $server->forceFill(['notes' => 'Rack B12 U21, PDU port 7', 'rack_unit' => 21])->save();

        $endpoint = $server->bmcEndpoints()->firstOrFail();
        $endpoint->forceFill([
            'firmware_version' => 'iLO5 2.60',
            'last_error' => 'iLO at 192.0.2.77 refused the Basic credential for svc-lynomia',
        ])->save();

        ServerComponent::factory()->forServer($server)->nic('aa:bb:cc:dd:ee:ff')->create([
            'serial' => 'NICSERIAL123',
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/dedicated/{$server->id}")->assertOk();

        /** @var array<string, mixed> $document */
        $document = (array) $response->json('data');

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
        ] as $field) {
            $this->assertArrayNotHasKey($field, $document, $field.' is in the customer document');
        }

        /** @var array<string, mixed> $component */
        $component = ((array) $response->json('data.components'))[0];

        foreach (['serial', 'attributes', 'dedicated_server_id', 'health_checked_at'] as $field) {
            $this->assertArrayNotHasKey($field, $component, $field.' is in the component document');
        }

        $body = $response->getContent();
        $this->assertIsString($body);

        foreach ([
            // The management address, the key its password is read from, the
            // controller's firmware level, whatever it last said, the NIC the
            // provisioning VLAN authorises installs against, and an engineer's
            // note about where the machine physically is.
            $endpoint->address,
            'bmc-secret-key-name',
            'iLO5 2.60',
            'refused the Basic credential',
            'aa:bb:cc:dd:ee:ff',
            'Rack B12',
            'NICSERIAL123',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret.' leaked into the response');
        }
    }

    #[Test]
    public function a_machine_that_has_gone_back_into_stock_is_a_404_for_its_previous_customer(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer);

        $this->actingAs($user)->getJson("/api/v1/dedicated/{$server->id}")->assertOk();

        $server->forceFill(['status' => DedicatedServerStatus::Available, 'service_id' => null])->save();

        // customer_id still names them; the machine is not theirs any more.
        $this->actingAs($user)
            ->getJson("/api/v1/dedicated/{$server->id}")
            ->assertStatus(404);
    }
}
