<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A physical machine's whole life, as far as software can take it.
 *
 * The end of this chain is where the platform deliberately stops. A VPS
 * termination destroys the machine and quarantines its address, entirely in
 * software. A dedicated server cannot be finished that way: the disks in it
 * hold the customer's data until somebody erases them, and no call this
 * platform can make proves that happened. So a decommission takes the machine
 * off the customer and holds it in maintenance, and a second, deliberate act —
 * a person saying what they did — puts it back on the shelf.
 *
 * The state machine has said so since it was written: `active → maintenance`,
 * then `maintenance → available`, commented as "the path a decommissioned
 * service takes once the disks have been erased". Until now nothing could
 * travel either edge, so a returned machine stayed attached to the customer
 * who left, and no other customer could ever be sold it.
 *
 * The reinstall's hardware half stays hardware-blocked and is not claimed
 * here: what this proves is that the operation is created, recorded, and
 * visible with its own state.
 */
final class TheWholeLifeOfADedicatedServerTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    private Service $service;

    private DedicatedServer $server;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create();

        $this->customer->members()->create([
            'user_id' => $this->user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $this->subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'status' => SubscriptionStatus::Active,
        ]);

        $this->service = Service::factory()->active()->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => 'dedicated',
            'subscription_id' => $this->subscription->getKey(),
        ]);

        $datacenter = Datacenter::factory()->create();

        $this->server = DedicatedServer::factory()->inDatacenter($datacenter)->create([
            'customer_id' => $this->customer->getKey(),
            'service_id' => $this->service->getKey(),
            'status' => DedicatedServerStatus::Active,
            'power_state' => PowerState::On,
            'serial' => 'SN-LIFE-0001',
        ]);

        BmcEndpoint::factory()->forServer($this->server)->create([
            'credentials_reference' => 'bmc-secret-key-name',
        ]);
    }

    #[Test]
    public function a_delivered_machine_is_rebuilt_lapses_and_goes_back_on_the_shelf(): void
    {
        // ---------------------------------------------------------------
        // The customer asks for a rebuild
        // ---------------------------------------------------------------
        $profile = OsInstallProfile::query()->first() ?? OsInstallProfile::factory()->create();

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'dedicated-life-reinstall-1')
            ->postJson('/api/v1/dedicated/'.$this->server->getKey().'/reinstall', [
                'confirm_serial' => $this->server->serial,
                'os_install_profile_id' => (string) $profile->getKey(),
            ])
            ->assertStatus(202);

        // The operation exists before any worker touches it, which is what a
        // customer who has just typed their serial needs to see.
        $operation = DedicatedReinstall::query()->sole();

        $this->assertSame((string) $this->server->getKey(), (string) $operation->dedicated_server_id);
        $this->assertNull(
            $operation->destructive_started_at,
            'The request alone armed an installer; nothing should be destructive until a worker runs.',
        );

        $this->assertSame(
            ProvisioningJobKind::ReinstallDedicated,
            ProvisioningJob::query()->findOrFail($operation->provisioning_job_id)->kind,
        );

        // ---------------------------------------------------------------
        // The subscription lapses
        // ---------------------------------------------------------------
        app(TransitionSubscription::class)->execute($this->subscription, SubscriptionStatus::Suspended);

        $this->assertSame(ServiceStatus::Suspended, $this->service->refresh()->status);

        // ---------------------------------------------------------------
        // Ended, but not resold
        // ---------------------------------------------------------------
        $this->travel(31)->days();

        $operator = $this->operator();

        $this->actingAs($operator)
            ->deleteJson('/api/admin/services/'.$this->service->getKey(), [
                'reason' => 'Cancelled and unpaid since March, ticket 8891.',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.dedicated_server_status', DedicatedServerStatus::Maintenance->value);

        $server = $this->server->refresh();

        $this->assertSame(ServiceStatus::Terminated, $this->service->refresh()->status);

        // Off the customer, and specifically not back in stock: the disks
        // still hold their data.
        $this->assertNull($server->customer_id);
        $this->assertNull($server->service_id);
        $this->assertSame(DedicatedServerStatus::Maintenance, $server->status);

        $this->assertSame(
            0,
            DedicatedServer::query()->where('status', DedicatedServerStatus::Available)->count(),
            'A machine went back into stock with the last customer’s data on it.',
        );

        // ---------------------------------------------------------------
        // A person erases the disks and says so
        // ---------------------------------------------------------------
        $this->actingAs($operator)
            ->postJson('/api/admin/dedicated/'.$server->getKey().'/return-to-stock', [
                'evidence' => 'Disks wiped with a three-pass erase on 8 September, verified from the console.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', DedicatedServerStatus::Available->value);

        $this->assertSame(DedicatedServerStatus::Available, $this->server->refresh()->status);

        $entry = AuditEntry::query()
            ->where('action', AuditAction::DedicatedServerReturnedToStock)
            ->sole();

        $this->assertStringContainsString('three-pass', (string) ($entry->context['evidence'] ?? ''));
        $this->assertTrue($entry->action->isAnAssertionAboutTheWorld());
    }

    #[Test]
    public function a_machine_still_assigned_cannot_be_put_back_on_the_shelf(): void
    {
        /*
         * The refusal that protects the next customer. A server with somebody
         * on it has not been decommissioned, whatever its status column says,
         * and returning it to stock would offer a running customer's machine
         * for sale.
         */
        $this->server->forceFill(['status' => DedicatedServerStatus::Maintenance])->save();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/dedicated/'.$this->server->getKey().'/return-to-stock', [
                'evidence' => 'Looks free to me.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dedicated.still_assigned');

        $this->assertSame(DedicatedServerStatus::Maintenance, $this->server->refresh()->status);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }
}
