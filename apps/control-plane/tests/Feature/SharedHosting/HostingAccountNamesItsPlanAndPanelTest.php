<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two things a hosting customer could not find out before Wave 3.
 *
 * Which plan they are on: the account document carried the platform's own
 * slug, so a customer was told they had bought "hosting-starter". And which
 * panel they are about to sign into: the button said "Open panel" and the
 * customer found out which product it was when the tab opened.
 *
 * Both are published now, and both stop where the estate begins. The panel's
 * *type* is a product fact — cPanel and DirectAdmin are different software a
 * person has to know how to use. Which node, at which address, under which
 * credential, is not, and the fake panel is not named at all: it exists for
 * development and the browser suite, and naming it would publish the shape of
 * the deployment.
 */
final class HostingAccountNamesItsPlanAndPanelTest extends HostingApiTestCase
{
    #[Test]
    public function it_names_the_catalogue_plan_the_customer_is_billed_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $plan = Plan::factory()->create(['name' => ['en' => 'Hosting Starter', 'ar' => 'استضافة مبدئية']]);
        $this->package()->update(['plan_id' => $plan->getKey()]);

        $account = $this->hostingAccountFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.package.plan_name', 'Hosting Starter')
            // The slug stays: it is the join key, and it is a hint beside the
            // name rather than the answer.
            ->assertJsonPath('data.package.slug', 'hosting-starter');
    }

    #[Test]
    public function the_plan_name_is_in_the_language_of_the_request(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $plan = Plan::factory()->create(['name' => ['en' => 'Hosting Starter', 'ar' => 'استضافة مبدئية']]);
        $this->package()->update(['plan_id' => $plan->getKey()]);

        $account = $this->hostingAccountFor($customer);

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/hosting/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.package.plan_name', 'استضافة مبدئية');
    }

    #[Test]
    public function a_package_with_no_catalogue_plan_behind_it_says_nothing_rather_than_the_slug(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        // Null, not the slug and not an invented product name: a client then
        // shows "not available", which is true.
        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.package.plan_name', null);
    }

    #[Test]
    public function it_names_the_panel_a_customer_is_about_to_sign_into(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        foreach ([HostingPanel::Cpanel, HostingPanel::DirectAdmin] as $panel) {
            $node = HostingNode::factory()->create([
                'panel' => $panel,
                'hostname' => 'node-'.$panel->value.'.lynomia.test',
            ]);

            $account = HostingAccount::factory()->create([
                'hosting_node_id' => $node->getKey(),
                'hosting_package_id' => $this->package()->getKey(),
                'customer_id' => $customer->getKey(),
            ]);

            $this->actingAs($user)
                ->getJson('/api/v1/hosting/'.$account->id)
                ->assertOk()
                ->assertJsonPath('data.panel_type', $panel->value);
        }
    }

    #[Test]
    public function a_controlled_panel_is_not_named(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        /*
         * The fake, which every browser test and every development machine
         * runs against. An account always has a node — the column is not
         * nullable, because an account is created on a node in the same
         * transaction that commits its capacity — so this is the one case
         * where the platform will not say which panel, and a client then says
         * "hosting control panel", which is true whichever it turns out to be.
         */
        $onFake = $this->hostingAccountFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$onFake->id)
            ->assertOk()
            ->assertJsonPath('data.panel_type', null);
    }

    #[Test]
    public function naming_the_panel_publishes_nothing_else_about_the_node(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $node = HostingNode::factory()->create([
            'panel' => HostingPanel::Cpanel,
            'hostname' => 'cpanel-node-under-test.lynomia.test',
        ]);

        $account = HostingAccount::factory()->create([
            'hosting_node_id' => $node->getKey(),
            'hosting_package_id' => $this->package()->getKey(),
            'customer_id' => $customer->getKey(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id)
            ->assertOk();

        $body = $response->getContent();
        $this->assertIsString($body);

        // The panel type is a word; the machine behind it is not published.
        $this->assertStringContainsString('cpanel', $body);
        $this->assertStringNotContainsString($node->hostname, $body);
        $this->assertStringNotContainsString((string) $node->api_endpoint, $body);
        $this->assertStringNotContainsString((string) $node->credentials_reference, $body);
        $this->assertStringNotContainsString($this->package()->panel_package_name, $body);
    }

    #[Test]
    public function the_usage_reading_is_stamped_and_never_a_zero_for_a_figure_it_does_not_have(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        // Nothing has ever been reported for this account.
        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.disk.used_mib', null)
            ->assertJsonPath('data.bandwidth.used_mib', null)
            ->assertJsonPath('data.disk.used_percent', null)
            ->assertJsonPath('meta.never_measured', true)
            ->assertJsonPath('meta.stale', false)
            ->assertJsonPath('meta.source', 'last_panel_sync');

        // And once it has, the figure carries the date it was taken.
        $account->update([
            'disk_used_mib' => 5_120,
            'bandwidth_used_mib' => 1_024,
            'usage_synced_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.disk.used_mib', 5_120)
            ->assertJsonPath('data.disk.quota_mib', 10_240)
            ->assertJsonPath('data.disk.used_percent', 50)
            ->assertJsonPath('data.disk.unlimited', false)
            ->assertJsonPath('meta.never_measured', false)
            ->assertJsonPath('meta.stale', false);
    }

    #[Test]
    public function a_reading_the_platform_has_stopped_hearing_is_labelled_stale(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer);

        $account->update([
            'disk_used_mib' => 9_000,
            'usage_synced_at' => now()->subDays(2),
        ]);

        /*
         * The figure is still published — it is history, and history is
         * useful — but a client that drew it as "now" would be showing a
         * customer at 88% of a quota nobody has checked for two days.
         */
        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.disk.used_mib', 9_000)
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('meta.never_measured', false);
    }
}
