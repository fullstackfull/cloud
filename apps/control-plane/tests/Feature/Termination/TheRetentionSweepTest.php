<?php

declare(strict_types=1);

namespace Tests\Feature\Termination;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\Provisioning\Application\Actions\EndExpiredServices;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sweep that finishes what a cancellation started.
 *
 * The boundary these tests exist to hold is the one between "the customer
 * chose this date" and "the customer owes us money". Both look identical in
 * the database — suspended, window elapsed — and only one of them may be acted
 * on without a person.
 */
final class TheRetentionSweepTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    #[Test]
    public function a_cancelled_service_out_of_time_is_ended(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(1, $outcome['ended']);

        // Queued rather than done: the destruction is a provider call, and it
        // gets the same attempt accounting and timeout handling as every other
        // one.
        $this->assertTrue(
            ProvisioningJob::query()
                ->where('service_id', $service->getKey())
                ->where('kind', ProvisioningJobKind::DestroyVps->value)
                ->exists(),
        );
    }

    #[Test]
    public function a_service_suspended_for_non_payment_is_never_ended_by_the_sweep(): void
    {
        /*
         * The most important test in this file. A customer who has not paid is
         * somebody the business may still want back, and destroying their data
         * thirty days into a billing dispute is a decision that needs a
         * person's name on it. That person has the operator endpoint.
         */
        $service = $this->vps(BeginRetentionWindow::BY_NON_PAYMENT, CarbonImmutable::now()->subDay());

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(0, $outcome['ended']);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()?->status);
        $this->assertFalse(ProvisioningJob::query()->where('service_id', $service->getKey())->exists());
    }

    #[Test]
    public function a_service_still_inside_its_window_is_left_alone(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->addDays(10));

        $this->assertSame(0, app(EndExpiredServices::class)->execute()['ended']);
        $this->assertFalse(ProvisioningJob::query()->where('service_id', $service->getKey())->exists());
    }

    #[Test]
    public function the_customer_is_warned_before_anything_is_destroyed(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->addDays(2));

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(1, $outcome['warned']);
        $this->assertSame(0, $outcome['ended']);

        $notification = Notification::query()
            ->where('type', NotificationType::DataRetentionEnding->value)
            ->firstOrFail();

        // The date, so the sentence is actionable. "Your data will be
        // destroyed soon" is not a message anybody can act on.
        $this->assertSame(
            $service->retention_ends_at?->toDateString(),
            $notification->data['date'] ?? null,
        );

        // Once. A daily sweep that warned daily would be a daily sweep nobody
        // reads.
        app(EndExpiredServices::class)->execute();

        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::DataRetentionEnding->value)->count(),
        );
    }

    #[Test]
    public function a_warning_is_not_sent_after_the_window_has_already_closed(): void
    {
        // The ending itself is the message at that point, and a warning that
        // arrives with the deletion is worse than none.
        $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $this->assertSame(0, app(EndExpiredServices::class)->execute()['warned']);
    }

    #[Test]
    public function ending_a_service_is_audited_and_the_customer_is_told(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        app(EndExpiredServices::class)->execute();

        $entry = AuditEntry::query()->where('action', AuditAction::ServiceTerminated->value)->firstOrFail();

        // "Who terminated this service" must never answer "nobody knows".
        $this->assertSame('retention sweep', $entry->context['terminated_by'] ?? null);
        $this->assertSame(BeginRetentionWindow::BY_CUSTOMER, $entry->context['ended_reason'] ?? null);

        $this->assertTrue(
            Notification::query()
                ->where('type', NotificationType::ServiceTerminated->value)
                ->where('customer_id', $this->customer->getKey())
                ->exists(),
        );

        unset($service);
    }

    #[Test]
    public function a_physical_server_goes_to_maintenance_and_never_back_to_stock(): void
    {
        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'dedicated',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDay(),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ]);

        $server = DedicatedServer::factory()->create([
            'service_id' => $service->getKey(),
            'customer_id' => $this->customer->id,
            'status' => DedicatedServerStatus::Active,
        ]);

        $this->assertSame(1, app(EndExpiredServices::class)->execute()['ended']);

        /*
         * The one place this platform deliberately stops short of automating
         * something. The disks in that chassis hold the customer's data until
         * a person erases them, and no call the platform can make proves that
         * happened — so the machine is out of the sellable pool and stays
         * there until somebody says otherwise.
         */
        $this->assertSame(DedicatedServerStatus::Maintenance, $server->fresh()?->status);
        $this->assertNotSame(DedicatedServerStatus::Available, $server->fresh()?->status);
    }

    #[Test]
    public function one_service_that_cannot_be_ended_does_not_stop_the_others(): void
    {
        // A VPS service with no machine behind it: the termination action
        // refuses, and the sweep has to step over it rather than stopping.
        Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDays(2),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ]);

        $healthy = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(1, $outcome['failed']);
        $this->assertSame(1, $outcome['ended']);
        $this->assertTrue(ProvisioningJob::query()->where('service_id', $healthy->getKey())->exists());
    }

    #[Test]
    public function the_sweep_can_be_switched_off_without_switching_off_the_warnings(): void
    {
        config()->set('provisioning.termination.sweep_cancelled', false);

        $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());
        $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->addDays(2));

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(0, $outcome['ended']);

        // A deployment that has turned the automation off still owes its
        // customers the date.
        $this->assertSame(1, $outcome['warned']);
    }

    #[Test]
    public function a_service_already_on_its_way_out_is_not_ended_a_second_time(): void
    {
        $service = $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $this->assertSame(1, app(EndExpiredServices::class)->execute()['ended']);

        /*
         * Terminating is not instant: the VPS path queues a job a worker may
         * not reach for minutes, and the service stays suspended until it
         * does. A daily sweep that found it again would write a second audit
         * entry and tell the customer a second time that their data had been
         * destroyed.
         */
        $this->assertSame(0, app(EndExpiredServices::class)->execute()['ended']);

        $this->assertNotNull($service->refresh()->termination_requested_at);

        /*
         * Scoped to this service rather than counting every termination in the
         * database. The queue proofs run the same sweep in a separate process
         * against committed rows, and the audit entries that leaves behind are
         * outside any test's transaction — a global count here passes alone
         * and fails in the full suite, which is a test about the suite rather
         * than about the sweep.
         */
        $this->assertSame(
            1,
            AuditEntry::query()
                ->where('action', AuditAction::ServiceTerminated->value)
                ->where('subject_id', (string) $service->getKey())
                ->count(),
        );
    }

    #[Test]
    public function the_command_reports_what_it_did(): void
    {
        $this->vps(BeginRetentionWindow::BY_CUSTOMER, CarbonImmutable::now()->subDay());

        $this->artisan('services:end-expired')
            ->expectsOutputToContain('1 services ended')
            ->assertSuccessful();
    }

    private function vps(string $reason, CarbonImmutable $windowEnds): Service
    {
        $cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $node = ComputeNode::factory()->withCapacity(16, 32_768, 1_000)->create([
            'cluster_id' => $cluster->id,
            'status' => NodeStatus::Active,
        ]);

        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Suspended,
            // Old enough for the termination action's own check as well as the
            // window's: the two agree in production, and a fixture that only
            // satisfied one would be testing half the guard.
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => $windowEnds,
            'ended_reason' => $reason,
            'label' => 'web-kw-01',
        ]);

        VirtualMachine::factory()->onNode($node)->create([
            'service_id' => $service->getKey(),
        ]);

        return $service;
    }
}
