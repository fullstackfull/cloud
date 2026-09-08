<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Giving a domain up.
 *
 * The most destructive act on this surface. A zone that is gone answers
 * NXDOMAIN for every name under it at once — the website, the mail, and the
 * things somebody set up years ago and forgot about — so the tests here are
 * about how hard it is to do by accident, and about the platform not claiming
 * the domain has gone when it does not know that.
 */
final class GivingUpAZoneTest extends DnsTestCase
{
    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->customer, $this->owner] = $this->accountWithOwner();
        $this->provider();
    }

    #[Test]
    public function it_takes_the_domain_typed_back(): void
    {
        $id = $this->claim('example.test');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson('/api/v1/dns/zones/'.$id, ['confirm_zone_name' => 'exampl.test'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dns.zone.not_confirmed');

        // Still there, and still serving.
        $this->assertNotNull($this->provider()->findZone('example.test'));
    }

    #[Test]
    public function the_zone_and_every_record_in_it_go(): void
    {
        $id = $this->claim('example.test');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/dns/zones/'.$id.'/records', [
                'type' => 'A', 'name' => 'www.example.test', 'content' => '203.0.113.10',
            ])->assertCreated();

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson('/api/v1/dns/zones/'.$id, ['confirm_zone_name' => 'example.test'])
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Deleted->value);

        $this->assertNull($this->provider()->findZone('example.test'));

        // Marked gone locally too. A record row still reading `active` under a
        // zone that no longer exists is a screen telling somebody their name
        // resolves.
        $this->assertSame(
            1,
            DnsRecord::query()->where('state', DnsState::Deleted->value)->count(),
        );
    }

    #[Test]
    public function a_released_zone_leaves_the_list_and_the_name_can_be_claimed_again(): void
    {
        $id = $this->claim('example.test');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson('/api/v1/dns/zones/'.$id, ['confirm_zone_name' => 'example.test'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->getJson('/api/v1/dns/zones')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // The unique index is on live rows only: a domain somebody gave up is
        // a domain anybody may claim, which is what DNS itself does.
        $this->claim('example.test');
    }

    #[Test]
    public function a_provider_that_does_not_answer_leaves_the_zone_where_it_is(): void
    {
        $id = $this->claim('dns-timeout.test');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson('/api/v1/dns/zones/'.$id, ['confirm_zone_name' => 'dns-timeout.test'])
            ->assertOk()
            ->assertJsonPath('data.state', DnsState::Indeterminate->value);

        /*
         * Not deleted. Saying so would tell the customer their names have
         * stopped resolving while they may still be live — and it is the
         * customer who would then be surprised, not the platform.
         */
        $zone = DnsZone::query()->findOrFail($id);
        $this->assertSame(DnsState::Indeterminate, $zone->state);
    }

    #[Test]
    public function giving_a_zone_up_is_audited(): void
    {
        $id = $this->claim('example.test');

        $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->deleteJson('/api/v1/dns/zones/'.$id, ['confirm_zone_name' => 'example.test'])
            ->assertOk();

        $entry = AuditEntry::query()->where('action', AuditAction::DnsZoneDeleted->value)->firstOrFail();

        $this->assertSame('example.test', $entry->context['zone'] ?? null);
    }

    private function claim(string $name): string
    {
        return (string) $this->actingAs($this->owner)
            ->withHeaders($this->actingFor($this->customer))
            ->postJson('/api/v1/dns/zones', ['name' => $name])
            ->assertCreated()
            ->json('data.id');
    }
}
