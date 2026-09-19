<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Copies of a WordPress site and the push back, from the ask to the row.
 *
 * What the suite establishes: that copying is a type question answered by
 * the panel (the fake can; a site with no panel cannot, and the row says
 * so); that a staging copy is a site of its own, verified like any other,
 * and the only kind that can be pushed; that a push says what it
 * overwrites and what the platform holds no backup of, needs the
 * production domain typed, and follows the Timeout Rule; and that every
 * ask is audited and every push outcome told.
 */
final class TheWholeLifeOfAWordPressCopyTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    private HostingNode $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HostingProviderFactory::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = User::factory()->create();
        $this->customer->members()->create(['user_id' => $this->owner->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);
        $this->node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);
    }

    #[Test]
    public function a_site_is_copied_to_staging_pushed_back_over_production_with_the_domain_typed_and_everything_is_recorded(): void
    {
        $site = $this->liveSite('shop.test');

        // The row says what the toolkit can do.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/wordpress/sites/'.$site->id)
            ->assertOk()
            ->assertJsonPath('data.kind', 'production')
            ->assertJsonPath('data.copies.staging', true)
            ->assertJsonPath('data.copies.clone', true)
            ->assertJsonPath('data.copies.push_to_production', false);

        // The copy: accepted, run, and a staging site of its own.
        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$site->id.'/staging')
            ->assertStatus(202)
            ->assertJsonPath('data.kind', 'create_staging');

        $operation = WordPressSiteOperation::query()->sole();
        $this->assertSame(WordPressOperationState::Succeeded, $operation->state);

        $staging = WordPressSite::query()->where('parent_site_id', $site->id)->sole();
        $this->assertSame('staging.shop.test', $staging->domain);
        $this->assertSame(WordPressSiteKind::Staging, $staging->kind);
        $this->assertTrue($staging->installed);
        $this->assertSame(WordPressSiteState::AwaitingCertificate, $staging->state);
        $this->assertSame((string) $this->customer->id, $staging->customer_id);
        $this->assertSame($site->hosting_account_id, $staging->hosting_account_id);

        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::WordPressStagingRequested->value)->count());

        // One staging copy per site.
        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$site->id.'/staging')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wordpress.staging_exists');

        // The push needs the production domain, exactly, and says what it costs.
        $impact = $this->actingAs($this->owner)
            ->getJson('/api/v1/wordpress/sites/'.$staging->id.'/push/impact?scope=both')
            ->assertOk()
            ->assertJsonPath('data.production_domain', 'shop.test')
            ->assertJsonPath('data.platform_backup', null);
        $warnings = implode(' ', $impact->json('data.warnings'));
        $this->assertStringContainsString('holds no backup', $warnings);
        $this->assertStringContainsString('every post, comment, order', $warnings);

        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$staging->id.'/push', ['confirmation' => 'SHOP.TEST'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'wordpress.push_confirmation_mismatch');

        // Only a staging copy pushes; production has nothing to push over.
        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$site->id.'/push', ['confirmation' => 'shop.test'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'wordpress.not_a_staging_copy');

        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$staging->id.'/push', ['confirmation' => 'shop.test', 'scope' => 'both'])
            ->assertStatus(202)
            ->assertJsonPath('data.kind', 'push_to_production')
            ->assertJsonPath('data.state', WordPressOperationState::Succeeded->value)
            ->assertJsonPath('data.impact.production_domain', 'shop.test');

        // Production is now the copy and has not been looked at since.
        $this->assertNull($site->refresh()->verified_at);
        $this->assertSame(WordPressSiteState::Ready, $site->state);

        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::WordPressPushRequested->value)->count());
        $this->assertSame(1, Notification::query()->where('type', NotificationType::WordPressPushCompleted->value)->count());

        $this->actingAs($this->owner)
            ->getJson('/api/v1/wordpress/sites/'.$site->id.'/operations')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_clone_is_a_production_site_of_its_own_and_a_taken_name_is_refused(): void
    {
        $site = $this->liveSite('agency.test');

        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$site->id.'/clones', ['domain' => ' Client-Two.test. '])
            ->assertStatus(202)
            ->assertJsonPath('data.kind', 'clone');

        $clone = WordPressSite::query()->where('domain', 'client-two.test')->sole();
        $this->assertSame(WordPressSiteKind::Clone, $clone->kind);
        $this->assertSame((string) $site->id, $clone->parent_site_id);
        $this->assertFalse($clone->dns_ready);
        $this->assertTrue($clone->installed);

        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$site->id.'/clones', ['domain' => 'client-two.test'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wordpress.domain_in_use');

        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$site->id.'/clones', ['domain' => 'nodots'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'wordpress.domain_unusable');

        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::WordPressCloneRequested->value)->count());
    }

    #[Test]
    public function a_push_the_toolkit_never_answers_leaves_production_in_review_and_is_never_retried(): void
    {
        $site = $this->liveSite('store-'.FakeHostingProvider::PUSH_TIMEOUT_MARKER.'.test');

        $this->actingAs($this->owner)->postJson('/api/v1/wordpress/sites/'.$site->id.'/staging')->assertStatus(202);
        $staging = WordPressSite::query()->where('parent_site_id', $site->id)->sole();

        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$staging->id.'/push', ['confirmation' => $site->domain])
            ->assertStatus(202)
            ->assertJsonPath('data.state', WordPressOperationState::Indeterminate->value)
            ->assertJsonPath('data.needs_attention', true);

        $this->assertSame(WordPressSiteState::NeedsReview, $site->refresh()->state);
        $this->assertStringContainsString('check it before pushing again', (string) $site->review_reason);
        $this->assertSame(1, Notification::query()->where('type', NotificationType::WordPressPushNeedsReview->value)->count());

        // Nothing involving either site may start until a person settles it.
        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$staging->id.'/push', ['confirmation' => $site->domain])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wordpress.operation_in_flight');

        $this->actingAs($this->owner)
            ->getJson('/api/v1/wordpress/sites/'.$staging->id)
            ->assertOk()
            ->assertJsonPath('data.copies.push_to_production', false);

        $this->assertSame(1, WordPressSiteOperation::query()->where('kind', 'push_to_production')->count());
    }

    #[Test]
    public function a_refused_push_leaves_production_exactly_as_it_was_and_a_refused_copy_leaves_a_failed_row(): void
    {
        $site = $this->liveSite('shop-'.FakeHostingProvider::PUSH_REFUSED_MARKER.'.test');

        $this->actingAs($this->owner)->postJson('/api/v1/wordpress/sites/'.$site->id.'/staging')->assertStatus(202);
        $staging = WordPressSite::query()->where('parent_site_id', $site->id)->sole();
        $verifiedAt = $site->verified_at;

        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$staging->id.'/push', ['confirmation' => $site->domain])
            ->assertStatus(202)
            ->assertJsonPath('data.state', WordPressOperationState::Failed->value);

        $this->assertSame(WordPressSiteState::Ready, $site->refresh()->state);
        $this->assertEquals($verifiedAt, $site->verified_at);
        $this->assertSame(1, Notification::query()->where('type', NotificationType::WordPressPushFailed->value)->count());

        // Failed is settled: the customer may push again.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/wordpress/sites/'.$staging->id)
            ->assertOk()
            ->assertJsonPath('data.copies.push_to_production', true);

        // A refused copy: the target row is failed and nothing else changed.
        $other = $this->liveSite('blog.test');
        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$other->id.'/clones', ['domain' => 'copy-'.FakeHostingProvider::COPY_REFUSED_MARKER.'.test'])
            ->assertStatus(202)
            ->assertJsonPath('data.state', WordPressOperationState::Failed->value);

        $this->assertSame(WordPressSiteState::Failed, WordPressSite::query()->where('domain', 'copy-copy-refused.test')->sole()->state);
    }

    #[Test]
    public function a_site_without_a_panel_that_can_copy_says_so_on_the_row_and_refuses_every_copy_route(): void
    {
        // A finished site the platform holds no hosting account for.
        $site = WordPressSite::factory()->live()->create(['customer_id' => $this->customer->id, 'domain' => 'elsewhere.test']);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/wordpress/sites/'.$site->id)
            ->assertOk()
            ->assertJsonPath('data.copies.staging', false)
            ->assertJsonPath('data.copies.push_to_production', false);

        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$site->id.'/staging')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'wordpress.panel_cannot_copy');

        // A site that is not finished cannot be copied either.
        $building = WordPressSite::factory()->create(['customer_id' => $this->customer->id, 'domain' => 'building.test']);
        $this->actingAs($this->owner)
            ->postJson('/api/v1/wordpress/sites/'.$building->id.'/staging')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'wordpress.site_not_ready');

        $this->assertSame(0, WordPressSiteOperation::query()->count());
    }

    #[Test]
    public function another_tenants_site_is_not_found_and_a_member_without_service_manage_cannot_copy(): void
    {
        $site = $this->liveSite('mine.test');

        $stranger = User::factory()->create();
        $theirs = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $theirs->members()->create(['user_id' => $stranger->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $this->actingAs($stranger)->postJson('/api/v1/wordpress/sites/'.$site->id.'/staging')->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/v1/wordpress/sites/'.$site->id.'/operations')->assertNotFound();

        $member = User::factory()->create();
        $this->customer->members()->create(['user_id' => $member->id, 'role' => CustomerRole::Member, 'accepted_at' => now()]);
        $this->actingAs($member)->postJson('/api/v1/wordpress/sites/'.$site->id.'/staging')->assertForbidden();

        $this->assertSame(0, WordPressSiteOperation::query()->count());
    }

    private function liveSite(string $domain): WordPressSite
    {
        $account = HostingAccount::factory()->named('acct'.substr(md5($domain), 0, 6))->create([
            'customer_id' => $this->customer->id,
            'hosting_node_id' => $this->node->id,
            'primary_domain' => $domain,
        ]);

        return WordPressSite::factory()->live()->create([
            'customer_id' => $this->customer->id,
            'hosting_account_id' => $account->id,
            'domain' => $domain,
        ]);
    }
}
