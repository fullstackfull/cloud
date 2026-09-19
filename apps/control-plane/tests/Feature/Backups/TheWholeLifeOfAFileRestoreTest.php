<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Backups\Application\Actions\ReconcileFileRestores;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\FileRestoreState;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileDownload;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\UnreachableBackupProvider;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * Files out of a backup, from the first listing to the audit row.
 *
 * What the suite establishes: that the file surface is a type question
 * answered by the provider (the fake can, Proxmox cannot, and the backup
 * row says which); that every path a customer sends is read into a value
 * object where the traversal family is refused by name; that a symlink is
 * shown and never followed — not browsed, not downloaded, not restored;
 * that a download link is single-use, short-lived and bound to the account
 * that minted it; that a restore clears the same bar as a whole-machine
 * restore and follows the Timeout Rule; and that nothing in any response
 * names where an archive lives.
 */
final class TheWholeLifeOfAFileRestoreTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');

        // One factory for the request and the poller. The factories are
        // deliberately not singletons in production — an adapter is per
        // cluster — so a test that steers one fake across two code paths
        // pins the container to a single instance.
        $this->app->singleton(BackupProviderFactory::class);
    }

    #[Test]
    public function a_backup_is_browsed_a_file_is_downloaded_once_and_named_paths_are_restored_polled_notified_and_audited(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        // The row says the files can be opened — the same answer the routes give.
        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/backups/'.$backup->id)
            ->assertOk()
            ->assertJsonPath('data.files.supported', true)
            ->assertJsonPath('data.files.reason', null);

        // Browse the root, then a directory.
        $root = $this->actingAs($user)->getJson($this->files($machine, $backup))->assertOk();
        $root->assertJsonPath('data.path', '/')->assertJsonPath('data.parent', null);
        $names = array_column($root->json('data.entries'), 'name');
        $this->assertContains('etc', $names);
        $this->assertContains('var', $names);

        $etc = $this->actingAs($user)->getJson($this->files($machine, $backup).'?path=/etc')->assertOk();
        $etc->assertJsonPath('data.parent', '/');
        $entries = collect($etc->json('data.entries'))->keyBy('name');
        $this->assertSame('file', $entries['hostname']['kind']);
        $this->assertSame('directory', $entries['nginx']['kind']);
        // The symlink is listed, marked, and offered for nothing.
        $this->assertSame('symlink', $entries['localtime']['kind']);
        $this->assertFalse($entries['localtime']['downloadable']);
        $this->assertFalse($entries['localtime']['browsable']);
        $this->assertFalse($entries['localtime']['restorable']);

        // Nothing about where the archive lives.
        $body = $etc->getContent();
        $this->assertStringNotContainsString($backup->datastore, $body);
        $this->assertStringNotContainsString($backup->node_name, $body);
        $this->assertStringNotContainsString((string) $backup->archive_id, $body);

        // A download link: minted once, followed once.
        $minted = $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/downloads', ['path' => '/etc/hostname'])
            ->assertCreated();
        $url = $minted->json('data.url');
        $this->assertMatchesRegularExpression('#^/api/v1/backups/downloads/[0-9a-f]{64}$#', $url);
        $this->assertSame(1, BackupFileDownload::query()->count());
        $this->assertStringNotContainsString(substr($url, -64), BackupFileDownload::query()->sole()->token_hash);

        $download = $this->actingAs($user)->get($url);
        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/octet-stream');
        $download->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('hostname', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('/etc/hostname from '.$backup->archive_id, $download->streamedContent());

        // Spent: the same link a second time is nothing.
        $this->actingAs($user)->get($url)->assertNotFound()->assertJsonPath('error.code', 'backup.download_unavailable');

        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::BackupFileDownloaded->value)->count());

        // Restore two paths — a file and a directory — with the hostname typed.
        $restore = $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => ['/etc/hostname', '/etc/nginx', '/etc/hostname'],
                'confirmation' => $machine->hostname,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.state', FileRestoreState::Running->value)
            ->assertJsonPath('data.is_in_flight', true)
            ->assertJsonPath('data.path_count', 2);

        $row = BackupFileRestore::query()->sole();
        $this->assertSame(['/etc/hostname', '/etc/nginx'], $row->paths);
        $this->assertNotNull($row->provider_task_id);
        $this->assertSame((string) $user->getKey(), $row->requested_by_user_id);
        // The backup itself is untouched: the archive was read, not consumed.
        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);

        $audit = AuditEntry::query()->where('action', AuditAction::BackupFilesRestored->value)->sole();
        $this->assertSame((string) $customer->getKey(), $audit->customer_id);
        $this->assertSame(['/etc/hostname', '/etc/nginx'], $audit->context['paths']);

        // A second restore into the machine while this one runs is refused.
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/var/www'], 'confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.file_restore_in_flight');

        // The poller follows the task: still running, then done.
        $sweep = app(ReconcileFileRestores::class)->execute();
        $this->assertSame(1, $sweep->considered);
        $this->assertSame(FileRestoreState::Running, $row->refresh()->state);

        app(ReconcileFileRestores::class)->execute();
        $this->assertSame(FileRestoreState::Succeeded, $row->refresh()->state);
        $this->assertNotNull($row->finished_at);

        $this->assertSame(1, Notification::query()->where('type', NotificationType::FileRestoreCompleted->value)->count());

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/backups/'.$backup->id.'/file-restores')
            ->assertOk()
            ->assertJsonPath('data.0.state', FileRestoreState::Succeeded->value);

        // The row's own screen: the same reconcile command that follows backups.
        $this->artisan('backups:reconcile')->assertExitCode(0);
    }

    #[Test]
    public function the_traversal_family_is_refused_by_name_before_any_provider_is_asked(): void
    {
        // Thirty requests in one test: the route throttles are not the thing under test.
        $this->withoutMiddleware(ThrottleRequests::class);

        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        foreach ([
            '/etc/../etc/passwd',
            '/etc/./hostname',
            '/etc//hostname',
            'etc/hostname',
            '/etc\\hostname',
            "/etc/host\0name",
            "/etc/host\nname",
            '/'.str_repeat('a/', 65),
            '/'.str_repeat('a', 256),
            "/etc/\xff",
        ] as $bad) {
            $this->actingAs($user)
                ->getJson($this->files($machine, $backup).'?'.http_build_query(['path' => $bad]))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'backup.file_path_invalid');

            $this->actingAs($user)
                ->postJson($this->files($machine, $backup).'/downloads', ['path' => $bad])
                ->assertStatus(422);

            $this->actingAs($user)
                ->postJson($this->files($machine, $backup).'/restore', ['paths' => [$bad], 'confirmation' => $machine->hostname])
                ->assertStatus(422);
        }

        $this->assertSame(0, BackupFileDownload::query()->count());
        $this->assertSame(0, BackupFileRestore::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function a_symlink_is_never_followed_browsed_downloaded_or_restored(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->getJson($this->files($machine, $backup).'?path=/etc/localtime')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.symlink_refused');

        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/downloads', ['path' => '/etc/localtime'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.symlink_refused');

        // Listed among good paths, it refuses the whole request.
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => ['/etc/hostname', '/etc/nginx/sites-enabled/default'],
                'confirmation' => $machine->hostname,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.symlink_refused');

        // A directory is not a download; a device is nothing; the root is
        // the whole machine; a missing path is missing.
        $this->actingAs($user)->postJson($this->files($machine, $backup).'/downloads', ['path' => '/etc'])->assertStatus(422)->assertJsonPath('error.code', 'backup.not_a_file');
        $this->actingAs($user)->postJson($this->files($machine, $backup).'/downloads', ['path' => '/dev/null'])->assertStatus(422)->assertJsonPath('error.code', 'backup.not_a_file');
        $this->actingAs($user)->postJson($this->files($machine, $backup).'/downloads', ['path' => '/var/www/archive.tar'])->assertStatus(422)->assertJsonPath('error.code', 'backup.file_too_large');
        $this->actingAs($user)->postJson($this->files($machine, $backup).'/downloads', ['path' => '/nope'])->assertStatus(404)->assertJsonPath('error.code', 'backup.file_not_found');
        $this->actingAs($user)->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/'], 'confirmation' => $machine->hostname])->assertStatus(422)->assertJsonPath('error.code', 'backup.file_path_invalid');
        $this->actingAs($user)->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/dev/null'], 'confirmation' => $machine->hostname])->assertStatus(422)->assertJsonPath('error.code', 'backup.not_a_file');

        $this->assertSame(0, BackupFileRestore::query()->count());
        $this->assertSame(0, BackupFileDownload::query()->count());
    }

    #[Test]
    public function a_download_link_is_the_accounts_own_expires_and_needs_the_session(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        $url = $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/downloads', ['path' => '/etc/hostname'])
            ->assertCreated()
            ->json('data.url');

        // Without a session: nothing, and the link is not spent by trying.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
        $this->assertNull(BackupFileDownload::query()->sole()->used_at);

        // Another account, holding the link: not found, not spent.
        [, $stranger] = $this->accountWithOwner();
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->assertNull(BackupFileDownload::query()->sole()->used_at);

        // A member who cannot manage services cannot follow it either.
        $billing = $this->memberOf($customer, CustomerRole::Billing);
        $this->actingAs($billing)->get($url)->assertForbidden();
        $this->assertNull(BackupFileDownload::query()->sole()->used_at);

        // Expired: the same 404.
        $this->travel(6)->minutes();
        $this->actingAs($user)->get($url)->assertNotFound()->assertJsonPath('error.code', 'backup.download_unavailable');
        $this->assertNull(BackupFileDownload::query()->sole()->used_at);

        // A token that was never issued.
        $this->actingAs($user)->get('/api/v1/backups/downloads/'.str_repeat('0', 64))->assertNotFound();
    }

    #[Test]
    public function another_tenants_backup_files_are_not_found_rather_than_forbidden(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        $myMachine = $this->machineFor($mine);

        [$theirs] = $this->accountWithOwner();
        $theirMachine = $this->machineFor($theirs);
        $theirBackup = $this->completedBackupFor($theirs, $theirMachine);

        $this->actingAs($me)->getJson($this->files($theirMachine, $theirBackup))->assertNotFound();
        $this->actingAs($me)->getJson($this->files($myMachine, $theirBackup))->assertNotFound();
        $this->actingAs($me)->postJson($this->files($theirMachine, $theirBackup).'/downloads', ['path' => '/etc/hostname'])->assertNotFound();
        $this->actingAs($me)
            ->postJson($this->files($theirMachine, $theirBackup).'/restore', ['paths' => ['/etc/hostname'], 'confirmation' => $theirMachine->hostname])
            ->assertNotFound();

        $this->assertSame(0, BackupFileRestore::query()->count());
    }

    #[Test]
    public function a_restore_clears_the_same_bar_as_a_whole_machine_restore(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-kw-01');
        $backup = $this->completedBackupFor($customer, $machine);

        // The hostname, exactly.
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/etc/hostname'], 'confirmation' => 'WEB-KW-01'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.file_restore_confirmation_mismatch');

        // A backup still being written cannot be opened at all.
        $running = Backup::factory()->running()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
        ]);
        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/backups/'.$running->id)
            ->assertOk()
            ->assertJsonPath('data.files.supported', false);
        $this->actingAs($user)->getJson($this->files($machine, $running))->assertStatus(422)->assertJsonPath('error.code', 'backup.files_unavailable');

        // Too many paths.
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => array_map(static fn (int $i): string => '/etc/'.$i, range(1, 51)),
                'confirmation' => 'web-kw-01',
            ])
            ->assertStatus(422);

        // A whole-machine restore in flight blocks a file restore.
        Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'state' => BackupState::Restoring,
            'provider_task_id' => 'UPID:fake:earlier',
        ]);
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/etc/hostname'], 'confirmation' => 'web-kw-01'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.file_restore_in_flight');

        // A member without service.manage cannot browse, let alone restore.
        $billing = $this->memberOf($customer, CustomerRole::Billing);
        $this->actingAs($billing)->getJson($this->files($machine, $backup))->assertForbidden();
        $this->actingAs($billing)
            ->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/etc/hostname'], 'confirmation' => 'web-kw-01'])
            ->assertForbidden();

        $this->assertSame(0, BackupFileRestore::query()->count());
    }

    #[Test]
    public function a_suspended_service_cannot_have_files_restored_into_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, status: ServiceStatus::Suspended);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/etc/hostname'], 'confirmation' => $machine->hostname])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.files_unavailable');
    }

    #[Test]
    public function a_provider_that_does_not_answer_leaves_the_restore_in_review_and_never_retries(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => ['/marker/'.FakeBackupProvider::TIMEOUT_MARKER],
                'confirmation' => $machine->hostname,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.state', FileRestoreState::NeedsReview->value)
            ->assertJsonPath('data.needs_attention', true);

        $row = BackupFileRestore::query()->sole();
        $this->assertNull($row->provider_task_id);
        $this->assertNull($row->finished_at);

        // Nothing polls it, nothing restarts it, and the machine is closed
        // to another restore until a person settles this one.
        app(ReconcileFileRestores::class)->execute();
        $this->assertSame(FileRestoreState::NeedsReview, $row->refresh()->state);

        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/etc/hostname'], 'confirmation' => $machine->hostname])
            ->assertStatus(409);

        $this->assertSame(1, BackupFileRestore::query()->count());
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::BackupFilesRestored->value)->count());
    }

    #[Test]
    public function a_refusal_fails_the_restore_with_the_reason_redacted_and_a_failing_task_is_reported(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => ['/marker/'.FakeBackupProvider::REFUSAL_MARKER],
                'confirmation' => $machine->hostname,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.state', FileRestoreState::Failed->value);

        $failed = BackupFileRestore::query()->sole();
        $this->assertNotNull($failed->failure_reason);
        $this->assertStringNotContainsString('fake-pve-token', (string) $failed->failure_reason);

        // Failed is settled: the machine is open again.
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => ['/marker/'.FakeBackupProvider::FAILING_MARKER],
                'confirmation' => $machine->hostname,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.state', FileRestoreState::Running->value);

        app(ReconcileFileRestores::class)->execute();
        app(ReconcileFileRestores::class)->execute();

        $second = BackupFileRestore::query()->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertNotNull($second);
        $this->assertSame(FileRestoreState::Failed, $second->state);
        $this->assertSame(1, Notification::query()->where('type', NotificationType::FileRestoreFailed->value)->count());
    }

    #[Test]
    public function a_poller_that_cannot_reach_the_provider_gives_up_after_the_deadline_and_tells_the_customer(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/etc/hostname'], 'confirmation' => $machine->hostname])
            ->assertStatus(202);

        $cluster = $machine->cluster()->firstOrFail();
        app(BackupProviderFactory::class)->swap($cluster, new UnreachableBackupProvider);

        app(ReconcileFileRestores::class)->execute();
        $row = BackupFileRestore::query()->sole();
        $this->assertSame(FileRestoreState::Running, $row->state);
        $this->assertSame(1, $row->poll_count);

        $this->travel(13)->hours();
        app(ReconcileFileRestores::class)->execute();

        $this->assertSame(FileRestoreState::NeedsReview, $row->refresh()->state);
        $this->assertSame(1, Notification::query()->where('type', NotificationType::FileRestoreNeedsReview->value)->count());
    }

    #[Test]
    public function a_provider_that_cannot_open_archives_says_so_on_the_row_and_refuses_every_file_route(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        // A provider without the file-level interface: the whole machine
        // can still be restored, and nothing else is offered.
        $cluster = $machine->cluster()->firstOrFail();
        app(BackupProviderFactory::class)->swap($cluster, new UnreachableBackupProvider);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/backups/'.$backup->id)
            ->assertOk()
            ->assertJsonPath('data.files.supported', false)
            ->assertJsonPath('data.is_restorable', true);

        $this->actingAs($user)->getJson($this->files($machine, $backup))->assertStatus(409)->assertJsonPath('error.code', 'backup.file_level_unsupported');
        $this->actingAs($user)->postJson($this->files($machine, $backup).'/downloads', ['path' => '/etc/hostname'])->assertStatus(409);
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', ['paths' => ['/etc/hostname'], 'confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.file_level_unsupported');

        $this->assertSame(0, BackupFileRestore::query()->count());
    }

    #[Test]
    public function the_path_value_object_normalises_and_compares(): void
    {
        $path = BackupPath::of('/etc/nginx/nginx.conf');

        $this->assertSame('nginx.conf', $path->name());
        $this->assertSame('/etc/nginx', $path->parent()->value);
        $this->assertSame('/', $path->parent()->parent()->parent()->value);
        $this->assertTrue(BackupPath::of('/')->isRoot());
        $this->assertSame('/', BackupPath::of('/')->name());
        $this->assertTrue($path->equals(BackupPath::of('/etc/nginx/nginx.conf')));
        $this->assertFalse($path->equals($path->parent()));
    }

    private function completedBackupFor(mixed $customer, mixed $machine): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
        ]);
    }

    private function files(mixed $machine, Backup $backup): string
    {
        return '/api/v1/vps/'.$machine->id.'/backups/'.$backup->id.'/files';
    }
}
