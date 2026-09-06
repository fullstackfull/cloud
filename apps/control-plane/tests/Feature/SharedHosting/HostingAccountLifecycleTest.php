<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\SharedHosting\Application\Actions\SuspendHostingAccount;
use Lynomia\Modules\SharedHosting\Application\Actions\TerminateHostingAccount;
use Lynomia\Modules\SharedHosting\Application\Actions\UnsuspendHostingAccount;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\RetentionPeriodActiveException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suspension and termination are two different products, not two intensities
 * of one.
 *
 * The distinction is commercial rather than technical. Technically the
 * platform could free the disk on the day an invoice goes unpaid. Commercially
 * it must not: most suspensions are billing disputes — an expired card, an
 * invoice sent to somebody who left the company, a transfer that took a week —
 * and the overwhelming majority end with the customer paying. A suspension
 * that destroyed data would turn a late invoice into a lost customer, an
 * unrecoverable dataset, and where the customer holds other people's data, a
 * liability no refund settles.
 *
 * So: suspension preserves everything and is undone by one call. Termination
 * releases everything and is undone by nothing, which is why the retention
 * window is enforced in front of it.
 */
final class HostingAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private HostingNode $node;

    private FakeHostingProvider $panel;

    protected function setUp(): void
    {
        parent::setUp();

        // One factory instance for the whole test, so the actions and the test
        // talk to the same in-memory panel.
        $this->app->singleton(HostingProviderFactory::class);

        $this->node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($this->node);
        $this->panel = $panel;
    }

    #[Test]
    public function suspension_stops_service_and_preserves_the_account(): void
    {
        $account = $this->account('acmeone');

        $suspended = app(SuspendHostingAccount::class)->execute($account, 'invoice 4471 unpaid');

        $this->assertSame(HostingAccountStatus::Suspended, $suspended->status);
        $this->assertSame('invoice 4471 unpaid', $suspended->suspension_reason);
        $this->assertNotNull($suspended->suspended_at);

        // The account is still on the node, with its data intact. That is the
        // whole point: nothing here is destructive.
        $remote = $this->remote('acmeone');
        $this->assertNotNull($remote);
        $this->assertTrue($remote->suspended);
        $this->assertNotNull($remote->diskUsedMib);
    }

    #[Test]
    public function suspension_does_not_release_the_nodes_slot(): void
    {
        // The files, mail and databases are still on the disk. Releasing the
        // slot would let the scheduler place a new account into space that is
        // still occupied by an account waiting out a billing dispute.
        $account = $this->account('acmeone');
        $before = $this->node->fresh()?->account_count;

        app(SuspendHostingAccount::class)->execute($account, 'non-payment');

        $this->assertSame($before, $this->node->fresh()?->account_count);
    }

    #[Test]
    public function suspension_is_reversible_and_the_customer_comes_back_to_exactly_what_they_had(): void
    {
        $account = $this->account('acmeone');
        $diskBefore = $this->remote('acmeone')?->diskUsedMib;

        app(SuspendHostingAccount::class)->execute($account, 'non-payment');
        $restored = app(UnsuspendHostingAccount::class)->execute($account->fresh() ?? $account);

        $this->assertSame(HostingAccountStatus::Active, $restored->status);
        $this->assertNull($restored->suspended_at);
        $this->assertNull($restored->suspension_reason);

        $remote = $this->remote('acmeone');
        $this->assertNotNull($remote);
        $this->assertFalse($remote->suspended);
        // Same node, same data. Nothing was restored because nothing was lost.
        $this->assertSame($diskBefore, $remote->diskUsedMib);
    }

    #[Test]
    public function suspending_twice_does_not_restart_the_retention_clock(): void
    {
        // A dunning run and an operator can arrive at the same account in the
        // same minute. The second must not silently extend how long the
        // platform waits before it may release the data.
        $account = $this->account('acmeone');

        $first = app(SuspendHostingAccount::class)->execute($account, 'non-payment');
        $suspendedAt = $first->suspended_at;

        $again = app(SuspendHostingAccount::class)->execute($first, 'still unpaid');

        $this->assertEquals($suspendedAt, $again->suspended_at);
        $this->assertSame('non-payment', $again->suspension_reason);
    }

    #[Test]
    public function termination_is_refused_while_the_retention_window_is_still_running(): void
    {
        config(['hosting.retention.suspended_days' => 30]);

        $account = $this->account('acmeone');
        app(SuspendHostingAccount::class)->execute($account, 'non-payment');

        try {
            app(TerminateHostingAccount::class)->execute($account->fresh() ?? $account);

            $this->fail('An account was terminated inside its retention window.');
        } catch (RetentionPeriodActiveException $e) {
            $this->assertSame('hosting.retention_period_active', $e->errorCode());
        }

        // Still there, and still restorable.
        $this->assertNotNull($this->remote('acmeone'));
        $this->assertSame(HostingAccountStatus::Suspended, $account->fresh()?->status);
    }

    #[Test]
    public function termination_releases_the_account_once_the_retention_window_has_elapsed(): void
    {
        config(['hosting.retention.suspended_days' => 30]);

        $account = $this->account('acmeone');
        app(SuspendHostingAccount::class)->execute($account, 'non-payment');

        // Wind the suspension back past the window rather than travelling in
        // time, so the assertion is about the policy and not about the clock.
        $account->forceFill(['suspended_at' => now()->subDays(31)])->save();

        $terminated = app(TerminateHostingAccount::class)->execute($account->fresh() ?? $account);

        $this->assertSame(HostingAccountStatus::Terminated, $terminated->status);
        $this->assertNotNull($terminated->terminated_at);

        // Gone from the panel, and this one is not reversible: no call this
        // platform can make brings the customer's files back.
        $this->assertNull($this->remote('acmeone'));
    }

    #[Test]
    public function termination_gives_the_nodes_slot_back_and_only_once(): void
    {
        $account = $this->account('acmeone');
        $before = $this->node->fresh()?->account_count ?? 0;

        app(TerminateHostingAccount::class)->execute($account);

        $this->assertSame($before - 1, $this->node->fresh()?->account_count);

        // A retried cleanup job must not release a second slot for one account.
        app(TerminateHostingAccount::class)->execute($account->fresh() ?? $account);

        $this->assertSame($before - 1, $this->node->fresh()?->account_count);
    }

    #[Test]
    public function an_operator_may_override_the_retention_window_deliberately(): void
    {
        // Reserved for an explicit request — an abuse case, or a customer
        // asking for their data to be deleted now. No automated path sets it.
        config(['hosting.retention.suspended_days' => 30]);

        $account = $this->account('acmeone');
        app(SuspendHostingAccount::class)->execute($account, 'abuse');

        $terminated = app(TerminateHostingAccount::class)->execute($account->fresh() ?? $account, force: true);

        $this->assertSame(HostingAccountStatus::Terminated, $terminated->status);
        $this->assertNull($this->remote('acmeone'));
    }

    #[Test]
    public function a_panel_that_refuses_the_suspension_leaves_the_platform_record_unchanged(): void
    {
        /*
         * A row marked suspended while the account is still serving is a
         * customer who is not paying and not stopped, and nobody looks again.
         */
        $account = HostingAccount::factory()->create([
            'hosting_node_id' => $this->node->getKey(),
            'username' => 'never-created',
        ]);

        try {
            app(SuspendHostingAccount::class)->execute($account, 'non-payment');

            $this->fail('A suspension the panel refused was recorded as done.');
        } catch (HostingProviderException) {
            $this->assertSame(HostingAccountStatus::Active, $account->fresh()?->status);
            $this->assertNull($account->fresh()?->suspended_at);
        }
    }

    /**
     * An account that exists both in the platform and on the panel.
     */
    private function account(string $username): HostingAccount
    {
        $this->panel->createAccount($this->node, new CreateAccountRequest(
            username: $username,
            primaryDomain: $username.'.example.test',
            password: 's3cret-panel-password',
            packageName: 'starter',
            contactEmail: 'owner@'.$username.'.example.test',
        ));

        $this->node->increment('account_count');
        $this->node->refresh();

        return HostingAccount::factory()->named($username)->create([
            'hosting_node_id' => $this->node->getKey(),
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
