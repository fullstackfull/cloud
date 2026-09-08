<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Records: writing them, changing them, taking them away.
 */
final class PublishingRecordsTest extends DnsTestCase
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
    public function an_address_record_reaches_the_zone(): void
    {
        $response = $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10']);

        $response->assertCreated()
            ->assertJsonPath('data.state', DnsState::Active->value)
            ->assertJsonPath('data.is_live', true);

        $zone = $this->provider()->findZone('example.test');
        $this->assertNotNull($zone);
        $this->assertCount(1, $this->provider()->records($zone, DnsRecordType::A, 'www.example.test'));
    }

    #[Test]
    public function every_type_the_platform_supports_can_be_written(): void
    {
        $this->add(['type' => 'A', 'name' => 'a.example.test', 'content' => '203.0.113.10'])->assertCreated();
        $this->add(['type' => 'AAAA', 'name' => 'aaaa.example.test', 'content' => '2001:db8::1'])->assertCreated();
        $this->add(['type' => 'CNAME', 'name' => 'cname.example.test', 'content' => 'a.example.test'])->assertCreated();
        $this->add(['type' => 'MX', 'name' => 'example.test', 'content' => 'mail.example.test', 'priority' => 10])->assertCreated();
        $this->add(['type' => 'TXT', 'name' => 'example.test', 'content' => 'v=spf1 -all'])->assertCreated();

        /*
         * CAA carries three fields rather than a string, and the platform
         * stores the presentation form as well so that everything in the table
         * reads, compares and deduplicates the same way.
         */
        $this->add([
            'type' => 'CAA',
            'name' => 'example.test',
            'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
        ])->assertCreated()->assertJsonPath('data.content', '0 issue "letsencrypt.org"');

        $this->assertSame(6, DnsRecord::query()->where('state', DnsState::Active->value)->count());
    }

    #[Test]
    public function a_record_that_is_changed_goes_back_through_pending_rather_than_being_written_over(): void
    {
        $id = (string) $this->add([
            'type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10',
        ])->json('data.id');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->patchJson($this->records().'/'.$id, ['content' => '203.0.113.20', 'ttl' => 300])
            ->assertOk()
            ->assertJsonPath('data.content', '203.0.113.20')
            ->assertJsonPath('data.ttl', 300)
            ->assertJsonPath('data.state', DnsState::Active->value);

        // The zone holds the new value and not both: publish is defined as
        // "make the zone say this", not "append".
        $zone = $this->provider()->findZone('example.test');
        $this->assertNotNull($zone);

        $held = $this->provider()->records($zone, DnsRecordType::A, 'www.example.test');
        $this->assertCount(1, $held);
        $this->assertSame('203.0.113.20', $held[0]->content());
    }

    #[Test]
    public function neither_the_name_nor_the_type_can_be_edited(): void
    {
        $id = (string) $this->add([
            'type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10',
        ])->json('data.id');

        /*
         * Sent and ignored rather than refused, because a client that sends
         * them is not doing anything dangerous — it is asking for something
         * this endpoint does not do. What must not happen is the record
         * quietly becoming a different record, leaving the old value live at
         * the provider under an identifier the platform has reassigned.
         */
        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->patchJson($this->records().'/'.$id, [
                'content' => '203.0.113.20',
                'name' => 'elsewhere.example.test',
                'type' => 'TXT',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'www.example.test')
            ->assertJsonPath('data.type', 'A');
    }

    #[Test]
    public function a_removed_record_leaves_the_zone(): void
    {
        $id = (string) $this->add([
            'type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10',
        ])->json('data.id');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson($this->records().'/'.$id)
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Deleted->value);

        $zone = $this->provider()->findZone('example.test');
        $this->assertNotNull($zone);
        $this->assertCount(0, $this->provider()->records($zone));
    }

    #[Test]
    public function a_record_that_never_reached_the_provider_is_simply_gone(): void
    {
        // Refused by the provider, so nothing is out there to remove. A delete
        // call for it would be a round trip whose only possible answer is "no
        // such record".
        $id = (string) $this->add([
            'type' => 'A', 'name' => 'dns-refused.example.test', 'content' => '203.0.113.10',
        ])->assertCreated()->assertJsonPath('data.state', DnsState::Failed->value)->json('data.id');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson($this->records().'/'.$id)
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Deleted->value);
    }

    #[Test]
    public function a_delete_that_does_not_answer_leaves_the_row_indeterminate_and_is_not_retried(): void
    {
        $id = (string) $this->add([
            'type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10',
        ])->json('data.id');

        // Renamed under the row: the marker has to be on the name at delete
        // time, and this is the shape an operator's "why did that time out"
        // actually takes — the same record, a provider that stops answering.
        DnsRecord::query()->whereKey($id)->update(['name' => 'dns-timeout.example.test']);

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson($this->records().'/'.$id)
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Indeterminate->value)
            ->assertJsonPath('data.needs_attention', true);

        /*
         * Not deleted, and not retried. The record may well be gone; asking
         * again is how a name the customer has since re-created gets removed
         * a second time.
         */
        $zone = $this->provider()->findZone('example.test');
        $this->assertNotNull($zone);
        $this->assertCount(1, $this->provider()->records($zone));
    }

    #[Test]
    public function writing_changing_and_removing_a_record_are_each_audited(): void
    {
        $id = (string) $this->add([
            'type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10',
        ])->json('data.id');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->patchJson($this->records().'/'.$id, ['content' => '203.0.113.20'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson($this->records().'/'.$id)
            ->assertOk();

        foreach ([AuditAction::DnsRecordCreated, AuditAction::DnsRecordUpdated, AuditAction::DnsRecordDeleted] as $action) {
            $this->assertTrue(
                AuditEntry::query()->where('action', $action->value)->exists(),
                $action->value.' was not written',
            );
        }

        // The old value, because "what did this person do" is the question an
        // audit trail answers and the row already says what it holds now.
        $updated = AuditEntry::query()->where('action', AuditAction::DnsRecordUpdated->value)->firstOrFail();
        $this->assertSame('203.0.113.10', $updated->context['was'] ?? null);
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
