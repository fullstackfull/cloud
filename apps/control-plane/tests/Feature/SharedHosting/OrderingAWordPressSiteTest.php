<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Asking for a WordPress site, and the four answers to "which name".
 */
final class OrderingAWordPressSiteTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = $this->memberOf($this->customer, CustomerRole::Owner);
        $this->plan = $this->hostingPlan();
    }

    /**
     * A plan and the panel package that goes with it.
     *
     * Both, always. A plan without a package cannot be bought at all, and a
     * fixture that made one without the other would let these tests pass over
     * an order no customer could actually place.
     */
    private function hostingPlan(): Plan
    {
        $product = Product::factory()->create(['kind' => 'shared_hosting']);

        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => 'wordpress-'.uniqid(),
            'resources' => [
                'disk_quota_mib' => 10_240,
                'bandwidth_quota_mib' => 512_000,
                'max_addon_domains' => 10,
                'max_databases' => 10,
            ],
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
            'setup_amount_minor' => 0,
        ]);

        HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'slug' => 'pkg-wordpress-'.uniqid(),
            'panel_package_name' => 'lyn_wordpress',
            'disk_quota_mib' => 10_240,
        ]);

        return $plan->fresh(['prices', 'product']) ?? $plan;
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
    private function acting(): array
    {
        return ['X-Lynomia-Customer' => (string) $this->customer->getKey()];
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function order(array $overrides = []): array
    {
        return [
            'domain' => 'mysite.test',
            'domain_source' => WordPressDomainSource::External->value,
            'plan_id' => (string) $this->plan->getKey(),
            'admin_username' => 'sitemanager',
            'admin_email' => 'owner@mysite.test',
            ...$overrides,
        ];
    }

    #[Test]
    public function an_external_name_starts_by_waiting_on_the_customers_registrar(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order())
            ->assertCreated();

        /*
         * The first screen says what the customer has to go and do. A spinner
         * would be a lie: nothing here happens until somebody edits DNS at
         * another company, and the platform cannot make that happen.
         */
        $response->assertJsonPath('data.state', WordPressSiteState::AwaitingDns->value)
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.installed', false);
    }

    #[Test]
    public function a_name_already_held_here_starts_ready_to_build(): void
    {
        Domain::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => 'ours.test',
            'tld' => 'test',
            'state' => DomainState::Active,
        ]);

        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order([
                'domain' => 'ours.test',
                'domain_source' => WordPressDomainSource::Existing->value,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.state', WordPressSiteState::Requested->value)
            ->assertJsonPath('data.domain_source', WordPressDomainSource::Existing->value);

        // Linked to the domain row, so the delegation can be made without
        // anybody having to match names by hand.
        $this->assertNotNull(WordPressSite::query()->where('domain', 'ours.test')->value('domain_id'));
    }

    #[Test]
    public function claiming_a_name_is_held_here_when_it_is_not_is_refused(): void
    {
        /*
         * Refused rather than quietly downgraded to "external", which would
         * leave the customer waiting for a delegation nobody is going to make.
         */
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order([
                'domain' => 'notours.test',
                'domain_source' => WordPressDomainSource::Existing->value,
            ]))
            ->assertStatus(409);
    }

    #[Test]
    public function another_accounts_domain_does_not_count_as_held_here(): void
    {
        $stranger = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        Domain::factory()->create([
            'customer_id' => $stranger->getKey(),
            'name' => 'theirs.test',
            'tld' => 'test',
            'state' => DomainState::Active,
        ]);

        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order([
                'domain' => 'theirs.test',
                'domain_source' => WordPressDomainSource::Existing->value,
            ]))
            ->assertStatus(409);
    }

    #[Test]
    public function two_sites_cannot_answer_for_one_name(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order())
            ->assertCreated();

        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order())
            ->assertStatus(409);
    }

    #[Test]
    public function the_obvious_administrator_names_are_refused(): void
    {
        foreach (['admin', 'administrator', 'root'] as $username) {
            $this->actingAs($this->owner)
                ->withHeaders($this->acting())
                ->postJson('/api/v1/wordpress/sites', $this->order(['admin_username' => $username]))
                ->assertStatus(422);
        }
    }

    #[Test]
    public function the_administrators_address_is_encrypted_and_never_published(): void
    {
        $response = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order())
            ->assertCreated();

        $this->assertStringNotContainsString('owner@mysite.test', (string) $response->getContent());

        $stored = (string) DB::table('wordpress_sites')
            ->where('domain', 'mysite.test')
            ->value('admin_email');

        $this->assertNotSame('owner@mysite.test', $stored);
        $this->assertStringNotContainsString('owner@', $stored);
    }

    #[Test]
    public function the_order_is_audited_without_the_administrators_address(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order())
            ->assertCreated();

        $entry = AuditEntry::query()
            ->where('action', AuditAction::WordPressSiteOrdered->value)
            ->sole();

        $this->assertSame('mysite.test', $entry->context['domain'] ?? null);
        $this->assertStringNotContainsString(
            'owner@',
            json_encode($entry->context, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function a_member_who_may_only_look_cannot_order_a_site(): void
    {
        $reader = $this->memberOf($this->customer, CustomerRole::Member);

        $this->actingAs($reader)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/wordpress/sites', $this->order())
            ->assertForbidden();
    }

    #[Test]
    public function another_accounts_site_is_not_found_rather_than_forbidden(): void
    {
        $site = WordPressSite::factory()->create(['domain' => 'private.test']);

        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/wordpress/sites/'.$site->getKey())
            ->assertNotFound();
    }
}
