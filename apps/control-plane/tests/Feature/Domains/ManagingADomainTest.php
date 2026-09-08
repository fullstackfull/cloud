<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a customer can do with a name they already hold.
 */
final class ManagingADomainTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    private Domain $domain;

    private string $registrarState;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The fake keeps what it holds in a file, so that a web request and a
         * worker in another process see the same registrar. Every test here
         * acts on a name that has to already exist at the registrar — the fake
         * refuses to change the delegation of a name it does not hold, which
         * is exactly what a real registrar does.
         */
        $this->registrarState = tempnam(sys_get_temp_dir(), 'domains-fake-');

        config([
            'domains.fake.tlds' => ['test'],
            'domains.fake.state_path' => $this->registrarState,
        ]);

        DomainTld::factory()->onSale()->named('test')->create();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = $this->memberOf($this->customer, CustomerRole::Owner);

        $this->domain = Domain::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => 'mine.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
            'expires_at' => now()->addYear(),
        ]);

        app(DomainRegistrarFactory::class)
            ->make('fake')
            ->register(new RegistrationRequest('mine.test', 1, []));
    }

    protected function tearDown(): void
    {
        @unlink($this->registrarState);

        parent::tearDown();
    }

    private function memberOf(Customer $customer, CustomerRole $role): User
    {
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function acting(?Customer $customer = null): array
    {
        return ['X-Lynomia-Customer' => (string) ($customer ?? $this->customer)->getKey()];
    }

    #[Test]
    public function the_delegation_can_be_changed(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/'.$this->domain->getKey().'/nameservers', [
                'nameservers' => ['ns1.lynomia.test', 'ns2.lynomia.test'],
            ])
            ->assertOk()
            ->assertJsonPath('data.nameservers', ['ns1.lynomia.test', 'ns2.lynomia.test']);
    }

    #[Test]
    public function a_single_nameserver_is_refused(): void
    {
        // It resolves right up until that one host reboots, and then the
        // customer's whole domain is dark.
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/'.$this->domain->getKey().'/nameservers', [
                'nameservers' => ['ns1.lynomia.test'],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function the_transfer_lock_can_be_taken_off_and_the_act_is_recorded(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/'.$this->domain->getKey().'/transfer-lock', ['locked' => false])
            ->assertOk()
            ->assertJsonPath('data.transfer_locked', false);

        /*
         * Unlocking is one of the two steps by which a name is stolen. It is
         * not made difficult — the customer owns the domain — but it is
         * recorded, so that "unlocked, then the code taken a minute later" is
         * a query rather than an eyeball exercise.
         */
        $this->assertSame(1, AuditEntry::query()
            ->where('action', AuditAction::DomainUnlocked->value)
            ->count());
    }

    #[Test]
    public function an_authorisation_code_is_issued_and_never_written_down(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$this->domain->getKey().'/authorisation-code')
            ->assertOk();

        $code = (string) $response->json('data.authorisation_code');
        $this->assertNotSame('', $code);

        $entry = AuditEntry::query()
            ->where('action', AuditAction::DomainAuthorisationCodeIssued->value)
            ->firstOrFail();

        // A bearer credential for the whole domain does not belong in a record
        // operators read for years.
        $this->assertStringNotContainsString($code, json_encode($entry->context, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_member_who_may_only_look_cannot_take_the_authorisation_code(): void
    {
        $reader = $this->memberOf($this->customer, CustomerRole::Member);

        $this->actingAs($reader)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$this->domain->getKey().'/authorisation-code')
            ->assertForbidden();
    }

    #[Test]
    public function another_accounts_domain_is_not_found_rather_than_forbidden(): void
    {
        $stranger = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $strangerOwner = $this->memberOf($stranger, CustomerRole::Owner);

        // A 403 here would confirm that this id is somebody's domain.
        $this->actingAs($strangerOwner)
            ->withHeaders($this->acting($stranger))
            ->getJson('/api/v1/domains/'.$this->domain->getKey())
            ->assertNotFound();
    }

    #[Test]
    public function the_registrant_can_be_changed(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/'.$this->domain->getKey().'/contacts', [
                'registrant' => [
                    'name' => 'Omar Saleh',
                    'email' => 'omar@example.test',
                    'phone' => '+96550000001',
                    'address_line_one' => '5 Salmiya Street',
                    'city' => 'Kuwait City',
                    'country' => 'KW',
                ],
            ])
            ->assertOk();

        $this->assertSame('Omar Saleh', $this->domain->contacts()->firstOrFail()->name);
    }

    #[Test]
    public function nothing_can_be_managed_on_a_name_the_platform_is_unsure_of(): void
    {
        $this->domain->forceFill(['state' => DomainState::Indeterminate])->save();

        /*
         * The Timeout Rule reaching the screen. A name whose registration the
         * platform could not confirm must not accept a delegation change: the
         * change would either fail or apply to a registration nobody has
         * established exists.
         */
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->putJson('/api/v1/domains/'.$this->domain->getKey().'/nameservers', [
                'nameservers' => ['ns1.lynomia.test', 'ns2.lynomia.test'],
            ])
            ->assertStatus(409);
    }

    #[Test]
    public function the_list_says_whether_a_name_is_about_to_lapse(): void
    {
        $this->domain->forceFill(['expires_at' => now()->addDays(10)])->save();

        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/domains')
            ->assertOk()
            ->assertJsonPath('data.0.is_expiring', true)
            ->assertJsonPath('data.0.auto_renew', true);
    }
}
