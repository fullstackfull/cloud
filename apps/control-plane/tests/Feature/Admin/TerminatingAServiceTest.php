<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The retention window, which is the only thing standing between a late
 * invoice and a destroyed dataset.
 *
 * Termination is the one act on the operator surface that cannot be undone by
 * anybody, so the refusals are the subject of this file and the happy path is
 * one test. Shared hosting has had this shape since Phase 29; a VPS had no
 * termination at all, which meant a cancelled customer's machine, address and
 * share of a node were held for ever.
 */
final class TerminatingAServiceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Queue::fake([RunProvisioningJob::class]);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $this->service = Service::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => 'vps',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => now()->subDays(45),
        ]);

        $node = ComputeNode::factory()->create();

        VirtualMachine::factory()->onNode($node)->forService($this->service)->create([
            'hostname' => 'web-kw-09',
        ]);
    }

    #[Test]
    public function a_service_past_its_retention_window_is_queued_for_destruction(): void
    {
        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$this->service->id, [
                'reason' => 'Cancelled in March, unpaid since, ticket 8891.',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        $job = ProvisioningJob::query()->sole();

        $this->assertSame(ProvisioningJobKind::DestroyVps, $job->kind);
        $this->assertSame((string) $this->service->getKey(), $job->service_id);

        Queue::assertPushed(RunProvisioningJob::class);

        // The service is still suspended: it reaches `terminated` when the
        // worker has actually destroyed the machine, not when somebody asked.
        $this->assertSame(ServiceStatus::Suspended, $this->service->refresh()->status);

        $entry = AuditEntry::query()->where('action', AuditAction::ServiceTerminated)->sole();
        $this->assertStringContainsString('8891', (string) ($entry->context['reason'] ?? ''));
        $this->assertFalse($entry->context['forced'] ?? true);
    }

    #[Test]
    public function a_service_still_inside_its_retention_window_is_refused(): void
    {
        /*
         * The case the window exists for. Most suspensions are billing
         * disputes that end with the customer paying, and a termination that
         * ran the day after a suspension would turn a late invoice into a lost
         * customer and a destroyed dataset.
         */
        $this->service->forceFill(['suspended_at' => now()->subDay()])->save();

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$this->service->id, ['reason' => 'Tidying up.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.retention_window_open');

        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function an_active_service_is_never_terminated_directly(): void
    {
        // The path to termination runs through suspension, so that a customer
        // who pays late gets their machine back rather than a condolence
        // message.
        $this->service->forceFill(['status' => ServiceStatus::Active])->save();

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$this->service->id, ['reason' => 'Customer asked.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.termination_before_suspension');

        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function skipping_the_window_is_possible_and_recorded_as_such(): void
    {
        // An abuse case, or a customer asking for their data to be deleted
        // today. The override travels with the request so that it is recorded
        // rather than inferred from the timestamps afterwards.
        $this->service->forceFill(['suspended_at' => now()->subDay()])->save();

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$this->service->id, [
                'reason' => 'Customer exercised their right to erasure, ticket 9004.',
                'force' => true,
            ])
            ->assertStatus(202);

        $entry = AuditEntry::query()->where('action', AuditAction::ServiceTerminated)->sole();

        $this->assertTrue($entry->context['forced'] ?? false);
    }

    #[Test]
    public function terminating_requires_a_reason(): void
    {
        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$this->service->id, [])
            ->assertStatus(422);

        $this->assertSame(0, AuditEntry::query()->count());
        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    #[Test]
    public function an_operator_without_the_permission_cannot_end_anything(): void
    {
        // NOC can retry work and record verdicts; ending a customer's service
        // is a different authority.
        $this->actingAs($this->operator(Role::Noc))
            ->deleteJson('/api/admin/services/'.$this->service->id, ['reason' => 'Looks unused.'])
            ->assertStatus(403);

        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
