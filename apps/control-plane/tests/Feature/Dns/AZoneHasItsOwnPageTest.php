<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * A zone is addressed by its name, and only by its owner.
 *
 * Before Wave 3 the whole of DNS was one screen with a select box, so "the
 * records of example.test" was not an address anybody could send. Giving a
 * zone its own page means the route parameter is a domain name — something
 * anybody can type — so the two things asserted here are that the name works
 * and that it is scoped to the acting account.
 *
 * The record edit is here too, and the property that matters is that it is one
 * request rather than two: a delete followed by an add drops the record at the
 * provider and creates another, so anything resolving in between gets nothing
 * at all, and a failure halfway leaves the customer with neither the old value
 * nor the new one.
 */
final class AZoneHasItsOwnPageTest extends DnsTestCase
{
    private Customer $customer;

    private User $owner;

    private string $zoneId;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->customer, $this->owner] = $this->accountWithOwner();
        $this->provider();

        $this->zoneId = (string) $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/dns/zones', ['name' => 'example.test'])
            ->assertCreated()
            ->json('data.id');
    }

    #[Test]
    public function a_zone_is_readable_by_its_name_and_by_its_id(): void
    {
        foreach (['example.test', $this->zoneId, 'EXAMPLE.TEST'] as $identity) {
            $this->actingAs($this->owner)
                ->withHeaders($this->actingFor($this->customer))
                ->getJson('/api/v1/dns/zones/'.$identity)
                ->assertOk()
                ->assertJsonPath('data.name', 'example.test')
                ->assertJsonPath('data.id', $this->zoneId);
        }
    }

    #[Test]
    public function its_records_are_readable_by_the_zones_name_too(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/dns/zones/'.$this->zoneId.'/records', [
                'type' => 'A',
                'name' => 'www.example.test',
                'content' => '203.0.113.10',
            ])
            ->assertCreated();

        // The page a customer bookmarks is /dns/example.test/records, so the
        // request behind it addresses the zone the same way.
        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->getJson('/api/v1/dns/zones/example.test/records')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'www.example.test');
    }

    #[Test]
    public function another_accounts_zone_name_answers_exactly_as_an_unknown_one_does(): void
    {
        [$theirs, $them] = $this->accountWithOwner();

        $this->actingAs($them)
            ->withHeaders($this->actingFor($theirs))
            ->postJson('/api/v1/dns/zones', ['name' => 'theirs.test'])
            ->assertCreated();

        /*
         * A zone name is guessable in a way a ULID is not, so the lookup is
         * scoped to the acting customer rather than filtered afterwards, and
         * a name somebody else holds is not found rather than found-and-
         * refused. 403 would confirm it exists.
         */
        foreach (['theirs.test', 'nobody-claimed-this.test'] as $identity) {
            $this->actingAs($this->owner)
                ->withHeaders($this->actingFor($this->customer))
                ->getJson('/api/v1/dns/zones/'.$identity)
                ->assertNotFound();
        }
    }

    #[Test]
    public function a_zone_identifier_carrying_a_path_reaches_nothing(): void
    {
        /*
         * The route pattern is what a hostname is made of and nothing else, so
         * none of these is a path the router will take. They are the shapes
         * somebody tries first when a URL segment becomes a domain name.
         */
        foreach ([
            'example.test%2F..%2Fadmin',
            '..%2F..%2Fetc%2Fpasswd',
            'example.test%00',
            'example.test%2Frecords%2F01JEXAMPLE',
        ] as $attempt) {
            $response = $this->actingAs($this->owner)
                ->withHeaders($this->actingFor($this->customer))
                ->getJson('/api/v1/dns/zones/'.$attempt);

            $this->assertContains(
                $response->getStatusCode(),
                [404, 405],
                sprintf('%s must not reach a zone.', $attempt),
            );
        }
    }

    #[Test]
    public function a_record_is_changed_in_place_rather_than_deleted_and_recreated(): void
    {
        $id = (string) $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/dns/zones/'.$this->zoneId.'/records', [
                'type' => 'A',
                'name' => 'www.example.test',
                'content' => '203.0.113.10',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->patchJson('/api/v1/dns/zones/example.test/records/'.$id, [
                'content' => '203.0.113.99',
                'ttl' => 300,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.content', '203.0.113.99')
            ->assertJsonPath('data.ttl', 300)
            ->assertJsonPath('data.state', DnsState::Active->value);

        // One record at the provider, still, and carrying the new value: the
        // edit did not go through a delete.
        $zone = $this->provider()->findZone('example.test');
        $this->assertNotNull($zone);

        $records = $this->provider()->records($zone);
        $this->assertCount(1, $records);
        $this->assertSame('203.0.113.99', $records[0]->content());
    }

    #[Test]
    public function a_record_belonging_to_another_accounts_zone_cannot_be_changed_through_mine(): void
    {
        [$theirs, $them] = $this->accountWithOwner();

        $theirZone = (string) $this->actingAs($them)
            ->withHeaders($this->actingFor($theirs))
            ->postJson('/api/v1/dns/zones', ['name' => 'theirs.test'])
            ->assertCreated()
            ->json('data.id');

        $theirRecord = (string) $this->actingAs($them)
            ->withHeaders($this->actingFor($theirs))
            ->postJson('/api/v1/dns/zones/'.$theirZone.'/records', [
                'type' => 'A',
                'name' => 'www.theirs.test',
                'content' => '203.0.113.20',
            ])
            ->assertCreated()
            ->json('data.id');

        /*
         * The record id is real and the zone in the path is mine. The record
         * is resolved *through* the zone rather than globally, so this is a
         * record that does not exist rather than one that exists and is
         * refused.
         */
        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->patchJson('/api/v1/dns/zones/example.test/records/'.$theirRecord, [
                'content' => '203.0.113.66',
            ])
            ->assertNotFound();

        // And the record is untouched at the provider.
        $zone = $this->provider()->findZone('theirs.test');
        $this->assertNotNull($zone);
        $this->assertSame('203.0.113.20', $this->provider()->records($zone)[0]->content());
    }

    #[Test]
    public function an_edit_cannot_rename_a_record_or_change_its_type(): void
    {
        $id = (string) $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/dns/zones/'.$this->zoneId.'/records', [
                'type' => 'A',
                'name' => 'www.example.test',
                'content' => '203.0.113.10',
            ])
            ->assertCreated()
            ->json('data.id');

        /*
         * Both are sent and both are ignored: a record with a different name
         * is a different record, and letting one be edited into another would
         * leave the provider holding the old value under an identifier the
         * platform had quietly reassigned. A customer who wants a different
         * name deletes and adds, and sees both steps.
         */
        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->patchJson('/api/v1/dns/zones/example.test/records/'.$id, [
                'content' => '203.0.113.11',
                'name' => 'elsewhere.example.test',
                'type' => 'TXT',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'www.example.test')
            ->assertJsonPath('data.type', 'A')
            ->assertJsonPath('data.content', '203.0.113.11');
    }
}
