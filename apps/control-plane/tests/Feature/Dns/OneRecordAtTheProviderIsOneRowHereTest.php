<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Dns\Application\Actions\ClaimProviderRecord;
use Lynomia\Modules\Dns\Application\Actions\ReconcileZones;
use Lynomia\Modules\Dns\Application\Jobs\PublishRecord;
use Lynomia\Modules\Dns\Application\Jobs\RemoveRecord;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Enums\IndeterminateAfter;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Providers\CloudflareDnsProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CloudflareZoneSimulator;

/**
 * F-11, end to end: the Cloudflare adapter, over a simulated zone, driven
 * through the product's own HTTP surface, jobs and sweep.
 *
 * The audit's sentence was that records at one `(type, name)` collapsed onto
 * one, that two local rows could come to share one provider identifier, and
 * that deleting one then killed the survivor. The last clause survived every
 * adapter-level repair, because the platform opens the window itself:
 * `ChangeRecord` writes a row's new value at once and publishes afterwards,
 * so for a moment the live-value index permits a second row at the value the
 * first no longer carries — and a publish that finds that value in the zone
 * adopts its identifier. From inside an adapter, "the record I created and did
 * not hear about" and "a sibling's record" are the same read; only
 * `dns_records` can tell them apart, so the repair is here and not there.
 */
final class OneRecordAtTheProviderIsOneRowHereTest extends DnsTestCase
{
    private CloudflareZoneSimulator $simulator;

    private Customer $customer;

    private User $owner;

    private DnsZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->simulator = CloudflareZoneSimulator::install('example.test');
        $this->swapProvider(new CloudflareDnsProvider($this->app->make(SecretRedactor::class)));

        [$this->customer, $this->owner] = $this->accountWithOwner();

        $this->zone = DnsZone::factory()->active()->heldBy($this->customer)->create([
            'name' => 'example.test',
            'provider' => CloudflareDnsProvider::NAME,
            'provider_zone_id' => CloudflareZoneSimulator::ZONE_ID,
            'last_synced_at' => null,
        ]);
    }

    #[Test]
    public function a_second_row_at_the_value_an_edit_is_leaving_does_not_take_the_first_rows_record(): void
    {
        $first = $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])->assertCreated()->json('data.id');

        // Hold the publishes, so the window the edit opens can be walked
        // through in the order a busy queue would take it.
        Queue::fake();

        $this->request()->patchJson($this->records().'/'.$first, ['content' => '203.0.113.20'])->assertOk();
        $second = $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])->assertCreated()->json('data.id');

        $this->app->call([new PublishRecord((string) $second), 'handle']);
        $this->app->call([new PublishRecord((string) $first), 'handle']);

        $rows = DnsRecord::query()->whereKey([$first, $second])->get();
        $this->assertTrue($rows->every(static fn (DnsRecord $r): bool => $r->state === DnsState::Active));
        $this->assertNotSame($rows[0]->provider_record_id, $rows[1]->provider_record_id, 'Two live rows share one provider record.');

        // The customer asked for two records, and the zone holds two.
        $held = array_column($this->simulator->rows(), 'content');
        sort($held);
        $this->assertSame(['203.0.113.10', '203.0.113.20'], $held);

        // And removing one leaves the other answering.
        $this->request()->deleteJson($this->records().'/'.$second)->assertOk();
        $this->app->call([new RemoveRecord((string) $second), 'handle']);

        $this->assertSame(['203.0.113.20'], array_column($this->simulator->rows(), 'content'));
    }

    #[Test]
    public function the_table_refuses_two_live_rows_holding_one_provider_record(): void
    {
        DnsRecord::factory()->active()->create([
            'dns_zone_id' => $this->zone->getKey(), 'type' => DnsRecordType::A, 'name' => 'www.example.test',
            'content' => '203.0.113.10', 'provider_record_id' => 'rec-1',
        ]);

        $this->expectException(QueryException::class);

        DnsRecord::factory()->active()->create([
            'dns_zone_id' => $this->zone->getKey(), 'type' => DnsRecordType::A, 'name' => 'www.example.test',
            'content' => '203.0.113.11', 'provider_record_id' => 'rec-1',
        ]);
    }

    #[Test]
    public function a_claim_disowns_the_other_live_row_in_the_zone_and_nothing_else(): void
    {
        $other = DnsRecord::factory()->active()->create([
            'dns_zone_id' => $this->zone->getKey(), 'type' => DnsRecordType::A, 'name' => 'www.example.test',
            'content' => '203.0.113.20', 'provider_record_id' => 'rec-1',
        ]);
        $gone = DnsRecord::factory()->create([
            'dns_zone_id' => $this->zone->getKey(), 'type' => DnsRecordType::A, 'name' => 'www.example.test',
            'content' => '203.0.113.30', 'provider_record_id' => 'rec-1', 'state' => DnsState::Deleted,
        ]);
        $elsewhere = DnsRecord::factory()->active()->create([
            'dns_zone_id' => DnsZone::factory()->active()->create()->getKey(), 'type' => DnsRecordType::A,
            'name' => 'www.example.test', 'content' => '203.0.113.10', 'provider_record_id' => 'rec-1',
        ]);
        $claimant = DnsRecord::factory()->create([
            'dns_zone_id' => $this->zone->getKey(), 'type' => DnsRecordType::A, 'name' => 'www.example.test',
            'content' => '203.0.113.10', 'provider_record_id' => null, 'state' => DnsState::Pending,
        ]);

        app(ClaimProviderRecord::class)->execute($claimant, DnsState::Active, 'rec-1');

        $this->assertSame('rec-1', $claimant->refresh()->provider_record_id);
        $this->assertSame(DnsState::Active, $claimant->state);
        $this->assertNull($other->refresh()->provider_record_id, 'The refuted claim was left standing.');
        $this->assertSame('rec-1', $gone->refresh()->provider_record_id, 'A deleted row is history, and history is not disowned.');
        $this->assertSame('rec-1', $elsewhere->refresh()->provider_record_id, 'A row in another zone was disowned.');
    }

    #[Test]
    public function a_publish_settled_by_the_sweep_claims_its_record_the_same_way(): void
    {
        $id = $this->simulator->holds(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10', 'ttl' => 300]);

        $other = DnsRecord::factory()->active()->create([
            'dns_zone_id' => $this->zone->getKey(), 'type' => DnsRecordType::A, 'name' => 'www.example.test',
            'content' => '203.0.113.20', 'ttl' => 300, 'provider_record_id' => $id,
        ]);
        $settling = DnsRecord::factory()->create([
            'dns_zone_id' => $this->zone->getKey(), 'type' => DnsRecordType::A, 'name' => 'www.example.test',
            'content' => '203.0.113.10', 'ttl' => 300, 'provider_record_id' => null,
            'state' => DnsState::Indeterminate, 'indeterminate_after' => IndeterminateAfter::Publish,
        ]);

        app(ReconcileZones::class)->execute();

        $this->assertSame(DnsState::Active, $settling->refresh()->state);
        $this->assertSame($id, $settling->provider_record_id);
        $this->assertNull($other->refresh()->provider_record_id);
    }

    #[Test]
    public function a_publish_that_never_arrived_is_not_settled_as_a_deletion(): void
    {
        $this->simulator->failTheNextWrite();

        $id = $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])
            ->assertCreated()
            ->assertJsonPath('data.state', DnsState::Indeterminate->value)
            ->json('data.id');

        app(ReconcileZones::class)->execute();

        // The value is not in the zone because it never got there, not because
        // somebody removed it. The customer never asked for it to be gone.
        $this->assertSame(DnsState::Indeterminate, DnsRecord::query()->findOrFail($id)->state);
    }

    #[Test]
    public function a_delete_that_never_arrived_is_not_settled_as_live(): void
    {
        $id = $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])->assertCreated()->json('data.id');

        $this->simulator->failTheNextWrite();

        $this->request()->deleteJson($this->records().'/'.$id)
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Indeterminate->value);

        app(ReconcileZones::class)->execute();

        // Still in the zone, and the customer asked for it to be removed: it
        // is neither `active` nor `deleted`, and a person is asked.
        $this->assertSame(DnsState::NeedsReview, DnsRecord::query()->findOrFail($id)->state);
        $this->assertCount(1, $this->simulator->rows());
    }

    #[Test]
    public function removing_a_record_somebody_already_removed_at_the_provider_completes_and_spares_the_sibling(): void
    {
        $first = $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10'])->assertCreated()->json('data.id');
        $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.11'])->assertCreated();

        $this->simulator->forget((string) DnsRecord::query()->findOrFail($first)->provider_record_id);

        // The zone already says what the customer asked for.
        $this->request()->deleteJson($this->records().'/'.$first)
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Deleted->value);

        $this->assertSame(['203.0.113.11'], array_column($this->simulator->rows(), 'content'));
    }

    #[Test]
    public function a_change_of_ttl_alone_reaches_the_zone(): void
    {
        $id = $this->add(['type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10', 'ttl' => 300])->assertCreated()->json('data.id');

        $this->request()->patchJson($this->records().'/'.$id, ['content' => '203.0.113.10', 'ttl' => 3600])->assertOk();

        $this->assertSame(3600, $this->simulator->rows()[0]['ttl'] ?? null);
    }

    #[Test]
    public function a_correctly_published_caa_record_is_not_drift(): void
    {
        $this->add(['type' => 'CAA', 'name' => 'example.test', 'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']])
            ->assertCreated();

        $outcome = app(ReconcileZones::class)->execute();

        $this->assertSame(0, $outcome['drifts'], (string) json_encode(ResourceDrift::query()->get(['kind', 'provider_reference'])->toArray()));
    }

    #[Test]
    public function a_stranger_beside_a_record_the_platform_wrote_is_still_a_stranger(): void
    {
        $this->add(['type' => 'MX', 'name' => 'example.test', 'content' => 'mail.example.test', 'priority' => 10])->assertCreated();
        $this->add(['type' => 'CAA', 'name' => 'example.test', 'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org']])
            ->assertCreated();

        // Written through the provider's console: same host at another
        // priority, and a certificate authority nobody here authorised.
        $this->simulator->holds(['type' => 'MX', 'name' => 'example.test', 'content' => 'mail.example.test', 'priority' => 20, 'ttl' => 1]);
        $this->simulator->holds(['type' => 'CAA', 'name' => 'example.test', 'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'attacker-ca.test'], 'ttl' => 1]);

        app(ReconcileZones::class)->execute();

        $this->assertSame(2, ResourceDrift::query()->where('kind', DriftKind::OrphanAtProvider->value)->where('resource_type', 'dns_record')->count());
        $this->assertSame(0, ResourceDrift::query()->where('kind', DriftKind::MissingAtProvider->value)->count());
    }

    private function request(): self
    {
        return $this->actingAs($this->owner)->withHeaders($this->actingFor($this->customer));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function add(array $payload): TestResponse
    {
        return $this->request()->postJson($this->records(), $payload);
    }

    private function records(): string
    {
        return '/api/v1/dns/zones/'.$this->zone->getKey().'/records';
    }
}
