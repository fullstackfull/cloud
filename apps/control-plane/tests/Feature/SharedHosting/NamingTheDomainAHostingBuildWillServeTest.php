<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingHostingProvider;
use Tests\TestCase;

/**
 * The operator's way out of a hosting build that was refused over its name.
 *
 * Two refusals send a build here: the job names no domain at all (every order
 * placed before checkout asked for one), or it names one another live account
 * already serves. Both are refused before the panel is asked for anything, and
 * both would be refused identically on every retry — because a retry is not a
 * repair. It runs the same job again.
 *
 * So naming the domain is its own act, separate from retry: it writes the
 * job's payload and nothing else, it is audited, and it answers the same
 * question the build will ask (is this name free?) before it accepts one. Then
 * an ordinary retry builds under the corrected name.
 *
 * The traps it walks are the ones earlier rounds fell into:
 *
 *  - a correction the build silently discarded, handing the panel the stale
 *    name on a job reporting success — for a row an earlier attempt left
 *    released, and for one it left pending;
 *  - a surface that refused to name a job back to its own row's name, because
 *    it counted the job's own row as somebody else serving it.
 */
final class NamingTheDomainAHostingBuildWillServeTest extends TestCase
{
    use RefreshDatabase;

    private RecordingHostingProvider $panel;

    private HostingNode $node;

    private Customer $customer;

    private HostingPackage $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->app->singleton(HostingProviderFactory::class);

        $this->node = HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'max_accounts' => 50,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
        ]);

        $this->panel = new RecordingHostingProvider(new FakeHostingProvider);
        app(HostingProviderFactory::class)->swap($this->node, $this->panel);

        $this->customer = Customer::factory()->create();
        $this->package = HostingPackage::factory()->create();
    }

    #[Test]
    public function an_operator_names_the_domain_and_a_retry_builds_under_it(): void
    {
        $job = $this->failedJob(['primary_domain' => null]);

        $this->nameIt($job, ' Right.Example.Test. ')
            ->assertOk()
            ->assertJsonPath('data.primary_domain', 'right.example.test');

        $fresh = $job->fresh();
        $this->assertSame('right.example.test', $fresh?->payload['primary_domain'] ?? null);

        // The payload and nothing else: it is still a failed job, and running
        // it again is a separate decision.
        $this->assertSame(ProvisioningJobStatus::Failed, $fresh?->status);
        $this->assertSame([], $this->panel->creates);

        $entry = AuditEntry::query()->where('action', AuditAction::HostingJobDomainNamed)->sole();
        $this->assertSame('right.example.test', $entry->context['primary_domain'] ?? null);
        $this->assertNull($entry->context['previous_domain'] ?? null);
        $this->assertStringContainsString('customer confirmed', (string) ($entry->context['evidence'] ?? ''));

        $this->retry($job)->assertOk();

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertCount(1, $this->panel->creates);
        $this->assertSame('right.example.test', $this->panel->creates[0]->primaryDomain);
    }

    #[Test]
    public function a_correction_reaches_the_panel_when_an_earlier_attempt_left_a_released_row(): void
    {
        /*
         * The check-then-act gap. The earlier attempt's row was released
         * (the panel refused it outright, nothing was built) and still names
         * the old domain. The build used to ask whether the NEW name was
         * free, be told yes, and then re-arm the row with the status alone —
         * so the panel was handed the old name on a job reporting success.
         */
        $job = $this->failedJob(['primary_domain' => 'wrong.example.test', 'username' => 'released']);
        $this->rowFor($job, 'wrong.example.test', HostingAccountStatus::Failed);

        $this->nameIt($job, 'right.example.test')->assertOk();
        $this->retry($job)->assertOk();

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertSame('right.example.test', $this->panel->creates[0]->primaryDomain ?? null);

        $row = HostingAccount::query()->where('username', 'released')->sole();
        $this->assertSame('right.example.test', $row->primary_domain);
        $this->assertSame(HostingAccountStatus::Active, $row->status);
    }

    #[Test]
    public function a_row_the_panel_may_hold_under_the_old_name_is_refused_rather_than_built_under_it(): void
    {
        /*
         * A pending row exists from the moment the slot is taken, before the
         * panel is called, so a worker that died either side of the create
         * leaves an identical row. The reservation cannot tell which, and it
         * decides under the node's lock, where it may not call a panel. So it
         * refuses rather than guess: building would either hand the panel the
         * stale name on a job reporting success, or retry for ever under a
         * name the panel already holds.
         */
        $job = $this->failedJob(['primary_domain' => 'wrong.example.test', 'username' => 'pendingrow']);
        $this->rowFor($job, 'wrong.example.test', HostingAccountStatus::Pending);

        $this->nameIt($job, 'right.example.test')->assertOk();
        $this->retry($job)->assertOk();

        $fresh = $job->fresh();
        $this->assertSame(ProvisioningJobStatus::Failed, $fresh?->status);
        $this->assertSame(FailureClass::Permanent, $fresh?->failure_class);
        $this->assertSame('hosting.account_serves_another_domain', $fresh?->result['error']['code'] ?? null);
        $this->assertSame([], $this->panel->creates, 'the panel was handed a name the row does not serve');

        // And the way back is open: name it back to the row's own name.
        $this->nameIt($job, 'wrong.example.test')->assertOk();
        $this->retry($job)->assertOk();

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertSame('wrong.example.test', $this->panel->creates[0]->primaryDomain ?? null);
    }

    #[Test]
    public function the_jobs_own_row_never_blocks_the_job_from_naming_its_own_name(): void
    {
        foreach ([HostingAccountStatus::Pending, HostingAccountStatus::Active] as $index => $status) {
            $job = $this->failedJob(['primary_domain' => 'own-'.$index.'.example.test', 'username' => 'ownrow'.$index]);
            $this->rowFor($job, 'own-'.$index.'.example.test', $status);

            // Forward…
            $this->nameIt($job, 'moved-'.$index.'.example.test')->assertOk();

            // …and back. This is the correction the build accepts, and the
            // surface must not refuse it on the strength of the job's own row.
            $this->nameIt($job, 'own-'.$index.'.example.test')
                ->assertOk()
                ->assertJsonPath('data.primary_domain', 'own-'.$index.'.example.test');
        }
    }

    #[Test]
    public function a_name_another_live_account_serves_is_refused(): void
    {
        HostingAccount::factory()->create(['primary_domain' => 'taken.example.test']);
        $job = $this->failedJob(['primary_domain' => 'mine.example.test']);

        $this->nameIt($job, 'Taken.Example.Test')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.domain_in_use');

        $this->assertSame('mine.example.test', $job->fresh()?->payload['primary_domain'] ?? null);
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::HostingJobDomainNamed)->count());
    }

    #[Test]
    public function a_name_that_is_not_a_host_name_is_refused(): void
    {
        $job = $this->failedJob(['primary_domain' => null]);

        $this->nameIt($job, 'https://shop.example.test/')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'hosting.domain_unusable');
    }

    #[Test]
    public function only_a_stopped_hosting_build_can_be_renamed(): void
    {
        $running = $this->failedJob(['primary_domain' => null]);
        $running->forceFill(['status' => ProvisioningJobStatus::Running])->save();

        $this->nameIt($running, 'shop.example.test')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.job_not_settled');

        $vps = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateVps)->create([
            'status' => ProvisioningJobStatus::Failed,
        ]);

        $this->nameIt($vps, 'shop.example.test')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.job_not_a_hosting_build');
    }

    #[Test]
    public function naming_needs_the_permission_that_changes_what_the_platform_believes(): void
    {
        $job = $this->failedJob(['primary_domain' => null]);

        $this->actingAs($this->operator(Role::Support))
            ->putJson('/api/admin/provisioning/jobs/'.$job->id.'/hosting-domain', [
                'domain' => 'shop.example.test',
                'evidence' => 'customer confirmed the name by ticket',
            ])
            ->assertForbidden();
    }

    // ---- fixtures ---------------------------------------------------------

    private function nameIt(ProvisioningJob $job, string $domain): TestResponse
    {
        return $this->actingAs($this->operator())
            ->putJson('/api/admin/provisioning/jobs/'.$job->id.'/hosting-domain', [
                'domain' => $domain,
                'evidence' => 'customer confirmed the name by ticket 4411',
            ]);
    }

    private function retry(ProvisioningJob $job): TestResponse
    {
        return $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', [
                'evidence' => 'the domain was corrected on the job',
            ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function failedJob(array $payload): ProvisioningJob
    {
        return ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $this->customer->getKey(),
            'status' => ProvisioningJobStatus::Failed,
            'failure_class' => FailureClass::Permanent,
            'attempts' => 1,
            'max_attempts' => 3,
            'payload' => array_filter([
                'hosting_package_id' => (string) $this->package->getKey(),
                ...$payload,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function rowFor(ProvisioningJob $job, string $domain, HostingAccountStatus $status): HostingAccount
    {
        $account = HostingAccount::factory()->status($status)->create([
            'hosting_node_id' => $this->node->getKey(),
            'hosting_package_id' => $this->package->getKey(),
            'customer_id' => $this->customer->getKey(),
            'username' => (string) ($job->payload['username'] ?? ''),
            'primary_domain' => $domain,
        ]);

        if ($status->occupiesNodeCapacity()) {
            $this->node->increment('account_count');
        }

        return $account;
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
