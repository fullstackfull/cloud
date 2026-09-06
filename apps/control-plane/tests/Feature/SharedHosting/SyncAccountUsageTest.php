<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\SharedHosting\Application\Actions\SyncAccountUsage;
use Lynomia\Modules\SharedHosting\Domain\DTOs\AccountUsage;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sync writes what the panel said, and only what the panel said.
 *
 * Panels answer with the numbers missing far more often than anybody expects:
 * a cPanel node rebuilding its quota cache after a reboot, a DirectAdmin
 * account whose statistics run has not completed, a node under load that
 * answers one call and times out on the other. In every one of those the
 * correct reading is "unknown", and the tempting shortcut — treat absent as
 * zero — is wrong in the most expensive possible direction:
 *
 *  - a customer at 95% of quota is recorded at 0%, so enforcement stops and
 *    the account fills the node's disk, which takes every site on that machine
 *    down at once;
 *  - metered bandwidth resets, so the overage about to be billed is not, and
 *    the month's revenue quietly disappears;
 *  - the graph the customer looks at drops to the floor, and the support
 *    ticket that follows is answered by somebody who believes the graph.
 */
final class SyncAccountUsageTest extends TestCase
{
    use RefreshDatabase;

    private HostingNode $node;

    private FakeHostingProvider $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HostingProviderFactory::class);

        $this->node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($this->node);
        $this->panel = $panel;
    }

    #[Test]
    public function it_records_what_the_panel_reported(): void
    {
        $account = $this->account('acmeone');

        $this->assertTrue(app(SyncAccountUsage::class)->execute($account));

        $fresh = $account->fresh();
        $this->assertNotNull($fresh?->disk_used_mib);
        $this->assertNotNull($fresh?->bandwidth_used_mib);
        $this->assertNotNull($fresh?->usage_synced_at);
    }

    #[Test]
    public function it_never_overwrites_a_record_with_nulls_when_the_panel_returns_nothing(): void
    {
        // The NO_USAGE marker makes the fake answer exactly as a panel
        // mid-restart does: the account exists, and there are no numbers.
        $account = $this->account('acme-no-usage');

        $account->forceFill([
            'disk_used_mib' => 8_192,
            'bandwidth_used_mib' => 120_000,
            'usage_synced_at' => now()->subHour(),
        ])->save();

        $syncedAt = $account->fresh()?->usage_synced_at;

        $this->assertFalse(app(SyncAccountUsage::class)->execute($account));

        $fresh = $account->fresh();

        // The customer's real figures survive untouched.
        $this->assertSame(8_192, $fresh?->disk_used_mib);
        $this->assertSame(120_000, $fresh?->bandwidth_used_mib);

        /*
         * And the timestamp is not moved either. A sync that learned nothing is
         * not a sync, and stamping it would hide a node that has stopped
         * reporting behind a timestamp that says everything is fine.
         */
        $this->assertEquals($syncedAt, $fresh?->usage_synced_at);
    }

    #[Test]
    public function a_partial_answer_writes_only_the_half_the_panel_actually_gave(): void
    {
        // The panel answered about disk and said nothing about bandwidth. A
        // whole-row write would null the half it did not mention.
        $account = $this->account('acmeone');

        $account->forceFill(['disk_used_mib' => 1, 'bandwidth_used_mib' => 99_999])->save();

        $written = app(SyncAccountUsage::class)->apply($account, new AccountUsage(
            username: 'acmeone',
            diskUsedMib: 4_096,
        ));

        $this->assertTrue($written);
        $this->assertSame(4_096, $account->fresh()?->disk_used_mib);
        $this->assertSame(99_999, $account->fresh()?->bandwidth_used_mib);
    }

    #[Test]
    public function quotas_without_consumption_are_not_recorded_as_a_reading(): void
    {
        // An account that exists and has never been measured. There is nothing
        // to record, and stamping the sync time would claim otherwise.
        $account = $this->account('acmeone');

        $written = app(SyncAccountUsage::class)->apply($account, new AccountUsage(
            username: 'acmeone',
            diskQuotaMib: 10_240,
            bandwidthQuotaMib: 512_000,
        ));

        $this->assertFalse($written);
        $this->assertNull($account->fresh()?->usage_synced_at);
    }

    #[Test]
    public function a_reading_of_genuine_zero_is_written_because_zero_is_a_measurement(): void
    {
        /*
         * The distinction the whole action turns on. Absent is not zero — but
         * zero IS zero, and a brand-new account really does use nothing. A
         * guard that rejected falsy values instead of null values would refuse
         * to record the one reading every new account starts with.
         */
        $account = $this->account('acmeone');

        $written = app(SyncAccountUsage::class)->apply($account, new AccountUsage(
            username: 'acmeone',
            diskUsedMib: 0,
            bandwidthUsedMib: 0,
        ));

        $this->assertTrue($written);
        $this->assertSame(0, $account->fresh()?->disk_used_mib);
        $this->assertNotNull($account->fresh()?->usage_synced_at);
    }

    private function account(string $username): HostingAccount
    {
        $this->panel->createAccount($this->node, new CreateAccountRequest(
            username: $username,
            primaryDomain: $username.'.example.test',
            password: 's3cret-panel-password',
            packageName: 'starter',
            contactEmail: 'owner@'.$username.'.example.test',
        ));

        return HostingAccount::factory()->named($username)->create([
            'hosting_node_id' => $this->node->getKey(),
        ]);
    }
}
