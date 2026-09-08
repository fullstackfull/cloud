<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Lynomia\Modules\Dns\Application\Actions\ReconcileZones;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use PHPUnit\Framework\Attributes\Test;

/**
 * Comparing the platform's picture of a zone with the zone.
 *
 * The property every test here defends is the same one: **nothing in this
 * sweep writes to a zone.** A customer's zone is not the platform's document,
 * and a sweep that removed what it did not recognise would remove somebody's
 * mail routing at three in the morning while being entirely correct that the
 * record was not in this database.
 */
final class ZoneReconciliationTest extends DnsTestCase
{
    #[Test]
    public function a_record_the_platform_calls_live_but_the_zone_does_not_have_is_reported(): void
    {
        $provider = $this->provider();
        $zone = $this->zoneHeldByTheProvider();

        DnsRecord::factory()->active()->create([
            'dns_zone_id' => $zone->getKey(),
            'type' => DnsRecordType::A,
            'name' => 'www.example.test',
            'content' => '203.0.113.10',
        ]);

        $outcome = app(ReconcileZones::class)->execute();

        $this->assertSame(1, $outcome['drifts']);

        $drift = ResourceDrift::query()->where('kind', DriftKind::MissingAtProvider->value)->firstOrFail();
        $this->assertSame('dns_record', $drift->resource_type);

        // Reported, not repaired. Republishing here would be this sweep
        // deciding what a customer's zone should say.
        $this->assertCount(0, $provider->records($provider->findZone('example.test')));
    }

    #[Test]
    public function a_record_nobody_here_wrote_is_reported_and_left_exactly_where_it_is(): void
    {
        $provider = $this->provider();
        $zone = $this->zoneHeldByTheProvider();

        $providerZone = $provider->findZone('example.test');
        $this->assertNotNull($providerZone);

        $provider->publish($providerZone, DnsRecordValue::of(
            DnsRecordType::TXT,
            'somebody-elses.example.test',
            'written through the provider console',
        ));

        $outcome = app(ReconcileZones::class)->execute();

        $this->assertSame(1, $outcome['drifts']);
        $this->assertTrue(
            ResourceDrift::query()->where('kind', DriftKind::OrphanAtProvider->value)->exists(),
        );

        // Still there. This is the assertion the whole design exists for.
        $this->assertCount(1, $provider->records($providerZone));

        // And not adopted: an archive of unknown provenance must not be shown
        // to a customer as one of theirs.
        $this->assertSame(0, DnsRecord::query()->count());

        unset($zone);
    }

    #[Test]
    public function a_publish_that_landed_after_all_is_settled_rather_than_reported(): void
    {
        $provider = $this->provider();
        $zone = $this->zoneHeldByTheProvider();

        $providerZone = $provider->findZone('example.test');
        $this->assertNotNull($providerZone);

        $provider->publish($providerZone, DnsRecordValue::of(
            DnsRecordType::A,
            'www.example.test',
            '203.0.113.10',
        ));

        // The row the platform stopped being sure of: the publish call did not
        // answer, and it turns out it worked.
        $record = DnsRecord::factory()->create([
            'dns_zone_id' => $zone->getKey(),
            'type' => DnsRecordType::A,
            'name' => 'www.example.test',
            'content' => '203.0.113.10',
            'state' => DnsState::Indeterminate,
            'failure_reason' => 'the provider stopped answering',
        ]);

        $outcome = app(ReconcileZones::class)->execute();

        $this->assertSame(1, $outcome['settled']);
        $this->assertSame(0, $outcome['drifts']);

        $record->refresh();
        $this->assertSame(DnsState::Active, $record->state);
        $this->assertNotNull($record->provider_record_id);
        $this->assertNull($record->failure_reason);
    }

    #[Test]
    public function a_delete_that_completed_after_all_is_settled(): void
    {
        $this->provider();
        $zone = $this->zoneHeldByTheProvider();

        $record = DnsRecord::factory()->create([
            'dns_zone_id' => $zone->getKey(),
            'type' => DnsRecordType::A,
            'name' => 'gone.example.test',
            'content' => '203.0.113.10',
            'state' => DnsState::Deleting,
        ]);

        $outcome = app(ReconcileZones::class)->execute();

        $this->assertSame(1, $outcome['settled']);

        // This is the answer the platform was waiting for. The Timeout Rule
        // says wait for it, and this is what waiting looks like when it pays.
        $this->assertSame(DnsState::Deleted, $record->refresh()->state);
    }

    #[Test]
    public function a_provider_that_will_not_answer_produces_no_drift_at_all(): void
    {
        $provider = $this->provider();

        $zone = DnsZone::factory()->active()->create([
            'name' => 'dns-timeout.test',
            'provider' => 'fake',
            // Never looked at, so the sweep will certainly pick it up: a
            // fixture that was merely fresh would pass this test without the
            // provider being asked anything at all.
            'last_synced_at' => null,
        ]);

        $provider->withZone('dns-timeout.test');

        DnsRecord::factory()->active()->create([
            'dns_zone_id' => $zone->getKey(),
            'name' => 'www.dns-timeout.test',
        ]);

        $outcome = app(ReconcileZones::class)->execute();

        /*
         * Nothing concluded and nothing stamped. A sweep that recorded
         * "missing at the provider" every time an API was down would fill an
         * operator's queue with the platform's own outage, and the one real
         * missing record would be somewhere in the middle of it.
         */
        $this->assertSame(0, $outcome['zones']);
        $this->assertSame(0, $outcome['drifts']);
        $this->assertSame(0, ResourceDrift::query()->count());
        $this->assertNull($zone->refresh()->last_synced_at);
    }

    #[Test]
    public function a_zone_looked_at_recently_is_left_for_the_next_run(): void
    {
        $this->provider();

        $zone = $this->zoneHeldByTheProvider();
        $zone->forceFill(['last_synced_at' => now()->subMinutes(5)])->save();

        DnsRecord::factory()->active()->create([
            'dns_zone_id' => $zone->getKey(),
            'name' => 'www.example.test',
        ]);

        // Bounded because a provider's API has a rate limit, and a sweep that
        // hits it reconciles nothing at all.
        $this->assertSame(0, app(ReconcileZones::class)->execute()['zones']);
    }

    #[Test]
    public function a_zone_at_the_provider_that_no_row_claims_is_reported(): void
    {
        $provider = $this->provider();
        $this->zoneHeldByTheProvider();

        // The shape a leaked zone really takes: a claim that timed out, where
        // the provider created the zone and the platform never learned its
        // identifier. Nothing per-zone would ever reach it — there is no local
        // row to start from — so it would sit there being billed for, and
        // serving whatever the last delegation pointed at.
        $provider->withZone('forgotten.test');

        $outcome = app(ReconcileZones::class)->execute();

        $this->assertSame(1, $outcome['drifts']);

        $drift = ResourceDrift::query()->where('resource_type', 'dns_zone')->firstOrFail();
        $this->assertSame(DriftKind::OrphanAtProvider->value, $drift->kind->value);

        // Reported, not reclaimed and not deleted: a deployment may share a
        // provider account with something that is not this platform.
        $this->assertNotNull($provider->findZone('forgotten.test'));
    }

    #[Test]
    public function the_command_reports_what_it_did(): void
    {
        $this->provider();
        $this->zoneHeldByTheProvider();

        $this->artisan('dns:reconcile')
            ->expectsOutputToContain('1 zones checked')
            ->assertSuccessful();
    }

    private function zoneHeldByTheProvider(): DnsZone
    {
        $held = $this->provider()->withZone('example.test');

        return DnsZone::factory()->active()->create([
            'name' => 'example.test',
            'provider' => 'fake',
            'provider_zone_id' => $held->id(),
            'last_synced_at' => null,
        ]);
    }
}
