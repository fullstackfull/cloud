<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Compute\Application\Jobs\ReconcileCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The operator capabilities that had no execution path, and the trail they
 * now leave.
 *
 * VoidInvoice and the drift review workflow were both written, both tested at
 * the action level and both unreachable: there was no route, no command and no
 * listener that called either. An operator faced with a duplicate invoice or a
 * page of drift had nothing to press.
 *
 * The audit trail is asserted alongside each one rather than in a test of its
 * own, because the point of the table is that these specific acts cannot
 * happen without it.
 */
final class OperatorInterventionsAreRecordedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function an_operator_can_void_an_invoice_that_should_never_have_been_issued(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $invoice = Invoice::factory()
            ->open()
            ->totalling(Money::ofMinor(12_000, 'KWD'))
            ->create(['customer_id' => $customer->id, 'currency' => 'KWD']);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/invoices/'.$invoice->id.'/void', [
                'reason' => 'Duplicate of INV-2026-0104; the customer was billed twice for one order.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InvoiceStatus::Void->value);

        $entry = AuditEntry::query()->sole();

        $this->assertSame(AuditAction::InvoiceVoided, $entry->action);
        $this->assertSame((string) $invoice->getKey(), $entry->subject_id);
        $this->assertSame((string) $customer->getKey(), $entry->customer_id);
        $this->assertStringContainsString('Duplicate of INV-2026-0104', (string) ($entry->context['reason'] ?? ''));

        // The person, as they were at the time, not a foreign key that reads
        // differently after they leave.
        $this->assertNotNull($entry->actor_label);
    }

    #[Test]
    public function voiding_requires_a_reason(): void
    {
        $invoice = Invoice::factory()->open()->create();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/invoices/'.$invoice->id.'/void', [])
            ->assertStatus(422);

        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function an_operator_can_record_a_verdict_on_drift(): void
    {
        $drift = ResourceDrift::factory()->create(['status' => DriftStatus::Open]);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson('/api/admin/drift/'.$drift->id.'/review', [
                'verdict' => 'resolved',
                'resolution' => 'The machine was migrated by hand during the maintenance window and is now recorded.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', DriftStatus::Resolved->value);

        $reviewed = $drift->refresh();

        $this->assertSame(DriftStatus::Resolved, $reviewed->status);
        $this->assertSame((string) $operator->getKey(), $reviewed->resolved_by_user_id);
        $this->assertNotNull($reviewed->resolved_at);

        $this->assertSame(AuditAction::DriftResolved, AuditEntry::query()->sole()->action);
    }

    #[Test]
    public function calling_something_resolved_requires_saying_why(): void
    {
        /*
         * Acknowledging needs no explanation — the row is still true and a
         * person has seen it. Resolving is a claim about the world, and the
         * next person to read it has to be able to evaluate the claim.
         */
        $drift = ResourceDrift::factory()->create(['status' => DriftStatus::Open]);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/drift/'.$drift->id.'/review', ['verdict' => 'resolved'])
            ->assertStatus(422);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/drift/'.$drift->id.'/review', ['verdict' => 'acknowledged'])
            ->assertOk();

        $this->assertSame(DriftStatus::Acknowledged, $drift->refresh()->status);
    }

    #[Test]
    public function a_second_operator_cannot_overwrite_the_first_ones_verdict(): void
    {
        // Two people work the same alert channel and click the same row.
        $drift = ResourceDrift::factory()->create(['status' => DriftStatus::Open]);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/drift/'.$drift->id.'/review', [
                'verdict' => 'resolved',
                'resolution' => 'Adopted into inventory.',
            ])
            ->assertOk();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/drift/'.$drift->id.'/review', [
                'verdict' => 'acknowledged',
            ])
            ->assertStatus(409);

        $this->assertSame(DriftStatus::Resolved, $drift->refresh()->status);
        $this->assertSame('Adopted into inventory.', $drift->resolution);
    }

    #[Test]
    public function asking_for_a_reconciliation_queues_the_same_job_the_scheduler_queues(): void
    {
        Queue::fake([ReconcileCluster::class]);

        $cluster = ComputeCluster::factory()->create(['status' => 'active']);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/clusters/'.$cluster->id.'/reconcile')
            ->assertStatus(202);

        // Not a faster, less careful path reserved for humans: the operator
        // triggers exactly what runs unattended every thirty minutes.
        Queue::assertPushed(ReconcileCluster::class);

        $this->assertSame(AuditAction::ReconciliationRequested, AuditEntry::query()->sole()->action);
    }

    #[Test]
    public function the_trail_cannot_be_rewritten_by_the_application_that_writes_it(): void
    {
        $entry = AuditEntry::factory()->create();

        try {
            $entry->update(['action' => AuditAction::BackupRestored]);
            $this->fail('An audit entry was amended.');
        } catch (RuntimeException) {
            $this->assertSame(AuditAction::DriftAcknowledged, $entry->fresh()?->action);
        }

        try {
            $entry->delete();
            $this->fail('An audit entry was deleted.');
        } catch (RuntimeException) {
            $this->assertSame(1, AuditEntry::query()->count());
        }
    }

    #[Test]
    public function the_trail_is_readable_by_somebody_with_the_permission_and_nobody_else(): void
    {
        AuditEntry::factory()->create();

        $this->actingAs($this->operator())
            ->getJson('/api/admin/audit')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $customer = User::factory()->create();
        $customer->syncRoles([Role::Customer->value]);

        $this->actingAs($customer)->getJson('/api/admin/audit')->assertForbidden();
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
