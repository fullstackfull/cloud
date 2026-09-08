<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Dns\Application\Actions\ReconcileZones;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A domain, from the day an account claims it to the day it gives it up.
 *
 * Told through the HTTP surface a customer actually uses, against the fake
 * provider, because what this is testing is the *product*: that each step
 * reaches the zone, that the platform's record and the zone agree, and that
 * every claim made along the way is one the platform could actually support.
 *
 * Nothing here is REAL_INFRA_VERIFIED. A real Cloudflare account is Phase 30B.
 */
final class TheWholeLifeOfADnsZoneTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    private FakeDnsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // One provider for the whole story. The factory is deliberately not a
        // singleton, so without this the zone created in one request would not
        // be there in the next.
        $this->app->singleton(DnsProviderFactory::class);

        $this->provider = new FakeDnsProvider;
        app(DnsProviderFactory::class)->swap($this->provider);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = User::factory()->create();

        $this->customer->members()->create([
            'user_id' => $this->owner->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);
    }

    #[Test]
    public function a_customer_claims_a_domain_serves_it_and_gives_it_up(): void
    {
        // ---------------------------------------------------------------
        // Claimed, and the zone created at the provider
        // ---------------------------------------------------------------
        $zoneId = (string) $this->request()
            ->postJson('/api/v1/dns/zones', ['name' => 'lynomia-customer.test'])
            ->assertCreated()
            ->assertJsonPath('data.state', DnsState::Active->value)
            ->json('data.id');

        $atProvider = $this->provider->findZone('lynomia-customer.test');
        $this->assertNotNull($atProvider, 'The zone was claimed and the provider has none.');

        // The nameservers are the product: without them the customer has
        // nothing to type at their registrar, and the zone serves nobody.
        $this->assertNotEmpty(DnsZone::query()->findOrFail($zoneId)->nameservers);

        // ---------------------------------------------------------------
        // The three records a domain actually needs
        // ---------------------------------------------------------------
        $aRecord = (string) $this->add($zoneId, [
            'type' => 'A', 'name' => 'www.lynomia-customer.test', 'content' => '203.0.113.10',
        ])->assertCreated()->assertJsonPath('data.is_live', true)->json('data.id');

        $this->add($zoneId, [
            'type' => 'MX', 'name' => 'lynomia-customer.test',
            'content' => 'mail.lynomia-customer.test', 'priority' => 10,
        ])->assertCreated();

        $this->add($zoneId, [
            'type' => 'TXT', 'name' => 'lynomia-customer.test', 'content' => 'v=spf1 -all',
        ])->assertCreated();

        $this->assertCount(3, $this->provider->records($atProvider));

        // ---------------------------------------------------------------
        // Changed — and the zone says the new thing, not both
        // ---------------------------------------------------------------
        $this->request()
            ->patchJson('/api/v1/dns/zones/'.$zoneId.'/records/'.$aRecord, ['content' => '203.0.113.20'])
            ->assertOk()
            ->assertJsonPath('data.content', '203.0.113.20');

        $live = $this->provider->records($atProvider, DnsRecordType::A, 'www.lynomia-customer.test');

        $this->assertCount(1, $live);
        $this->assertSame('203.0.113.20', $live[0]->content());

        // ---------------------------------------------------------------
        // Somebody edits the zone behind the platform's back
        // ---------------------------------------------------------------
        /*
         * Through the provider's own console, which is how it really happens.
         * The sweep must notice and must not touch it: a customer's zone is
         * not this platform's document, and a record removed for not being in
         * this database is somebody's mail routing at three in the morning.
         */
        $this->provider->publish($atProvider, DnsRecordValue::of(
            DnsRecordType::TXT,
            'somebody-elses.lynomia-customer.test',
            'added through the provider console',
        ));

        DnsZone::query()->whereKey($zoneId)->update(['last_synced_at' => null]);

        $outcome = app(ReconcileZones::class)->execute();

        $this->assertSame(1, $outcome['zones']);
        $this->assertSame(1, $outcome['drifts']);

        $this->assertTrue(
            ResourceDrift::query()->where('kind', DriftKind::OrphanAtProvider->value)->exists(),
        );

        // Still there, and still not adopted.
        $this->assertCount(4, $this->provider->records($atProvider));
        $this->assertSame(3, DnsRecord::query()->where('dns_zone_id', $zoneId)->count());

        // ---------------------------------------------------------------
        // A record removed
        // ---------------------------------------------------------------
        $this->request()
            ->deleteJson('/api/v1/dns/zones/'.$zoneId.'/records/'.$aRecord)
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Deleted->value);

        $this->assertCount(0, $this->provider->records($atProvider, DnsRecordType::A));

        // ---------------------------------------------------------------
        // Given up
        // ---------------------------------------------------------------
        $this->request()
            ->deleteJson('/api/v1/dns/zones/'.$zoneId, ['confirm_zone_name' => 'lynomia-customer.test'])
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Deleted->value);

        $this->assertNull(
            $this->provider->findZone('lynomia-customer.test'),
            'The zone was given up and the provider still holds it.',
        );

        // Every record with it, and marked so locally: a row still reading
        // `active` under a zone that no longer exists is a screen telling
        // somebody their name resolves.
        $this->assertSame(
            0,
            DnsRecord::query()
                ->where('dns_zone_id', $zoneId)
                ->where('state', '!=', DnsState::Deleted->value)
                ->count(),
        );

        // ---------------------------------------------------------------
        // And the trail
        // ---------------------------------------------------------------
        foreach ([
            AuditAction::DnsZoneCreated,
            AuditAction::DnsRecordCreated,
            AuditAction::DnsRecordUpdated,
            AuditAction::DnsRecordDeleted,
            AuditAction::DnsZoneDeleted,
        ] as $action) {
            $this->assertTrue(
                AuditEntry::query()->where('action', $action->value)->exists(),
                $action->value.' was never written.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function add(string $zoneId, array $payload): TestResponse
    {
        return $this->request()->postJson('/api/v1/dns/zones/'.$zoneId.'/records', $payload);
    }

    private function request(): self
    {
        $this->actingAs($this->owner);

        return $this->withHeaders(['X-Lynomia-Customer' => (string) $this->customer->getKey()]);
    }
}
