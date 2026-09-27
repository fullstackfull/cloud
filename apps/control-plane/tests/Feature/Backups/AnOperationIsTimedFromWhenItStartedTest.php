<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Database\Seeders\RolePermissionSeeder;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
use Lynomia\Modules\Backups\Application\Actions\SettleBackupReview;
use Lynomia\Modules\Backups\Application\Actions\VerifyStoredArchives;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\IllegalBackupTransitionException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * Each operation on an archive is timed from when THAT operation started (F-09).
 *
 * ---------------------------------------------------------------------------
 * The defect
 * ---------------------------------------------------------------------------
 *
 * `ReconcileBackup::giveUpIfOverdue` measured `backups.max_poll_hours` from the
 * archive's own `started_at` — when the backup was taken — whatever the row
 * was waiting on. Restoring a backup from three days ago is the normal case,
 * so the first poll of a restore that had been running for seconds read "72
 * hours overdue" and handed the row to a person, telling the customer "the
 * provider task was still unfinished after 12 hours". Three things followed:
 *
 *  - `assertRestorable` looked only for rows in `restoring`, so a second
 *    restore over the same disks was accepted (202) while the first provider
 *    task was still writing them;
 *  - `NeedsReview` had no way out, so a good archive was never restorable
 *    again;
 *  - the scheduled `backups:verify` sweep did the same to every archive older
 *    than the window it sent for verification, and told nobody.
 *
 * Every restoring and verifying fixture in the suite used an archive taken
 * minutes before, so nothing could go red. Every archive here is days old,
 * and every operation runs on the shipped simulator, through the real
 * request, the real sweeps and the real clock.
 */
final class AnOperationIsTimedFromWhenItStartedTest extends VpsApiTestCase
{
    private const int ARCHIVE_AGE_DAYS = 3;

    private int $backupsMade = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        config()->set('backups.max_poll_hours', 12);
        $this->app->singleton(BackupProviderFactory::class);
        $this->seed(RolePermissionSeeder::class);
    }

    // ---- the restore clock -------------------------------------------------

    #[Test]
    public function a_restore_of_an_archive_taken_days_ago_is_still_restoring_after_its_first_poll(): void
    {
        [$customer, $user, $machine, $old] = $this->restoreStartedOnAnOldArchive();

        app(ReconcileRunningBackups::class)->execute();

        $row = $old->refresh();
        $this->assertSame(BackupState::Restoring, $row->state, 'A restore started seconds ago is not overdue.');
        $this->assertNull($row->failure_reason);
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreNeedsReview));
    }

    #[Test]
    public function a_restore_goes_to_a_person_only_once_the_restore_itself_has_outrun_the_window(): void
    {
        [$customer, $user, $machine, $old] = $this->restoreStartedOnAnOldArchive();

        $this->travel(11)->hours();
        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::Restoring, $old->refresh()->state, 'Eleven hours into a twelve-hour window.');

        $this->travel(2)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $row = $old->refresh();
        $this->assertSame(BackupState::NeedsReview, $row->state);
        $this->assertSame(BackupState::Restoring, $row->quarantined_from, 'The row records which operation was interrupted.');

        // The reason names the operation and the clock it was measured on, and
        // the number in it is the restore's age — not the archive's.
        $this->assertStringContainsString('restore', (string) $row->failure_reason);
        $this->assertStringContainsString('13 hours', (string) $row->failure_reason);
        $this->assertStringNotContainsString(sprintf('%d hours', 13 + 24 * self::ARCHIVE_AGE_DAYS), (string) $row->failure_reason);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreNeedsReview));
    }

    #[Test]
    public function a_quarantined_restore_still_blocks_a_second_restore_of_the_same_machine(): void
    {
        [$customer, $user, $machine, $old, $other, $fake] = $this->restoreStartedOnAnOldArchive();

        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::NeedsReview, $old->refresh()->state);

        // Nobody has established that the first restore stopped writing.
        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $other), ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.restore_in_flight');

        $this->assertSame(BackupState::Succeeded, $other->refresh()->state);
    }

    #[Test]
    public function a_quarantined_restore_still_blocks_a_file_restore_on_the_same_machine(): void
    {
        [$customer, $user, $machine, $old, $other] = $this->restoreStartedOnAnOldArchive();

        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::NeedsReview, $old->refresh()->state);

        $this->actingAs($user)
            ->postJson(
                '/api/v1/vps/'.$machine->id.'/backups/'.$other->id.'/files/restore',
                ['paths' => ['/etc/hostname'], 'confirmation' => $machine->hostname],
            )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.file_restore_in_flight');
    }

    #[Test]
    public function a_restore_whose_start_never_answered_blocks_the_next_one(): void
    {
        /*
         * The other road into review with a restore possibly running: the
         * provider call timed out. It never reached the poller, and it used to
         * release the machine in exactly the same way.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $timedOut = $this->oldArchiveFor($customer, $machine, archive: 'vzdump-'.FakeBackupProvider::TIMEOUT_MARKER.'.vma.zst');
        $other = $this->oldArchiveFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $timedOut), ['confirmation' => $machine->hostname]);

        $this->assertSame(BackupState::NeedsReview, $timedOut->refresh()->state);
        $this->assertSame(BackupState::Restoring, $timedOut->quarantined_from);

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $other), ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.restore_in_flight');
    }

    #[Test]
    public function a_file_restore_still_writing_blocks_a_whole_machine_restore(): void
    {
        /*
         * The same two writers over the same disks, arriving in the other
         * order. The file route already refused while a whole-machine restore
         * ran; the whole-machine route did not look at file restores at all.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $source = $this->oldArchiveFor($customer, $machine);
        $other = $this->oldArchiveFor($customer, $machine);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        $this->actingAs($user)
            ->postJson(
                '/api/v1/vps/'.$machine->id.'/backups/'.$source->id.'/files/restore',
                ['paths' => ['/etc/hostname'], 'confirmation' => $machine->hostname],
            )
            ->assertStatus(202);

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $other), ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.restore_in_flight');

        $this->assertSame(BackupState::Succeeded, $other->refresh()->state);
    }

    // ---- the verification clock -------------------------------------------

    #[Test]
    public function the_verification_sweep_does_not_strand_an_archive_taken_days_ago(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $old = $this->oldArchiveFor($customer, $machine);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        app(VerifyStoredArchives::class)->execute();
        $this->assertSame(BackupState::Verifying, $old->refresh()->state);

        app(ReconcileRunningBackups::class)->execute();

        $row = $old->refresh();
        $this->assertSame(BackupState::Verifying, $row->state, 'A verification started seconds ago is not overdue.');
        $this->assertNull($row->failure_reason);
        $this->assertNotNull($row->verification_started_at, 'The clock this verification is measured on.');
    }

    #[Test]
    public function a_verification_that_really_outruns_the_window_goes_to_a_person_and_the_customer_is_told(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $old = $this->oldArchiveFor($customer, $machine);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        app(VerifyStoredArchives::class)->execute();

        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();

        $row = $old->refresh();
        $this->assertSame(BackupState::NeedsReview, $row->state);
        $this->assertSame(BackupState::Verifying, $row->quarantined_from);
        $this->assertStringContainsString('verification', (string) $row->failure_reason);
        $this->assertStringContainsString('13 hours', (string) $row->failure_reason);
        $this->assertNull($row->verified, 'Losing track of a verification is not a verdict on the data.');

        // The archive has just stopped being restorable in the portal; the
        // customer is owed a word about that, once.
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupVerificationFailed));
    }

    // ---- the way back -------------------------------------------------------

    #[Test]
    public function an_operator_who_has_looked_can_confirm_a_quarantined_restore_finished(): void
    {
        [$customer, $user, $machine, $old, $other] = $this->restoreStartedOnAnOldArchive();
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', [
                'verdict' => 'completed',
                'evidence' => 'qmrestore task log on pve-01 ends TASK OK at 02:14',
                'review' => $this->reviewOf($old),
            ])
            ->assertOk()
            ->assertJsonPath('data.state', BackupState::Restored->value);

        $row = $old->refresh();
        $this->assertSame(BackupState::Restored, $row->state);
        $this->assertNotNull($row->restored_at);
        $this->assertNull($row->quarantined_from);
        $this->assertTrue($row->isRestorable(), 'The archive is a good archive again.');

        $entry = AuditEntry::query()->where('action', AuditAction::BackupOperationConfirmed)->sole();
        $this->assertSame($old->id, $entry->subject_id);
        $this->assertSame($customer->id, $entry->customer_id);
        $this->assertStringContainsString('TASK OK', (string) ($entry->context['evidence'] ?? ''));
        $this->assertSame('restoring', $entry->context['operation'] ?? null);
        $this->assertTrue($entry->action->isAnAssertionAboutTheWorld());

        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));

        // And the machine is released: a person has said the disks are done.
        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $other), ['confirmation' => $machine->hostname])
            ->assertStatus(202);
    }

    #[Test]
    public function an_operator_can_record_that_a_quarantined_restore_failed_and_the_archive_is_offered_again(): void
    {
        [$customer, $user, $machine, $old] = $this->restoreStartedOnAnOldArchive();
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', [
                'verdict' => 'failed',
                'evidence' => 'task was killed by the node reboot at 01:00; disks untouched',
                'review' => $this->reviewOf($old),
            ])
            ->assertOk()
            ->assertJsonPath('data.state', BackupState::Succeeded->value);

        $row = $old->refresh();
        $this->assertSame(BackupState::Succeeded, $row->state);
        $this->assertNull($row->restored_at);
        $this->assertNotNull($row->failure_reason);

        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::BackupOperationFailed)->count());
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreFailed));

        $listed = collect($this->actingAs($user)->getJson('/api/v1/vps/'.$machine->id.'/backups')->assertOk()->json('data'))
            ->keyBy('id');
        $this->assertTrue($listed[$old->id]['is_restorable']);
    }

    #[Test]
    public function an_operator_can_return_a_quarantined_verification_with_either_verdict(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $readable = $this->oldArchiveFor($customer, $machine);
        $unreadable = $this->oldArchiveFor($customer, $machine);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        app(VerifyStoredArchives::class)->execute();
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $this->assertSame(BackupState::NeedsReview, $readable->refresh()->state);
        $this->assertSame(BackupState::NeedsReview, $unreadable->refresh()->state);

        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson('/api/admin/backups/'.$readable->id.'/resolve', ['verdict' => 'completed', 'evidence' => 'PBS verify job log: OK', 'review' => $this->reviewOf($readable)])
            ->assertOk()
            ->assertJsonPath('data.state', BackupState::Verified->value);

        $this->actingAs($operator)
            ->postJson('/api/admin/backups/'.$unreadable->id.'/resolve', ['verdict' => 'failed', 'evidence' => 'PBS verify: chunk 9ac1 missing', 'review' => $this->reviewOf($unreadable)])
            ->assertOk()
            ->assertJsonPath('data.state', BackupState::Failed->value);

        $this->assertTrue($readable->refresh()->verified);
        $this->assertNotNull($readable->verified_at);
        $this->assertFalse($unreadable->refresh()->verified);
        $this->assertFalse($unreadable->isRestorable());
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupVerificationFailed));
    }

    #[Test]
    public function the_platform_lists_what_is_waiting_and_says_which_operation_was_interrupted(): void
    {
        [$customer, $user, $machine, $old] = $this->restoreStartedOnAnOldArchive();
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $rows = $this->actingAs($this->operator())
            ->getJson('/api/admin/backups/needs-review')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($old->id, $rows[0]['id']);
        $this->assertSame('restoring', $rows[0]['interrupted_operation']);
        $this->assertTrue($rows[0]['resolvable']);
    }

    #[Test]
    public function a_quarantine_the_platform_cannot_attribute_to_an_archive_operation_is_refused(): void
    {
        /*
         * A backup that never reported its archive, or a deletion whose
         * outcome is unknown, is not "a good archive quarantined by mistake":
         * settling either needs facts this route does not take. Refused, and
         * the row is left exactly as it was.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $lost = Backup::factory()->running('UPID:fake:lost')->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
        ]);
        $lost->transitionTo(BackupState::NeedsReview, ['failure_reason' => 'the provider stopped answering']);
        $this->assertSame(BackupState::Running, $lost->refresh()->quarantined_from);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$lost->id.'/resolve', ['verdict' => 'completed', 'evidence' => 'I think it worked', 'review' => $this->reviewOf($lost)])
            ->assertStatus(422);

        $this->assertSame(BackupState::NeedsReview, $lost->refresh()->state);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function a_row_that_is_not_waiting_for_a_person_is_refused(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $fine = $this->oldArchiveFor($customer, $machine);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$fine->id.'/resolve', ['verdict' => 'failed', 'evidence' => 'mistake', 'review' => str_repeat('0', 64)])
            ->assertStatus(422);

        $this->assertSame(BackupState::Succeeded, $fine->refresh()->state);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function settling_a_backup_needs_the_backup_permission(): void
    {
        [$customer, $user, $machine, $old] = $this->restoreStartedOnAnOldArchive();
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $body = ['verdict' => 'completed', 'evidence' => 'looked', 'review' => (string) Backup::query()->findOrFail($old->id)->reviewToken()];

        // The queue itself shows archive and task identifiers across every
        // account: the same permission, not merely a login.
        $this->actingAs($this->operator(Role::Support))->getJson('/api/admin/backups/needs-review')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/backups/needs-review')->assertForbidden();
        $this->actingAs($this->operator(Role::InfrastructureAdmin))->getJson('/api/admin/backups/needs-review')->assertOk();

        $this->actingAs($this->operator(Role::Support))
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', $body)
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', $body)
            ->assertForbidden();

        $this->actingAs($this->operator(Role::InfrastructureAdmin))
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', $body)
            ->assertOk();
    }

    #[Test]
    public function two_verdicts_on_one_row_are_one_verdict_and_one_refusal(): void
    {
        /*
         * Two operators working from the same list: both read the row in
         * review, both decide. The first verdict stands; the second is refused
         * and leaves no trail and no second message. Driven through the
         * action with the copy both of them read, because the controller
         * re-reads and would hide the race.
         */
        [$customer, $user, $machine, $old] = $this->restoreStartedOnAnOldArchive();
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $seenByBoth = Backup::query()->findOrFail($old->id);
        $this->assertSame(BackupState::NeedsReview, $seenByBoth->state);

        app(SettleBackupReview::class)->execute($seenByBoth, completed: true, evidence: 'TASK OK', resolvedBy: 'first', review: (string) $seenByBoth->reviewToken());

        try {
            app(SettleBackupReview::class)->execute($seenByBoth, completed: false, evidence: 'task killed', resolvedBy: 'second', review: (string) $seenByBoth->reviewToken());
            $this->fail('A second verdict on a settled row must be refused.');
        } catch (IllegalBackupTransitionException) {
            // refused
        }

        $this->assertSame(BackupState::Restored, $old->refresh()->state);
        $this->assertSame(1, AuditEntry::query()->whereIn('action', [AuditAction::BackupOperationConfirmed, AuditAction::BackupOperationFailed])->count());
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreFailed));
    }

    #[Test]
    public function a_verdict_on_the_review_an_operator_read_does_not_settle_a_later_one(): void
    {
        /*
         * An operator reads the list and goes to the node. Meanwhile somebody
         * else settles that review, the customer restores again, and the
         * second restore is lost in turn: the row is back in review, for an
         * attempt this operator never looked at. Their verdict — "it
         * finished", about the first restore — is not about the second, and
         * the settling request's own fresh read of the row cannot tell.
         */
        [$customer, $user, $machine, $old] = $this->restoreStartedOnAnOldArchive();
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $seen = $this->reviewOf($old);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', ['verdict' => 'failed', 'evidence' => 'task killed by the reboot', 'review' => $seen])
            ->assertOk();

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $old), ['confirmation' => $machine->hostname])
            ->assertStatus(202);
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::NeedsReview, $old->refresh()->state);
        $audited = AuditEntry::query()->count();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', ['verdict' => 'completed', 'evidence' => 'TASK OK on the first restore', 'review' => $seen])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.review_changed');

        $row = $old->refresh();
        $this->assertSame(BackupState::NeedsReview, $row->state, 'A verdict on the first restore is not one on the second.');
        $this->assertNull($row->restored_at);
        $this->assertSame($audited, AuditEntry::query()->count(), 'Nothing is recorded for a refused verdict.');
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreCompleted));

        // The positive control: the review as it now stands can be settled.
        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', ['verdict' => 'completed', 'evidence' => 'TASK OK on the second restore', 'review' => $this->reviewOf($old)])
            ->assertOk()
            ->assertJsonPath('data.state', BackupState::Restored->value);
    }

    #[Test]
    public function a_verdict_that_names_no_review_is_refused(): void
    {
        // Without the token a verdict would settle whatever review the row
        // holds when it arrives, which is the thing the token exists to stop.
        [$customer, $user, $machine, $old] = $this->restoreStartedOnAnOldArchive();
        $this->travel(13)->hours();
        app(ReconcileRunningBackups::class)->execute();
        $audited = AuditEntry::query()->count();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$old->id.'/resolve', ['verdict' => 'completed', 'evidence' => 'TASK OK'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['review']]]]);

        $this->assertSame(BackupState::NeedsReview, $old->refresh()->state);
        $this->assertSame($audited, AuditEntry::query()->count());
    }

    #[Test]
    public function each_handle_less_restore_attempt_is_its_own_message(): void
    {
        /*
         * A restore whose start never answered has no task to key its message
         * on. Settled by a person and tried again, the second attempt is a
         * second event, and the customer is owed a second word about it.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $timedOut = $this->oldArchiveFor($customer, $machine, archive: 'vzdump-'.FakeBackupProvider::TIMEOUT_MARKER.'.vma.zst');
        $url = $this->restoreUrl($machine, $timedOut);

        $this->actingAs($user)->postJson($url, ['confirmation' => $machine->hostname]);
        $this->assertSame(BackupState::NeedsReview, $timedOut->refresh()->state);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/backups/'.$timedOut->id.'/resolve', ['verdict' => 'failed', 'evidence' => 'no task on the node', 'review' => $this->reviewOf($timedOut)])
            ->assertOk();

        $this->travel(1)->hours();
        $this->actingAs($user)->postJson($url, ['confirmation' => $machine->hostname]);
        $this->assertSame(BackupState::NeedsReview, $timedOut->refresh()->state);

        $this->assertSame(2, $this->notifications($customer, NotificationType::RestoreNeedsReview));
    }

    #[Test]
    public function a_restore_is_polled_as_a_new_operation(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $old = $this->oldArchiveFor($customer, $machine);
        $old->forceFill(['poll_count' => 7, 'last_polled_at' => now()->subDays(2)])->save();
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $old), ['confirmation' => $machine->hostname])
            ->assertStatus(202);

        $row = $old->refresh();
        $this->assertSame(0, $row->poll_count, 'The backup\'s poll history is not the restore\'s.');
        $this->assertNull($row->last_polled_at, 'Asked about ahead of rows already polled.');
    }

    // ---- fixtures -----------------------------------------------------------

    /**
     * A restore of an archive taken days ago, started now through the
     * customer's own request, with the provider still writing.
     *
     * @return array{0: Customer, 1: User, 2: VirtualMachine, 3: Backup, 4: Backup, 5: FakeBackupProvider}
     */
    private function restoreStartedOnAnOldArchive(): array
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $old = $this->oldArchiveFor($customer, $machine);
        $other = $this->oldArchiveFor($customer, $machine);

        $fake = $this->fake($machine);
        $fake->pollsBeforeSettling = 1_000;

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $old), ['confirmation' => $machine->hostname])
            ->assertStatus(202);

        $this->assertSame(BackupState::Restoring, $old->refresh()->state);

        return [$customer, $user, $machine, $old, $other, $fake];
    }

    private function oldArchiveFor(Customer $customer, VirtualMachine $machine, ?string $archive = null): Backup
    {
        return Backup::factory()->takenHoursAgo(24 * self::ARCHIVE_AGE_DAYS)->create(array_filter([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'provider_task_id' => 'UPID:fake:taken-'.(++$this->backupsMade),
            'archive_id' => $archive,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function fake(VirtualMachine $machine): FakeBackupProvider
    {
        $provider = app(BackupProviderFactory::class)->for($machine->cluster()->firstOrFail());
        $this->assertInstanceOf(FakeBackupProvider::class, $provider);

        return $provider;
    }

    private function restoreUrl(VirtualMachine $machine, Backup $backup): string
    {
        return '/api/v1/vps/'.$machine->id.'/backups/'.$backup->id.'/restore';
    }

    /**
     * The token the review list gives for this row: what an operator's
     * verdict carries back.
     */
    private function reviewOf(Backup $backup): string
    {
        $rows = $this->actingAs($this->operator())->getJson('/api/admin/backups/needs-review?per_page=100')->assertOk()->json('data');

        foreach ($rows as $row) {
            if ($row['id'] === $backup->id) {
                $this->assertIsString($row['review']);

                return $row['review'];
            }
        }

        $this->fail('The row is not on the review list.');
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function notifications(Customer $customer, NotificationType $type): int
    {
        return Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', $type->value)
            ->count();
    }
}
