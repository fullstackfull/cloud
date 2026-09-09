<?php

declare(strict_types=1);

namespace Tests\Feature\ProductReadiness;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ladder over HTTP, and the two things that matter most at this
 * boundary: that the last rung is a person's and cannot be reached by
 * configuration, and that a provider losing its footing takes the product
 * down with it in the same transaction — declaration and all.
 */
final class NothingIsSellableOnHopeTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'LYNOMIA_TEST_READINESS_SECRET';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        putenv(self::SECRET.'=not-a-real-secret');
    }

    protected function tearDown(): void
    {
        putenv(self::SECRET);

        parent::tearDown();
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * A provider the Providers module would call proven and an operator has
     * enabled, written directly: the real drivers have no tester in this
     * build, so this is the only road to a real, live provider in a test.
     */
    private function live(ProviderCategory $category, string $driver, DeploymentEnvironment $environment = DeploymentEnvironment::Production, ?CredentialReference $credential = null): ProviderInstance
    {
        $provider = ProviderInstance::factory()->of($category)->enabled()->create([
            'driver' => $driver,
            'environment' => $environment,
            'endpoint' => 'https://'.$driver.'.example.test',
            'credential_reference_id' => $credential?->getKey(),
        ]);

        foreach ($category->capabilities() as $capability) {
            ProviderCapability::factory()->named($capability)->create(['provider_instance_id' => $provider->getKey()]);
        }

        return $provider;
    }

    private function sharedMet(): void
    {
        $this->live(ProviderCategory::Payment, 'stripe');
        $this->live(ProviderCategory::Email, 'smtp');
    }

    private function state(Product $product): ProductReadinessState
    {
        return ProductReadiness::query()->where('product', $product->value)->firstOrFail()->state;
    }

    #[Test]
    public function a_fresh_install_is_assessed_on_first_read_and_nothing_is_ready(): void
    {
        $response = $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/readiness/products');

        $response->assertOk();
        $response->assertJsonCount(count(Product::cases()), 'data');

        foreach ($response->json('data') as $row) {
            $this->assertSame('not_ready', $row['state']);
            $this->assertSame('ready_for_test', $row['next_state']);
            $this->assertNotNull($row['blocker']);
            $this->assertNotNull($row['next_action']);
        }

        // Dependencies first: shared hosting before WordPress, VPS before backups.
        $order = array_column($response->json('data'), 'product');
        $this->assertLessThan(array_search('wordpress', $order, strict: true), array_search('shared_hosting', $order, strict: true));
        $this->assertLessThan(array_search('backups', $order, strict: true), array_search('vps', $order, strict: true));
    }

    #[Test]
    public function a_customer_and_a_support_agent_cannot_read_it(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/readiness/products')->assertForbidden();
        $this->actingAs($this->operator(Role::Support))->getJson('/api/admin/readiness/products')->assertForbidden();
    }

    #[Test]
    public function a_controlled_provider_carries_a_product_to_ready_for_test_and_no_further(): void
    {
        $this->sharedMet();
        // Enabled, in production, fully capable — and a fake. The registration
        // endpoint refuses this combination; the ladder must refuse it even
        // when the row exists.
        $this->live(ProviderCategory::Dns, 'fake');

        $response = $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/assess');

        $response->assertOk();
        $response->assertJsonPath('data.state', 'ready_for_test');
        $response->assertJsonPath('data.next_state', 'ready_for_real_validation');
        $this->assertStringContainsString('controlled fake driver', $response->json('data.detail'));

        $declared = $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/sellable', [
            'reason' => 'It works on the fake.',
            'validation_reference' => 'TICKET-1',
        ]);

        $declared->assertConflict();
        $declared->assertJsonPath('error.code', 'readiness_refused');
        $this->assertStringContainsString('not ready for production', $declared->json('error.message'));
        $this->assertSame(ProductReadinessState::ReadyForTest, $this->state(Product::Dns));
    }

    #[Test]
    public function declaring_a_product_sellable_is_its_own_permission_that_the_infrastructure_admin_does_not_hold(): void
    {
        $this->actingAs($this->operator(Role::InfrastructureAdmin))
            ->postJson('/api/admin/readiness/products/dns/sellable', ['reason' => 'Go.', 'validation_reference' => 'X-1'])
            ->assertForbidden();
    }

    #[Test]
    public function a_declaration_needs_a_reason_and_a_reference_to_the_validation(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/api/admin/readiness/products/dns/sellable', ['reason' => 'Validated.'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['validation_reference']]]]);
    }

    #[Test]
    public function a_product_on_real_live_providers_reaches_production_and_a_person_takes_it_to_sell(): void
    {
        $this->sharedMet();
        $this->live(ProviderCategory::Dns, 'cloudflare');

        $assessed = $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/assess');
        $assessed->assertJsonPath('data.state', 'ready_for_production');
        $assessed->assertJsonPath('data.blocker', null);
        $assessed->assertJsonPath('data.sellable.declared', false);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ProductReadinessChanged->value]);

        $declared = $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/sellable', [
            'reason' => 'Ten zones created, edited and deleted against the live account.',
            'validation_reference' => 'docs/validation/dns-2026-09.md',
        ]);

        $declared->assertOk();
        $declared->assertJsonPath('data.state', 'ready_to_sell');
        $declared->assertJsonPath('data.next_state', null);
        $declared->assertJsonPath('data.sellable.declared', true);
        $declared->assertJsonPath('data.sellable.validation_reference', 'docs/validation/dns-2026-09.md');
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ProductDeclaredSellable->value]);

        // A reassessment keeps the declaration while the evidence holds.
        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/assess')
            ->assertJsonPath('data.state', 'ready_to_sell');
    }

    #[Test]
    public function a_provider_losing_its_credential_takes_the_product_and_its_declaration_down_in_the_same_transaction(): void
    {
        $this->sharedMet();
        $credential = CredentialReference::factory()->create([
            'state' => CredentialState::Valid,
            'environment' => DeploymentEnvironment::Production,
            'backend_reference' => self::SECRET,
        ]);
        $dns = $this->live(ProviderCategory::Dns, 'cloudflare', credential: $credential);

        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/assess');
        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/sellable', [
            'reason' => 'Validated against the live account.',
            'validation_reference' => 'TICKET-77',
        ])->assertOk();
        $this->assertSame(ProductReadinessState::ReadyToSell, $this->state(Product::Dns));

        // Nothing here mentions products. The credential is revoked, the
        // provider is reassessed by the Providers module, and the event it
        // raises is what reaches the product.
        $this->actingAs($this->operator())
            ->postJson('/api/admin/credentials/'.$credential->getKey().'/revoke', ['reason' => 'Leaked in a screenshot.'])
            ->assertOk();

        // The provider stays Enabled — nothing switches a provider off on a
        // key event, by design — but it is no longer proven, and that is what
        // the product reads.
        $this->assertSame(ProviderState::Enabled, $dns->fresh()->state);
        $this->assertSame(ReadinessState::NotReady, $dns->fresh()->readiness);
        $this->assertSame(BlockerReason::Credentials, $dns->fresh()->blocker);
        $this->assertSame(ProductReadinessState::NotReady, $this->state(Product::Dns));

        $row = ProductReadiness::query()->where('product', 'dns')->firstOrFail();
        $this->assertFalse($row->isDeclaredSellable());
        $this->assertNotNull($row->sellability_withdrawn_at);
        $this->assertStringStartsWith('Withdrawn by assessment', $row->sellability_withdrawn_reason);
        // The declaration itself is kept, so the withdrawal says what was withdrawn.
        $this->assertSame('TICKET-77', $row->validation_reference);

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ProductSellabilityWithdrawn->value]);

        // The screen reads the provider's blocker on the product row.
        $shown = $this->actingAs($this->operator())->getJson('/api/admin/readiness/products/dns');
        $shown->assertJsonPath('data.state', 'not_ready');
        $this->assertStringStartsWith('dns:', $shown->json('data.detail'));
        $this->assertSame('cloudflare', $dns->fresh()->driver);
    }

    #[Test]
    public function a_person_can_take_the_declaration_back_and_the_product_returns_to_what_its_providers_support(): void
    {
        $this->sharedMet();
        $this->live(ProviderCategory::Dns, 'cloudflare');
        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/assess');
        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/dns/sellable', [
            'reason' => 'Validated.',
            'validation_reference' => 'TICKET-1',
        ])->assertOk();

        $withdrawn = $this->actingAs($this->operator())->deleteJson('/api/admin/readiness/products/dns/sellable', [
            'reason' => 'Pricing not signed off.',
        ]);

        $withdrawn->assertOk();
        $withdrawn->assertJsonPath('data.state', 'ready_for_production');
        $withdrawn->assertJsonPath('data.sellable.declared', false);
        $withdrawn->assertJsonPath('data.sellable.withdrawn_reason', 'Pricing not signed off.');

        // Withdrawing what was never declared is a refusal, not a no-op.
        $this->actingAs($this->operator())->deleteJson('/api/admin/readiness/products/vps/sellable', ['reason' => 'x?'])
            ->assertUnprocessable();
        $this->actingAs($this->operator())->deleteJson('/api/admin/readiness/products/vps/sellable', ['reason' => 'Never declared.'])
            ->assertConflict();
    }

    #[Test]
    public function a_dependency_that_is_behind_holds_the_dependent_back_and_the_dependency_view_says_so(): void
    {
        $this->sharedMet();
        $this->live(ProviderCategory::WordPressInstaller, 'cpanel');
        // Shared hosting itself only has a fake.
        $this->live(ProviderCategory::Hosting, 'fake');

        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/assess')
            ->assertOk()
            ->assertJsonPath('data.examined', count(Product::cases()))
            ->assertJsonPath('data.products.shared_hosting', 'ready_for_test')
            ->assertJsonPath('data.products.wordpress', 'ready_for_test');

        $wordpress = $this->actingAs($this->operator())->getJson('/api/admin/readiness/products/wordpress');
        $wordpress->assertJsonPath('data.blocker', 'blocked_dependency');
        $wordpress->assertJsonPath('data.dependencies.shared_hosting', 'ready_for_test');
        $wordpress->assertJsonPath('data.depends_on.0', 'shared_hosting');

        $view = $this->actingAs($this->operator(Role::Noc))->getJson('/api/admin/readiness/dependencies');
        $view->assertOk();
        $node = collect($view->json('data'))->firstWhere('product', 'wordpress');
        $this->assertSame(['shared_hosting'], $node['depends_on']);
        $installer = collect($node['providers'])->firstWhere('category', 'wordpress_installer');
        $this->assertSame('ready_for_production', $installer['satisfied_up_to']);
        $this->assertFalse($installer['shared']);
        $this->assertTrue(collect($node['providers'])->firstWhere('category', 'payment')['shared']);
    }

    #[Test]
    public function a_sweep_that_changes_nothing_writes_no_audit_rows(): void
    {
        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/assess')->assertOk();
        $before = DB::table('audit_log')->where('action', AuditAction::ProductReadinessChanged->value)->count();

        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/assess')
            ->assertOk()
            ->assertJsonPath('data.changed', 0);

        $this->assertSame($before, DB::table('audit_log')->where('action', AuditAction::ProductReadinessChanged->value)->count());
    }
}
