<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Everything a customer can type that the platform refuses, and why.
 *
 * These are the tests that matter most on this surface. A DNS provider will
 * accept a great deal that does not work — an AAAA holding an IPv4 address, a
 * CNAME beside an MX, an MX pointing at an address — and every one of those is
 * discovered by the customer as an outage rather than by the platform as an
 * error. The last two are worse than that: they are how one account points a
 * name at another account's machine.
 */
final class RecordsTheZoneWillNotTakeTest extends DnsTestCase
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
            ->json('data.id');
    }

    #[Test]
    public function a_name_outside_the_zone_is_refused(): void
    {
        /*
         * The rule that stops a zone being used to serve somebody else's
         * domain. `notexample.test` ends with `example.test` and belongs to
         * whoever registered it — suffix matching without the label boundary
         * is exactly this bug.
         */
        $this->add(['type' => 'A', 'name' => 'www.somebodyelse.test', 'content' => '203.0.113.10'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.record.outside_zone');

        $this->add(['type' => 'A', 'name' => 'notexample.test', 'content' => '203.0.113.10'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.record.outside_zone');
    }

    #[Test]
    public function an_address_of_the_wrong_family_is_refused(): void
    {
        $this->add(['type' => 'A', 'name' => 'a.example.test', 'content' => '2001:db8::1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.invalid_record');

        $this->add(['type' => 'AAAA', 'name' => 'b.example.test', 'content' => '203.0.113.10'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.invalid_record');
    }

    #[Test]
    public function an_address_nobody_can_reach_from_the_internet_is_refused(): void
    {
        /*
         * A name resolving to 127.0.0.1 resolves to *the visitor's own
         * machine*, which is a well-known way to turn a customer's domain into
         * a tool for attacking the people who visit it. The link-local range
         * carries the cloud metadata endpoint, which is worse.
         */
        foreach (['127.0.0.1', '169.254.169.254', '10.0.0.5'] as $address) {
            $this->add(['type' => 'A', 'name' => 'a.example.test', 'content' => $address])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'dns.invalid_record');
        }
    }

    #[Test]
    public function a_platform_address_that_belongs_to_somebody_else_is_refused(): void
    {
        [$neighbour] = $this->accountWithOwner();

        $address = IpAddress::factory()->create(['address' => '198.51.100.24']);
        IpAssignment::factory()->create([
            'ip_address_id' => $address->getKey(),
            'customer_id' => $neighbour->getKey(),
            'released_at' => null,
        ]);

        /*
         * The most serious refusal on this surface. A name pointed at another
         * customer's machine is where virtual-host hijacking begins, and where
         * a certificate authority is persuaded to issue for a domain the
         * requester does not control.
         */
        $this->add(['type' => 'A', 'name' => 'a.example.test', 'content' => '198.51.100.24'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'dns.record.address_not_yours');
    }

    #[Test]
    public function a_platform_address_this_account_holds_is_allowed(): void
    {
        $address = IpAddress::factory()->create(['address' => '198.51.100.25']);
        IpAssignment::factory()->create([
            'ip_address_id' => $address->getKey(),
            'customer_id' => $this->customer->getKey(),
            'released_at' => null,
        ]);

        $this->add(['type' => 'A', 'name' => 'a.example.test', 'content' => '198.51.100.25'])
            ->assertCreated();
    }

    #[Test]
    public function an_address_this_platform_does_not_allocate_is_nobody_elses_business(): void
    {
        // A customer may point their own domain anywhere on the internet. A
        // platform that policed that would be a platform deciding where its
        // customers' names may point.
        $this->add(['type' => 'A', 'name' => 'a.example.test', 'content' => '93.184.216.34'])
            ->assertCreated();
    }

    #[Test]
    public function a_cname_cannot_stand_for_the_whole_domain(): void
    {
        $this->add(['type' => 'CNAME', 'name' => 'example.test', 'content' => 'elsewhere.test'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.record.cname_at_apex');
    }

    #[Test]
    public function a_cname_has_to_be_the_only_record_at_its_name(): void
    {
        $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])->assertCreated();

        $this->add(['type' => 'CNAME', 'name' => 'www.example.test', 'content' => 'elsewhere.test'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dns.record.cname_conflict');
    }

    #[Test]
    public function nothing_else_may_join_a_cname(): void
    {
        $this->add(['type' => 'CNAME', 'name' => 'shop.example.test', 'content' => 'elsewhere.test'])->assertCreated();

        // Resolvers disagree about what to do with this, which is worse than
        // any single one of them handling it badly.
        $this->add(['type' => 'A', 'name' => 'shop.example.test', 'content' => '203.0.113.10'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dns.record.covered_by_cname');
    }

    #[Test]
    public function a_mail_exchanger_must_be_named_rather_than_addressed(): void
    {
        // RFC 5321 requires a name here, so a well-behaved sender will not
        // deliver — and the customer sees mail working from some senders and
        // not others, which is the hardest kind of fault to report.
        $this->add(['type' => 'MX', 'name' => 'example.test', 'content' => '203.0.113.10', 'priority' => 10])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.invalid_record');
    }

    #[Test]
    public function a_mail_exchanger_without_a_priority_is_refused(): void
    {
        $this->add(['type' => 'MX', 'name' => 'example.test', 'content' => 'mail.example.test'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.invalid_record');
    }

    #[Test]
    public function a_text_record_may_not_carry_line_breaks(): void
    {
        // A newline is either a paste accident or an attempt to write a second
        // record, and providers differ on which.
        $this->add(['type' => 'TXT', 'name' => 'example.test', 'content' => "v=spf1 -all\nmalicious"])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.invalid_record');
    }

    #[Test]
    public function a_caa_record_with_a_tag_nobody_implements_is_refused(): void
    {
        $this->add([
            'type' => 'CAA',
            'name' => 'example.test',
            'data' => ['flags' => 0, 'tag' => 'whatever', 'value' => 'letsencrypt.org'],
        ])->assertStatus(422);
    }

    #[Test]
    public function the_same_value_cannot_be_written_twice(): void
    {
        $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])->assertCreated();

        // Several A records under one name is round robin; the same one twice
        // is a double-click the customer then has to undo record by record.
        $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dns.record.duplicate');

        $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.11'])
            ->assertCreated();
    }

    #[Test]
    public function an_edit_cannot_be_used_to_get_round_the_address_rule(): void
    {
        [$neighbour] = $this->accountWithOwner();

        $address = IpAddress::factory()->create(['address' => '198.51.100.30']);
        IpAssignment::factory()->create([
            'ip_address_id' => $address->getKey(),
            'customer_id' => $neighbour->getKey(),
            'released_at' => null,
        ]);

        $id = (string) $this->add(['type' => 'A', 'name' => 'a.example.test', 'content' => '203.0.113.10'])
            ->assertCreated()
            ->json('data.id');

        /*
         * The way round a front-door check is always the side door. Publish
         * something allowed, then edit it into something that is not.
         */
        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->patchJson($this->records().'/'.$id, ['content' => '198.51.100.30'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'dns.record.address_not_yours');
    }

    #[Test]
    public function the_ceiling_on_records_per_zone_is_enforced(): void
    {
        config()->set('dns.records_per_zone', 1);

        $this->add(['type' => 'A', 'name' => 'a.example.test', 'content' => '203.0.113.10'])->assertCreated();

        $this->add(['type' => 'A', 'name' => 'b.example.test', 'content' => '203.0.113.11'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dns.record.limit_reached');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function add(array $payload): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson($this->records(), $payload);
    }

    private function records(): string
    {
        return '/api/v1/dns/zones/'.$this->zoneId.'/records';
    }
}
