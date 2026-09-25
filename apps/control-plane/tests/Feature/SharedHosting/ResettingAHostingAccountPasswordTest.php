<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PanelThatLosesItsAnswer;
use Tests\TestCase;

/**
 * F-04's last clause: "`changePassword` is implemented and has no caller."
 *
 * The harm it bounds is real and the handler states it: every attempt sets a
 * freshly generated password and the platform stores none of it, so an
 * attempt whose `createacct` answer was lost leaves a live account holding a
 * credential nobody has. A customer reaches the panel by single sign-on and
 * never needed it — which is exactly why this survived six audits — but an
 * operator who must get into that account, or hand it over, had no way to set
 * one. This is that way.
 *
 * The reproduction uses {@see PanelThatLosesItsAnswer}, a decorator rather than
 * a simulator: the inner panel really builds the account, and then the answer
 * is lost. That is the half the controlled provider's timeout marker does not
 * cover, because the marker refuses before recording anything.
 */
final class ResettingAHostingAccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    private HostingNode $node;

    private PanelThatLosesItsAnswer $panel;

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

        $this->panel = new PanelThatLosesItsAnswer(new FakeHostingProvider);
        app(HostingProviderFactory::class)->swap($this->node, $this->panel);
    }

    #[Test]
    public function an_account_whose_create_answer_was_lost_can_be_given_a_password_nobody_had(): void
    {
        $account = $this->anAccountWhoseAnswerWasLost();

        $lost = $this->panel->creates[0]->password;

        $response = $this->actingAs($this->operator())
            ->postJson($this->url($account), ['reason' => 'handing the account over to the customer by ticket 5120'])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $account->getKey())
            ->assertJsonPath('data.username', $account->username);

        $issued = (string) $response->json('data.password');

        // Minted here, not typed by a person and not accepted from the body.
        $this->assertSame(CreateHostingAccountHandler::PASSWORD_LENGTH, strlen($issued));
        $this->assertNotSame($lost, $issued);

        // Set at the panel, once, on the account the platform recorded.
        $this->assertSame([['username' => $account->username, 'password' => $issued]], $this->panel->passwordChanges);

        // And kept nowhere: the audit row says it happened and never what it was.
        $entry = AuditEntry::query()->where('action', AuditAction::HostingAccountPasswordReset)->sole();
        $this->assertSame((string) $account->customer_id, $entry->customer_id);
        $this->assertSame($account->username, $entry->context['username'] ?? null);

        foreach (['audit_log', 'hosting_accounts', 'provisioning_jobs', 'provisioning_attempts'] as $table) {
            $this->assertStringNotContainsString($issued, DB::table($table)->get()->toJson(), $table);
        }
    }

    #[Test]
    public function the_body_cannot_choose_the_password(): void
    {
        $account = $this->anAccountWhoseAnswerWasLost();

        $response = $this->actingAs($this->operator())
            ->postJson($this->url($account), [
                'reason' => 'the customer asked for a specific one',
                'password' => 'chosen-by-a-person-under-pressure',
            ])
            ->assertOk();

        $this->assertNotSame('chosen-by-a-person-under-pressure', $response->json('data.password'));
        $this->assertNotSame('chosen-by-a-person-under-pressure', $this->panel->passwordChanges[0]['password']);
    }

    #[Test]
    public function an_account_the_panel_no_longer_holds_is_refused_without_asking_it(): void
    {
        foreach ([HostingAccountStatus::Terminated, HostingAccountStatus::Failed] as $status) {
            $account = HostingAccount::factory()->status($status)->create(['hosting_node_id' => $this->node->getKey()]);

            $this->actingAs($this->operator())
                ->postJson($this->url($account), ['reason' => 'checking a gone account'])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'hosting.password_reset_refused');
        }

        $this->assertSame([], $this->panel->passwordChanges);
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::HostingAccountPasswordReset)->count());
    }

    #[Test]
    public function a_panel_that_refuses_leaves_no_record_that_a_reset_happened(): void
    {
        /*
         * Panel first, then audit: recording first would write down an act
         * that may not have happened, on a table whose rows can be neither
         * updated nor deleted.
         */
        $account = HostingAccount::factory()->create(['hosting_node_id' => $this->node->getKey()]);

        $this->actingAs($this->operator())
            ->postJson($this->url($account), ['reason' => 'the panel does not hold this one'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'hosting.provider_request_failed')
            ->assertJsonPath('error.message', 'The hosting panel did not complete that request. Our team has the details.');

        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::HostingAccountPasswordReset)->count());
    }

    #[Test]
    public function it_has_its_own_permission_and_managing_accounts_is_not_it(): void
    {
        /*
         * Destructiveness is not the axis. Terminating is loud and visible to
         * the customer within the hour; a reset is quiet and hands the holder
         * a live login to the customer's mail, files and databases — and
         * before this there was no operator path into a customer's panel at
         * all.
         */
        $account = $this->anAccountWhoseAnswerWasLost();

        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::HostingAccountManage->value);

        $this->actingAs($manager)
            ->postJson($this->url($account), ['reason' => 'trying with manage alone'])
            ->assertForbidden();

        $this->actingAs($this->operator(Role::Support))
            ->postJson($this->url($account), ['reason' => 'trying as support'])
            ->assertForbidden();

        // The role that already held manage holds this too, so no
        // installation's effective authority moved.
        $this->actingAs($this->operator(Role::InfrastructureAdmin))
            ->postJson($this->url($account), ['reason' => 'handing over by ticket 5121'])
            ->assertOk();

        $this->assertCount(1, $this->panel->passwordChanges);
    }

    #[Test]
    public function it_is_rate_limited_as_the_credential_reset_it_is(): void
    {
        $account = $this->anAccountWhoseAnswerWasLost();

        /*
         * One principal for every call. The limiter is keyed on the user, so
         * a fresh operator per call would be five buckets and would measure
         * exactly what no limiter at all measures.
         */
        $operator = $this->operator();

        $statuses = [];

        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->actingAs($operator)
                ->postJson($this->url($account), ['reason' => 'rate limit probe '.$i])
                ->status();
        }

        $this->assertSame([200, 200, 200, 429, 429], $statuses);
        $this->assertCount(3, $this->panel->passwordChanges);
    }

    // ---- fixtures ---------------------------------------------------------

    /**
     * An account the panel holds and the platform never heard about: the
     * create was accepted, the answer was lost, the job timed out, and the row
     * is still pending and occupying its slot — so nothing releases it and
     * nothing terminates it.
     */
    private function anAccountWhoseAnswerWasLost(): HostingAccount
    {
        $customer = Customer::factory()->create();

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $customer->getKey(),
            'payload' => [
                'hosting_package_id' => (string) HostingPackage::factory()->create()->getKey(),
                'primary_domain' => 'lost-answer-'.strtolower(substr((string) $customer->getKey(), -6)).'.example.test',
            ],
        ]);

        $result = app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertSame(FailureClass::Timeout, $result->failureClass);

        $account = HostingAccount::query()->where('customer_id', $customer->getKey())->sole();
        $this->assertSame(HostingAccountStatus::Pending, $account->status);

        // The panel holds it, under the password it was handed.
        $held = array_map(
            static fn (RemoteAccount $remote): string => $remote->username,
            $this->panel->listAccounts($this->node),
        );
        $this->assertContains($account->username, $held);

        return $account;
    }

    private function url(HostingAccount $account): string
    {
        return '/api/admin/hosting-accounts/'.$account->getKey().'/password-reset';
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
