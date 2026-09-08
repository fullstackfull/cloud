<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\SharedHosting\Application\Actions\ReconcileHostingNodes;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Comparing what the platform believes about a hosting node with what the
 * panel is holding.
 *
 * `listAccounts()` has been implemented against cPanel and DirectAdmin since
 * Phase 12 and nothing called it, so the platform's belief that a customer had
 * a working website rested entirely on its own record of having built one.
 *
 * Every test here also holds the same boundary: **the sweep changes nothing.**
 * Not at the panel, and not in the platform's own rows.
 */
final class HostingReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private HostingNode $node;

    private FakeHostingProvider $panel;

    protected function setUp(): void
    {
        parent::setUp();

        // One factory for the whole test, so the action and the assertions
        // talk to the same in-memory panel.
        $this->app->singleton(HostingProviderFactory::class);

        $this->node = HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'status' => HostingNodeStatus::Active,
            'account_count' => 0,
        ]);

        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($this->node);
        $this->panel = $panel;
    }

    #[Test]
    public function an_account_the_platform_believes_in_and_the_panel_has_never_heard_of_is_critical(): void
    {
        $this->row('ghostone', HostingAccountStatus::Active);

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['nodes']);

        $drift = ResourceDrift::query()
            ->where('kind', DriftKind::MissingAtProvider->value)
            ->where('resource_type', 'hosting_account')
            ->firstOrFail();

        /*
         * Critical, and in the worst direction: the customer is being billed
         * for a website that is not being served, and the first person to
         * notice will be them.
         */
        $this->assertSame('critical', $drift->severity->value);
        $this->assertSame('ghostone', $drift->provider_reference);

        // Reported, not repaired. Nothing was created at the panel and the row
        // still says what it said.
        $this->assertNull($this->remote('ghostone'));
        $this->assertSame(
            HostingAccountStatus::Active,
            HostingAccount::query()->where('username', 'ghostone')->firstOrFail()->status,
        );
    }

    #[Test]
    public function an_account_on_the_panel_that_no_row_claims_is_reported_and_left_alone(): void
    {
        // Made by hand during an incident, or left behind by a create whose
        // answer was lost. Occupying disk nobody is billing for.
        $this->atPanel('strayone');

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['drifts']);

        $drift = ResourceDrift::query()->where('kind', DriftKind::OrphanAtProvider->value)->firstOrFail();
        $this->assertSame('strayone', $drift->provider_reference);

        /*
         * The assertion the whole design exists for. An account whose
         * provenance nobody knows must not be handed to a customer as theirs,
         * and must certainly not be deleted by a sweep that does not know what
         * it is.
         */
        $this->assertNotNull($this->remote('strayone'));
        $this->assertSame(0, HostingAccount::query()->count());
    }

    #[Test]
    public function a_terminated_row_whose_account_is_still_serving_is_reported(): void
    {
        $this->atPanel('leftbehind');
        $this->row('leftbehind', HostingAccountStatus::Terminated);

        app(ReconcileHostingNodes::class)->execute();

        // Disk and a licence slot nobody is billing for — and somebody's data
        // still on a machine the platform believes is empty.
        $this->assertTrue(
            ResourceDrift::query()
                ->where('kind', DriftKind::OrphanAtProvider->value)
                ->where('provider_reference', 'leftbehind')
                ->exists(),
        );

        $this->assertNotNull($this->remote('leftbehind'));
    }

    #[Test]
    public function disagreeing_about_whether_an_account_is_switched_off_is_reported_either_way(): void
    {
        // Suspended here, serving there: a customer using what they have not
        // paid for.
        $this->atPanel('stillserving');
        $this->row('stillserving', HostingAccountStatus::Suspended);

        // Active here, switched off there: a paying customer whose site is
        // down and who is about to ring support.
        $this->atPanel('darkone');
        $this->panel->suspendAccount($this->node, 'darkone', 'suspended out of band');
        $this->row('darkone', HostingAccountStatus::Active);

        app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(
            2,
            ResourceDrift::query()->where('kind', DriftKind::SuspensionMismatch->value)->count(),
        );

        // Neither is put right by the sweep: whichever way it guessed, half
        // the time it would be switching off a customer who has paid.
        $this->assertFalse($this->remote('stillserving')?->suspended);
        $this->assertTrue($this->remote('darkone')?->suspended);
    }

    #[Test]
    public function an_account_still_being_built_is_not_reported_as_a_disagreement(): void
    {
        // Neither suspended nor not. Reporting one would make every build a
        // drift record for as long as it took.
        $this->atPanel('brandnew');
        $this->row('brandnew', HostingAccountStatus::Pending);

        // A pending account holds a slot, so the ledger has to agree or the
        // capacity check reports that instead and this test passes for the
        // wrong reason.
        $this->node->forceFill(['account_count' => 1])->save();

        $this->assertSame(0, app(ReconcileHostingNodes::class)->execute()['drifts']);
    }

    #[Test]
    public function an_account_that_agrees_produces_nothing(): void
    {
        $this->atPanel('happyone');
        $this->row('happyone', HostingAccountStatus::Active);
        $this->node->forceFill(['account_count' => 1])->save();

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['accounts']);
        $this->assertSame(0, $outcome['drifts']);
        $this->assertSame(0, ResourceDrift::query()->count());
    }

    #[Test]
    public function a_node_whose_capacity_ledger_has_drifted_is_reported(): void
    {
        $this->atPanel('happyone');
        $this->row('happyone', HostingAccountStatus::Active);

        // `account_count` is what the scheduler places against, and it is
        // incremented and decremented by hand in two different actions. An
        // over-counted node quietly refuses accounts it could hold.
        $this->node->forceFill(['account_count' => 7])->save();

        app(ReconcileHostingNodes::class)->execute();

        $drift = ResourceDrift::query()
            ->where('resource_type', 'hosting_node')
            ->where('kind', DriftKind::SpecMismatch->value)
            ->firstOrFail();

        $this->assertSame(7, $drift->expected['account_count'] ?? null);
        $this->assertSame(1, $drift->observed['accounts_occupying_capacity'] ?? null);

        // Reported, not corrected. Every automated remedy for drift is one bug
        // away from deleting production.
        $this->assertSame(7, $this->node->fresh()?->account_count);
    }

    #[Test]
    public function a_panel_that_will_not_answer_produces_no_drift_at_all(): void
    {
        $this->row('ghostone', HostingAccountStatus::Active);

        // The fake reads its markers from the node's hostname for this call,
        // because listing is the one operation that is about the node rather
        // than about an account.
        $this->node->forceFill([
            'hostname' => FakeHostingProvider::PROVIDER_FAILURE_MARKER.'.example.test',
        ])->save();

        $outcome = app(ReconcileHostingNodes::class)->execute();

        /*
         * A sweep that recorded "missing at the panel" for every account on a
         * node whose API was down would report an outage as data loss, and
         * bury the one real missing account in the middle of it.
         */
        $this->assertSame(0, $outcome['nodes']);
        $this->assertSame(0, ResourceDrift::query()->count());
        $this->assertNull($this->node->fresh()?->reconciled_at);
    }

    #[Test]
    public function a_node_in_maintenance_is_not_asked(): void
    {
        $this->row('ghostone', HostingAccountStatus::Active);
        $this->node->forceFill(['status' => HostingNodeStatus::Maintenance])->save();

        // Its accounts are in whatever state the work left them, and reporting
        // that would fill an operator's queue with the consequences of their
        // own maintenance window.
        $this->assertSame(0, app(ReconcileHostingNodes::class)->execute()['nodes']);
    }

    #[Test]
    public function the_command_reports_what_it_did(): void
    {
        $this->atPanel('happyone');
        $this->row('happyone', HostingAccountStatus::Active);
        $this->node->forceFill(['account_count' => 1])->save();

        $this->artisan('hosting:reconcile')
            ->expectsOutputToContain('1 nodes checked')
            ->assertSuccessful();
    }

    private function atPanel(string $username): void
    {
        $this->panel->createAccount($this->node, new CreateAccountRequest(
            username: $username,
            primaryDomain: $username.'.example.test',
            password: 's3cret-panel-password',
            packageName: 'starter',
            contactEmail: 'owner@'.$username.'.example.test',
        ));
    }

    private function row(string $username, HostingAccountStatus $status): HostingAccount
    {
        return HostingAccount::factory()->named($username)->create([
            'hosting_node_id' => $this->node->getKey(),
            'status' => $status,
        ]);
    }

    private function remote(string $username): ?RemoteAccount
    {
        foreach ($this->panel->listAccounts($this->node) as $account) {
            if ($account->username === $username) {
                return $account;
            }
        }

        return null;
    }
}
