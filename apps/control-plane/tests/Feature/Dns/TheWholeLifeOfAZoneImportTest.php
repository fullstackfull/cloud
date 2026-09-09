<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZoneImport;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use PHPUnit\Framework\Attributes\Test;

/**
 * A zone moved in as a file and out again: preview, diff, plan, confirm,
 * apply through the ordinary record actions, audit, export.
 *
 * The properties proven are the ones the addendum named: nothing is
 * written directly to the tables, nothing is skipped silently, a refused
 * line refuses the whole plan, the default import removes nothing, a
 * replace shows what it removes before it does, a stale preview cannot be
 * applied, every DNS rule still applies, and a malicious file cannot reach
 * another customer's zone or another customer's address.
 */
final class TheWholeLifeOfAZoneImportTest extends DnsTestCase
{
    private Customer $customer;

    private User $owner;

    private string $zoneId;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->customer, $this->owner] = $this->accountWithOwner();
        $this->provider();

        $this->zoneId = (string) $this->request()
            ->postJson('/api/v1/dns/zones', ['name' => 'example.test'])
            ->assertCreated()
            ->json('data.id');
    }

    private function request(): self
    {
        return $this->actingAs($this->owner)->withHeaders($this->actingFor($this->customer));
    }

    private function plan(string $text, string $mode = 'merge', ?string $zone = null): TestResponse
    {
        return $this->request()->postJson('/api/v1/dns/zones/'.($zone ?? $this->zoneId).'/import/plan', ['text' => $text, 'mode' => $mode]);
    }

    private function apply(string $text, string $fingerprint, string $mode = 'merge'): TestResponse
    {
        return $this->request()->postJson('/api/v1/dns/zones/'.$this->zoneId.'/import', ['text' => $text, 'mode' => $mode, 'fingerprint' => $fingerprint]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(TestResponse $plan): array
    {
        /** @var array<string, int> $counts */
        $counts = $plan->json('data.counts');

        return $counts;
    }

    #[Test]
    public function a_file_is_previewed_as_a_diff_applied_through_the_record_actions_audited_and_exported_back(): void
    {
        $this->request()->postJson('/api/v1/dns/zones/'.$this->zoneId.'/records', ['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])->assertCreated();

        $text = <<<'ZONE'
        $ORIGIN example.test.
        $TTL 3600
        @    IN SOA ns1.old.test. hostmaster.old.test. ( 1 2 3 4 5 )
        @    IN NS  ns1.old.test.
        www  300 IN A   203.0.113.10
        api      IN A   203.0.113.20
        @        IN MX  10 mail.example.test.
        @        IN TXT "v=spf1 mx -all"
        @        IN CAA 0 issue "letsencrypt.org"
        ZONE;

        $plan = $this->plan($text)->assertOk();
        $plan->assertJsonPath('data.applicable', true);
        $plan->assertJsonPath('data.mode', 'merge');
        $this->assertSame(['add' => 4, 'update' => 1, 'remove' => 0, 'unchanged' => 0, 'refused' => 0, 'ignored' => 2, 'kept' => 0], $this->counts($plan));

        $entries = collect($plan->json('data.entries'));
        $this->assertSame('update', $entries->firstWhere('name', 'www.example.test')['kind']);
        $this->assertSame(300, $entries->firstWhere('name', 'www.example.test')['ttl']);
        $this->assertSame('ignored', $entries->firstWhere('line', 3)['kind']);
        $this->assertStringContainsString('SOA', $entries->firstWhere('line', 3)['reason']);

        $fingerprint = (string) $plan->json('data.fingerprint');

        // Nothing has been written by the preview.
        $this->assertSame(1, DnsRecord::query()->where('dns_zone_id', $this->zoneId)->count());

        $applied = $this->apply($text, $fingerprint)->assertOk();
        $applied->assertJsonPath('data.added', 4)->assertJsonPath('data.updated', 1)->assertJsonPath('data.removed', 0);

        $records = DnsRecord::query()->where('dns_zone_id', $this->zoneId)->where('state', '!=', DnsState::Deleted->value)->get();
        $this->assertCount(5, $records);
        // Through the record actions: each one was published by the fake and is active with a provider id.
        $this->assertTrue($records->every(fn (DnsRecord $r): bool => $r->state === DnsState::Active && $r->provider_record_id !== null));
        $this->assertSame(300, $records->firstWhere('name', 'www.example.test')?->ttl);
        $this->assertSame(10, $records->firstWhere('type', 'MX')?->priority);
        $this->assertSame(['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], $records->firstWhere('type', 'CAA')?->data);

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::DnsZoneImported->value]);
        // Each record's own audit row as well: the import is not a way round them.
        $this->assertSame(5, AuditEntry::query()->where('action', AuditAction::DnsRecordCreated->value)->count());
        $this->assertSame('applied', DnsZoneImport::query()->sole()->outcome->value);

        // Round trip: the export is a zone file the parser reads back to the same records.
        $export = $this->request()->getJson('/api/v1/dns/zones/'.$this->zoneId.'/export')->assertOk();
        $export->assertJsonPath('data.filename', 'example.test.zone')->assertJsonPath('data.record_count', 5);
        $content = (string) $export->json('data.content');
        $this->assertStringContainsString('$ORIGIN example.test.', $content);
        $this->assertStringContainsString('www 300 IN A 203.0.113.10', $content);
        $this->assertStringContainsString('@ 3600 IN MX 10 mail.example.test.', $content);
        $this->assertStringContainsString('@ 3600 IN TXT "v=spf1 mx -all"', $content);
        $this->assertStringContainsString('@ 3600 IN CAA 0 issue "letsencrypt.org"', $content);
        $this->assertStringNotContainsString('SOA', explode("\n", $content)[3] ?? 'SOA');
        // The fake names its nameservers after its zone id, so the check is
        // on the record lines: nothing but owner, TTL, class, type and data.
        $records = implode("\n", array_filter(explode("\n", $content), static fn (string $l): bool => $l !== '' && ! str_starts_with($l, ';') && ! str_starts_with($l, '$')));
        $this->assertStringNotContainsString('zone-', $records, 'the provider\'s zone id must not be exported');
        $this->assertStringNotContainsString($this->zoneId, $content, 'the platform\'s own id must not be exported');
        $this->assertStringNotContainsString('rec-', $content, 'provider record ids must not be exported');
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::DnsZoneExported->value]);

        $again = $this->plan($content)->assertOk();
        $this->assertSame(5, $this->counts($again)['unchanged']);
        $this->assertSame(0, $this->counts($again)['add']);
    }

    #[Test]
    public function one_refused_line_refuses_the_whole_plan_and_the_apply(): void
    {
        $text = "www IN A 203.0.113.10\ninternal IN A 10.0.0.5\nmail IN MX 10 203.0.113.9\nbad IN SRV 1 1 1 x.example.test.\nwww2 IN A 203.0.113.10\n";

        $plan = $this->plan($text)->assertOk();
        $plan->assertJsonPath('data.applicable', false);
        $this->assertSame(3, $this->counts($plan)['refused']);
        $this->assertSame(2, $this->counts($plan)['add']);

        $reasons = collect($plan->json('data.entries'))->where('kind', 'refused')->pluck('reason', 'line')->all();
        $this->assertStringContainsString('reach', strtolower($reasons[2]));
        $this->assertStringContainsString('host name', $reasons[3]);
        $this->assertStringContainsString('SRV', $reasons[4]);

        $this->apply($text, (string) $plan->json('data.fingerprint'))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dns.import.refused');

        $this->assertSame(0, DnsRecord::query()->where('dns_zone_id', $this->zoneId)->count());
        $this->assertSame('refused', DnsZoneImport::query()->sole()->outcome->value);
    }

    #[Test]
    public function a_stale_preview_cannot_be_applied(): void
    {
        $text = "www IN A 203.0.113.10\n";
        $fingerprint = (string) $this->plan($text)->json('data.fingerprint');

        // The zone changes between preview and apply.
        $this->request()->postJson('/api/v1/dns/zones/'.$this->zoneId.'/records', ['type' => 'A', 'name' => 'other.example.test', 'content' => '203.0.113.11'])->assertCreated();

        $this->apply($text, $fingerprint)->assertStatus(409)->assertJsonPath('error.code', 'dns.import.plan_changed');
        $this->assertSame('plan_changed', DnsZoneImport::query()->sole()->outcome->value);

        // A fingerprint that was never issued is refused the same way.
        $this->apply($text, str_repeat('0', 64))->assertStatus(409);
        $this->apply($text, 'nope')->assertUnprocessable();

        $this->assertSame(1, DnsRecord::query()->where('dns_zone_id', $this->zoneId)->count());
    }

    #[Test]
    public function merge_leaves_what_the_file_does_not_mention_and_replace_shows_what_it_removes_first(): void
    {
        $this->request()->postJson('/api/v1/dns/zones/'.$this->zoneId.'/records', ['type' => 'A', 'name' => 'old.example.test', 'content' => '203.0.113.99'])->assertCreated();
        $text = "www IN A 203.0.113.10\n";

        $merge = $this->plan($text)->assertOk();
        $this->assertSame(0, $this->counts($merge)['remove']);
        $this->assertSame(1, $this->counts($merge)['kept']);

        $replace = $this->plan($text, 'replace')->assertOk();
        $this->assertSame(1, $this->counts($replace)['remove']);
        $removed = collect($replace->json('data.entries'))->firstWhere('kind', 'remove');
        $this->assertSame('old.example.test', $removed['name']);

        $this->apply($text, (string) $replace->json('data.fingerprint'), 'replace')->assertOk()->assertJsonPath('data.removed', 1);

        $this->assertNull(DnsRecord::query()->where('name', 'old.example.test')->whereIn('state', [DnsState::Active->value, DnsState::Pending->value])->first());
        $this->assertNotNull(DnsRecord::query()->where('name', 'www.example.test')->first());
    }

    #[Test]
    public function a_cname_that_would_stand_beside_another_record_is_refused_on_the_zone_as_it_would_be(): void
    {
        // Existing A at blog; the file adds a CNAME at blog: refused in merge (A stays)...
        $this->request()->postJson('/api/v1/dns/zones/'.$this->zoneId.'/records', ['type' => 'A', 'name' => 'blog.example.test', 'content' => '203.0.113.10'])->assertCreated();

        $merge = $this->plan("blog IN CNAME www.example.test.\n")->assertOk();
        $merge->assertJsonPath('data.applicable', false);
        $this->assertStringContainsString('stand alone', collect($merge->json('data.entries'))->firstWhere('kind', 'refused')['reason']);

        // ...and allowed in replace, where the A is removed first.
        $replace = $this->plan("blog IN CNAME www.example.test.\n", 'replace')->assertOk();
        $replace->assertJsonPath('data.applicable', true);
        $this->assertSame(1, $this->counts($replace)['remove']);

        // Two lines in one file colliding with each other are both refused.
        $both = $this->plan("x IN CNAME www.example.test.\nx IN TXT \"hello\"\n")->assertOk();
        $this->assertSame(2, $this->counts($both)['refused']);

        // A CNAME at the apex is refused by the same rule the single path uses.
        $apex = $this->plan("@ IN CNAME www.example.test.\n")->assertOk();
        $apex->assertJsonPath('data.applicable', false);
    }

    #[Test]
    public function a_file_cannot_write_outside_the_zone_or_point_at_somebody_elses_platform_address(): void
    {
        $other = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $address = IpAddress::factory()->create(['address' => '198.51.100.7']);
        IpAssignment::factory()->create(['ip_address_id' => $address->getKey(), 'customer_id' => $other->getKey(), 'released_at' => null]);

        $plan = $this->plan("www.somebodyelse.test. IN A 203.0.113.10\n\$ORIGIN somebodyelse.test.\nx IN A 203.0.113.10\nmine IN A 198.51.100.7\n")->assertOk();
        $plan->assertJsonPath('data.applicable', false);

        $reasons = collect($plan->json('data.entries'))->where('kind', 'refused')->pluck('reason')->implode(' | ');
        $this->assertStringContainsString('not inside this zone', $reasons);
        $this->assertStringContainsString('$ORIGIN must be example.test', $reasons);
        $this->assertStringContainsString('belongs to this platform', $reasons);
    }

    #[Test]
    public function another_customers_zone_is_a_404_for_the_plan_the_apply_and_the_export(): void
    {
        [$stranger, $strangerOwner] = $this->accountWithOwner();
        $theirs = (string) $this->actingAs($strangerOwner)->withHeaders($this->actingFor($stranger))
            ->postJson('/api/v1/dns/zones', ['name' => 'stranger.test'])->json('data.id');

        $this->plan("www IN A 203.0.113.10\n", 'merge', $theirs)->assertNotFound();
        $this->request()->postJson('/api/v1/dns/zones/'.$theirs.'/import', ['text' => 'www IN A 203.0.113.10', 'fingerprint' => str_repeat('a', 64)])->assertNotFound();
        $this->request()->getJson('/api/v1/dns/zones/'.$theirs.'/export')->assertNotFound();
    }

    #[Test]
    public function the_zone_ceiling_is_measured_on_the_final_count_and_the_input_bounds_answer_422(): void
    {
        config(['dns.records_per_zone' => 3]);

        $plan = $this->plan("a IN A 203.0.113.1\nb IN A 203.0.113.2\nc IN A 203.0.113.3\nd IN A 203.0.113.4\n")->assertOk();
        $plan->assertJsonPath('data.applicable', false);
        $this->assertStringContainsString('at most 3', collect($plan->json('data.entries'))->firstWhere('kind', 'refused')['reason']);

        $this->plan(str_repeat("a IN A 203.0.113.1\n", 20_000))->assertUnprocessable();
        $this->plan("www IN A 203.0.113.1\x01")->assertUnprocessable()->assertJsonPath('error.code', 'dns.zone_file.not_text');
        $this->request()->postJson('/api/v1/dns/zones/'.$this->zoneId.'/import/plan', ['text' => ''])->assertUnprocessable();
        $this->request()->postJson('/api/v1/dns/zones/'.$this->zoneId.'/import/plan', ['text' => 'x', 'mode' => 'overwrite'])->assertUnprocessable();
    }

    #[Test]
    public function a_member_who_may_only_look_can_export_and_cannot_import(): void
    {
        $viewer = $this->memberOf($this->customer);

        $this->actingAs($viewer)->withHeaders($this->actingFor($this->customer))
            ->getJson('/api/v1/dns/zones/'.$this->zoneId.'/export')->assertOk();
        $this->actingAs($viewer)->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/dns/zones/'.$this->zoneId.'/import/plan', ['text' => 'www IN A 203.0.113.1'])->assertForbidden();
    }
}
