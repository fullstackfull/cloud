<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Domain\Enums\CustomerStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens when the trail cannot be written.
 *
 * The platform draws a line through the middle of its administrative surface,
 * and this file asserts it from both sides.
 *
 * An act that happens entirely inside Postgres — suspending an account,
 * adopting an orphan, requeuing a job, settling a rebuild — shares a
 * transaction with its audit row. If the row cannot be written the act does
 * not happen, because "it happened and nothing recorded who did it" is a hole
 * with nothing on the other side of the trade.
 *
 * An act that has already reached a provider — a refund at a payment gateway,
 * a terminated hosting account — cannot make that trade and does not try. The
 * record is written afterwards and a failure is loud: the operator gets an
 * error rather than a success and knows to go and look. Worse than atomic,
 * better than silent, which is the whole of the argument.
 *
 * The audit table is really taken away rather than mocked. A test that stubbed
 * the recorder would prove that the stub throws.
 */
final class AnActMustNotOutliveItsRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_suspension_that_cannot_be_recorded_does_not_happen(): void
    {
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);

        $this->breakTheAuditTable();

        $this->actingAs($this->operator())
            ->putJson('/api/admin/customers/'.$customer->id.'/status', [
                'status' => 'suspended',
                'reason' => 'Chargeback fraud, ticket 4412.',
            ])
            ->assertStatus(500);

        $this->restoreTheAuditTable();

        // The account is exactly as it was. An operator who saw the error
        // presses the button again; an account suspended with no record of who
        // did it is one nobody can explain to the customer.
        $this->assertSame(CustomerStatus::Active, $customer->refresh()->status);
    }

    #[Test]
    public function an_adoption_that_cannot_be_recorded_does_not_happen(): void
    {
        /*
         * The most consequential of the atomic acts: adoption is the platform
         * changing what it believes about a machine on a person's word, and it
         * is precisely the row an investigator goes looking for afterwards.
         */
        $job = ProvisioningJob::factory()->create(['status' => ProvisioningJobStatus::NeedsReview]);

        $this->breakTheAuditTable();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/adopt', [
                'provider_reference' => '4412',
                'evidence' => 'VM 4412 on pve-kw-03 carries this order\'s hostname.',
            ])
            ->assertStatus(500);

        $this->restoreTheAuditTable();

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $this->assertNull($job->result['provider_reference'] ?? null);
    }

    #[Test]
    public function the_act_and_its_record_land_together_when_both_succeed(): void
    {
        // The other half: atomicity must not have cost the trail its contents.
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);

        $this->actingAs($this->operator())
            ->putJson('/api/admin/customers/'.$customer->id.'/status', [
                'status' => 'suspended',
                'reason' => 'Chargeback fraud, ticket 4412.',
            ])
            ->assertOk();

        $entry = AuditEntry::query()->sole();

        $this->assertSame(AuditAction::CustomerSuspended, $entry->action);
        $this->assertSame((string) $customer->getKey(), $entry->customer_id);
        $this->assertStringContainsString('4412', (string) ($entry->context['reason'] ?? ''));
        $this->assertNotNull($entry->actor_label);

        $this->assertSame(CustomerStatus::Suspended, $customer->refresh()->status);
    }

    /**
     * Takes the table away from under the recorder.
     *
     * Postgres is transactional for DDL, so RefreshDatabase's rollback undoes
     * this even where a test fails halfway — but it is put back explicitly
     * anyway, because the assertions after the failure read the database.
     */
    private function breakTheAuditTable(): void
    {
        Schema::rename('audit_log', 'audit_log_hidden');
    }

    private function restoreTheAuditTable(): void
    {
        Schema::rename('audit_log_hidden', 'audit_log');
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }
}
