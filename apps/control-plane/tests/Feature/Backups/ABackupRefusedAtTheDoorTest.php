<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\ReconcileBackup;
use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupNotificationKey;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\TwoTaskDatastore;
use Tests\Feature\Backups\Doubles\UnreachableBackupProvider;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * A backup refused at the door is still a backup that did not happen.
 *
 * The request path has two ways to end badly and it told the customer about
 * only one of them. An indeterminate start — the provider stopped answering,
 * so a backup may or may not be running — became `BackupNeedsReview` when that
 * word was added. A deterministic refusal, which is the provider saying no in
 * as many words, put the row in `Failed` and said nothing at all.
 *
 * That asymmetry was recorded in the code rather than fixed, because it was
 * not that change's to make. It is this one's. A customer whose backup was
 * refused for want of disk space, or by a credential the platform no longer
 * has, is in exactly the position `BackupFailed` exists to describe: there is
 * no archive, and taking another one is the thing to do.
 *
 * ---------------------------------------------------------------------------
 * One outcome, one key, whoever notices it
 * ---------------------------------------------------------------------------
 *
 * The reconciler already announces this outcome for a task that failed after
 * it started, under `backup:{id}:failed`. A refusal at the door is the same
 * fact about the same row arriving by a different route, so it uses the same
 * key — not a second one qualified by "at start", which would let one backup
 * produce two messages if the two paths ever met.
 */
final class ABackupRefusedAtTheDoorTest extends VpsApiTestCase
{
    private const TASK = 'UPID:pve-01:backup-0001';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        $this->app->singleton(BackupProviderFactory::class);
    }

    // ---- 1. the refusal is announced --------------------------------------

    #[Test]
    public function a_backup_the_provider_refused_outright_says_it_failed(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);

        $backup = app(RequestServiceBackup::class)
            ->execute($machine, notes: FakeBackupProvider::REFUSAL_MARKER);

        $this->assertSame(BackupState::Failed, $backup->refresh()->state);

        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupCompleted));
    }

    // ---- 2. a refusal is not a silence ------------------------------------

    #[Test]
    public function a_start_that_never_answered_still_says_it_needs_review(): void
    {
        /*
         * The other half of the same catch block, and the distinction the
         * whole module turns on: the provider saying no is not the provider
         * saying nothing. One means take another backup; the other means do
         * not, because one may already be running.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);

        app(BackupProviderFactory::class)->swap($machine->cluster()->firstOrFail(), new UnreachableBackupProvider);

        $backup = app(RequestServiceBackup::class)->execute($machine);

        $this->assertSame(BackupState::NeedsReview, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupFailed));
    }

    // ---- 3. an accepted start is not an outcome ---------------------------

    #[Test]
    public function a_start_the_provider_accepted_announces_nothing_yet(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);

        $backup = app(RequestServiceBackup::class)->execute($machine);

        $this->assertSame(BackupState::Running, $backup->refresh()->state);
        $this->assertSame(
            0,
            Notification::query()->where('customer_id', $customer->getKey())->count(),
            'A backup that has started has not finished, and there is nothing to report yet.',
        );
    }

    // ---- 4. a task that failed later is still exactly one message ---------

    #[Test]
    public function a_task_that_failed_after_starting_announces_once(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::TASK => new BackupTaskState(
                taskId: 'irrelevant',
                finished: true,
                successful: false,
                exitStatus: 'no space left on datastore',
            ),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(BackupState::Failed, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupFailed));
    }

    // ---- 5. one row, one event, whichever route noticed -------------------

    #[Test]
    public function a_refusal_at_the_door_uses_the_same_key_reconciliation_would(): void
    {
        /*
         * Asserted on the stored key rather than inferred from behaviour,
         * because the two routes cannot both run against one row — a row
         * refused at the door never gets a task id, so no sweep will ever
         * reach it. What has to hold is that if they ever did meet, they would
         * be one event; the key is the only thing that can carry that.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);

        $backup = app(RequestServiceBackup::class)
            ->execute($machine, notes: FakeBackupProvider::REFUSAL_MARKER);

        $announced = Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', NotificationType::BackupFailed->value)
            ->sole();

        $this->assertSame(
            BackupNotificationKey::backup((string) $backup->getKey(), 'failed'),
            $announced->idempotency_key,
            'The canonical key for this outcome, and not a second one qualified by which route found it.',
        );
    }

    #[Test]
    public function two_workers_settling_one_failure_tell_the_customer_once(): void
    {
        /*
         * The dedup mechanism itself, on the reconciliation route, where two
         * workers really can race. A loop of sweeps proves nothing here: the
         * first settles the row to Failed, which is terminal, so every later
         * call returns at isAwaitingProvider() before announcing anything.
         * This holds a model loaded before the first one settled.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::TASK => new BackupTaskState(taskId: 'x', finished: true, successful: false, exitStatus: 'refused'),
        ]);

        $racer = Backup::query()->findOrFail($backup->getKey());

        app(ReconcileBackup::class)->execute($backup);
        app(ReconcileBackup::class)->execute($racer);

        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupFailed));
    }

    #[Test]
    public function a_second_request_that_is_also_refused_is_a_second_event(): void
    {
        /*
         * And the key must not be so coarse that it swallows a real second
         * attempt. Every request creates its own row, so asking again and
         * being refused again is news again.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);

        app(RequestServiceBackup::class)->execute($machine, notes: FakeBackupProvider::REFUSAL_MARKER);
        app(RequestServiceBackup::class)->execute($machine, notes: FakeBackupProvider::REFUSAL_MARKER);

        $this->assertSame(2, $this->notifications($customer, NotificationType::BackupFailed));
    }

    // ---- 6. and it reaches nobody else ------------------------------------

    #[Test]
    public function a_refused_backup_never_reaches_another_customer(): void
    {
        [$alice] = $this->accountWithOwner();
        [$bob] = $this->accountWithOwner();

        $machine = $this->machineWithDatastore($alice);

        app(RequestServiceBackup::class)->execute($machine, notes: FakeBackupProvider::REFUSAL_MARKER);

        $this->assertSame(1, $this->notifications($alice, NotificationType::BackupFailed));
        $this->assertSame(
            0,
            Notification::query()->where('customer_id', $bob->getKey())->count(),
            "Another customer's refused backup is none of Bob's business.",
        );
    }

    // ---- 7. the provider's words stay out of both -------------------------

    #[Test]
    public function the_credential_a_refusal_quoted_back_reaches_neither_the_row_nor_the_message(): void
    {
        /*
         * The fake quotes the Authorization header back, because a real
         * hypervisor does. The row's reason is redacted before it is stored;
         * the notification carries no provider text at all, which is the
         * stronger guarantee — it is built from facts, and the sentence a
         * customer reads is written in the language files.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);

        $backup = app(RequestServiceBackup::class)
            ->execute($machine, notes: FakeBackupProvider::REFUSAL_MARKER);

        $this->assertStringNotContainsString('fake-pve-token', (string) $backup->refresh()->failure_reason);

        $announced = Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', NotificationType::BackupFailed->value)
            ->sole();

        $this->assertStringNotContainsString('fake-pve-token', json_encode($announced->data, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(
            (string) $backup->failure_reason,
            json_encode($announced->data, JSON_THROW_ON_ERROR),
            'The provider\'s words are for the operator reading the row, not for the customer\'s inbox.',
        );
    }

    // ---- 8. nothing else moved --------------------------------------------

    #[Test]
    public function the_other_outcomes_are_unchanged(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineWithDatastore($customer);

        $done = $this->runningBackupFor($customer, $machine);
        $this->datastore($machine, [
            self::TASK => new BackupTaskState(
                taskId: 'x',
                finished: true,
                successful: true,
                archiveId: 'vm/101/2026-09-21T00:00:00Z',
            ),
        ]);
        app(ReconcileBackup::class)->execute($done);

        $unreadable = $this->verifyingBackupFor($customer, $machine);
        $this->datastore($machine, [
            'UPID:pve-01:verify-1' => new BackupTaskState(taskId: 'x', finished: true, successful: false, exitStatus: 'bad chunk'),
        ]);
        app(ReconcileBackup::class)->execute($unreadable);

        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupCompleted));
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupVerificationFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupNeedsReview));
    }

    // ---- fixtures ---------------------------------------------------------

    private function machineWithDatastore(Customer $customer): VirtualMachine
    {
        $machine = $this->machineFor($customer);
        $cluster = $machine->cluster()->firstOrFail();

        config()->set('backups.datastores.'.($cluster->credentials_reference ?? $cluster->slug), 'pbs-test-01');

        return $machine;
    }

    /**
     * @param  array<string, BackupTaskState>  $tasks
     */
    private function datastore(VirtualMachine $machine, array $tasks): TwoTaskDatastore
    {
        $datastore = new TwoTaskDatastore($tasks);

        app(BackupProviderFactory::class)->swap($machine->cluster()->firstOrFail(), $datastore);

        return $datastore;
    }

    private function runningBackupFor(Customer $customer, VirtualMachine $machine): Backup
    {
        return Backup::factory()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'state' => BackupState::Running,
            'provider_task_id' => self::TASK,
            'started_at' => now(),
        ]);
    }

    private function verifyingBackupFor(Customer $customer, VirtualMachine $machine): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'state' => BackupState::Verifying,
            'provider_task_id' => 'UPID:pve-01:verify-1',
            'verification_task_id' => 'UPID:pve-01:verify-1',
            'started_at' => now(),
        ]);
    }

    private function notifications(Customer $customer, NotificationType $type): int
    {
        return Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', $type->value)
            ->count();
    }
}
