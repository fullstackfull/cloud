<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Backups\Application\Actions\ReconcileBackup;
use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\BackupTrigger;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupNotConfiguredException;
use Lynomia\Modules\Backups\Domain\Exceptions\IllegalBackupTransitionException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\UnreachableBackupProvider;
use Tests\TestCase;

/**
 * How a backup row comes to say what it says.
 *
 * The claim this suite exists to make impossible: "your backup succeeded",
 * written on the strength of anything other than a provider task reporting OK.
 * A queue job accepted, a request that returned 200, a row that has existed
 * for a while — none of these are evidence that data was copied anywhere, and
 * a customer told otherwise finds out on the one day it matters.
 *
 * The second claim under test is subtler and matters as much: a backup call
 * that timed out is not a failure. Proxmox accepts a vzdump in milliseconds
 * and runs it for an hour, so the archive may be being written right now.
 * Marked failed, it invites a retry that runs a second backup over the same
 * disks; marked running, it invites a poller to chase a task id that was never
 * issued. It goes to a person instead.
 */
final class BackupLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ?ComputeNode $node = null;

    private ?FakeBackupProvider $provider = null;

    private ?BackupProviderFactory $factory = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        config()->set('backups.datastores.'.$this->cluster()->slug, 'pbs-test-01');
    }

    private function cluster(): ComputeCluster
    {
        return $this->node()->cluster()->firstOrFail();
    }

    private function node(): ComputeNode
    {
        return $this->node ??= ComputeNode::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->id,
            'provider_name' => 'pve-01',
        ]);
    }

    private function machine(?Customer $customer = null): VirtualMachine
    {
        $customer ??= Customer::factory()->create();

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        return VirtualMachine::factory()
            ->onNode($this->node())
            ->forService($service)
            ->create(['provider_id' => '101']);
    }

    /**
     * One factory, held for the test and handed to every action it builds.
     *
     * The factories in this platform are deliberately not singletons — a
     * cluster's adapter is per cluster, not per deployment — so a swap only
     * takes effect on the instance the code under test was given. Resolving
     * the action from the container instead would build it a second factory
     * and quietly ignore the fake.
     */
    private function factory(): BackupProviderFactory
    {
        return $this->factory ??= new BackupProviderFactory($this->app->make(SecretRedactor::class));
    }

    /**
     * The fake, installed for this cluster so the test can steer it.
     */
    private function provider(): FakeBackupProvider
    {
        if ($this->provider === null) {
            $this->provider = new FakeBackupProvider;
            $this->factory()->swap($this->cluster(), $this->provider);
        }

        return $this->provider;
    }

    private function request(): RequestServiceBackup
    {
        return new RequestServiceBackup($this->factory(), $this->app->make(SecretRedactor::class));
    }

    private function reconcile(): ReconcileBackup
    {
        return new ReconcileBackup($this->factory(), $this->app->make(SecretRedactor::class));
    }

    #[Test]
    public function an_accepted_request_is_running_and_not_succeeded(): void
    {
        $this->provider();

        $backup = $this->request()->execute($this->machine());

        // The provider has a task. Nothing has been backed up.
        $this->assertSame(BackupState::Running, $backup->state);
        $this->assertNotNull($backup->provider_task_id);
        $this->assertNull($backup->finished_at);
        $this->assertNull($backup->archive_id);
    }

    #[Test]
    public function only_a_provider_task_reporting_ok_can_write_succeeded(): void
    {
        $provider = $this->provider();
        $provider->pollsBeforeSettling = 2;

        $backup = $this->request()->execute($this->machine());

        $reconcile = $this->reconcile();

        // Two polls that report the task still running. Time passing is not
        // evidence of anything.
        $reconcile->execute($backup);
        $this->assertSame(BackupState::Running, $backup->refresh()->state);

        $reconcile->execute($backup);
        $this->assertSame(BackupState::Running, $backup->refresh()->state);

        // The third reports OK, and only now.
        $reconcile->execute($backup);
        $backup->refresh();

        $this->assertSame(BackupState::Succeeded, $backup->state);
        $this->assertNotNull($backup->archive_id);
        $this->assertNotNull($backup->finished_at);
        $this->assertSame(1_073_741_824, $backup->size_bytes);
    }

    #[Test]
    public function a_task_that_starts_and_then_fails_is_failed_with_the_providers_reason(): void
    {
        // Settles on the first poll: what this test is about is the failure,
        // not how many times the poller had to ask.
        $this->provider()->pollsBeforeSettling = 0;

        $backup = $this->request()->execute($this->machine(), notes: FakeBackupProvider::FAILING_MARKER);

        $this->assertSame(BackupState::Running, $backup->state);

        $this->reconcile()->execute($backup);
        $backup->refresh();

        $this->assertSame(BackupState::Failed, $backup->state);
        $this->assertStringContainsString('no space left on device', (string) $backup->failure_reason);
    }

    #[Test]
    public function a_refused_request_is_failed_and_never_reaches_the_provider_again(): void
    {
        $this->provider();

        $backup = $this->request()->execute($this->machine(), notes: FakeBackupProvider::REFUSAL_MARKER);

        $this->assertSame(BackupState::Failed, $backup->state);
        $this->assertNull($backup->provider_task_id);
        $this->assertNotNull($backup->finished_at);

        // Nothing leaves Failed. A failed backup is not repaired; a new one is
        // taken, so the failure stays in the history.
        $this->expectException(IllegalBackupTransitionException::class);
        $backup->transitionTo(BackupState::Running);
    }

    #[Test]
    public function the_credential_a_refusal_quoted_back_is_not_stored_on_the_row(): void
    {
        $this->provider();

        $backup = $this->request()->execute($this->machine(), notes: FakeBackupProvider::REFUSAL_MARKER);

        // The fake quotes the Authorization header, because a real hypervisor
        // does. The reason is stored for an operator to read, so it is the
        // reason that must be clean.
        $this->assertStringNotContainsString('fake-pve-token', (string) $backup->failure_reason);
    }

    /*
     * -----------------------------------------------------------------------
     * The case this module is arranged around
     * -----------------------------------------------------------------------
     */

    #[Test]
    public function a_request_that_timed_out_goes_to_a_person_rather_than_being_called_failed(): void
    {
        $this->provider();

        $backup = $this->request()->execute($this->machine(), notes: FakeBackupProvider::TIMEOUT_MARKER);

        // Not Failed: the vzdump may be running right now, writing to the
        // datastore and holding the machine's disks. Not Running either: there
        // is no task id to poll, because the call never came back.
        $this->assertSame(BackupState::NeedsReview, $backup->state);
        $this->assertTrue($backup->state->needsAttention());
        $this->assertNull($backup->provider_task_id);
        $this->assertNull($backup->finished_at);
        $this->assertStringContainsString('unknown', (string) $backup->failure_reason);
    }

    #[Test]
    public function the_row_survives_a_provider_call_that_never_answers(): void
    {
        $this->provider();

        $this->request()->execute($this->machine(), notes: FakeBackupProvider::TIMEOUT_MARKER);

        // Written before the call, so a timeout leaves a record of the request
        // rather than losing it — which is what lets an operator find the
        // orphan on the datastore.
        $this->assertSame(1, Backup::query()->count());
    }

    #[Test]
    public function a_poll_that_times_out_changes_nothing_and_is_asked_again(): void
    {
        $provider = $this->provider();
        $provider->pollsBeforeSettling = 5;

        $backup = $this->request()->execute($this->machine());

        // Swap in a provider that cannot answer, without touching the row.
        $this->factory()->swap($this->cluster(), new UnreachableBackupProvider);

        $this->reconcile()->execute($backup);
        $backup->refresh();

        // Still running. Not knowing what a task is doing is the normal
        // condition of a poller; a slow datastore must not mark good backups
        // as needing review.
        $this->assertSame(BackupState::Running, $backup->state);
        $this->assertSame(1, $backup->poll_count);
        $this->assertNotNull($backup->last_polled_at);
    }

    #[Test]
    public function a_task_still_unfinished_after_the_tracking_window_is_handed_to_a_person(): void
    {
        config()->set('backups.max_poll_hours', 1);

        $provider = $this->provider();
        $provider->pollsBeforeSettling = 99;

        $backup = $this->request()->execute($this->machine());

        $backup->forceFill(['started_at' => now()->subHours(3)])->save();

        $this->reconcile()->execute($backup);
        $backup->refresh();

        // Not Failed. The provider may still be working — a large machine onto
        // a cold datastore takes as long as it takes. What failed is the
        // platform's ability to keep track.
        $this->assertSame(BackupState::NeedsReview, $backup->state);
        $this->assertStringContainsString('stopped tracking', (string) $backup->failure_reason);
    }

    #[Test]
    public function a_cluster_with_no_datastore_declared_refuses_rather_than_choosing_one(): void
    {
        config()->set('backups.datastores', []);
        $this->provider();

        try {
            $this->request()->execute($this->machine());
            $this->fail('A backup was requested with no datastore configured.');
        } catch (BackupNotConfiguredException $e) {
            // A provider asked to back up without a storage writes to whatever
            // it considers default, which on a hypervisor is frequently the
            // same disks the machine runs on.
            $this->assertSame('backups.not_configured', $e->errorCode());
            $this->assertStringContainsString('backups.datastores.', (string) ($e->context()['configuration_key'] ?? ''));
        }

        $this->assertSame(0, Backup::query()->count());
    }

    #[Test]
    public function a_machine_the_hypervisor_has_never_confirmed_cannot_be_backed_up(): void
    {
        $this->provider();

        $machine = $this->machine();
        $machine->forceFill(['provider_id' => null])->save();

        $this->expectException(BackupNotConfiguredException::class);

        $this->request()->execute($machine->refresh());
    }

    #[Test]
    public function the_row_records_who_asked_and_why(): void
    {
        $this->provider();

        $backup = $this->request()->execute($this->machine(), trigger: BackupTrigger::Scheduled);

        $this->assertSame(BackupTrigger::Scheduled, $backup->trigger);
        $this->assertFalse($backup->trigger->hasSomebodyWaiting());
    }
}
