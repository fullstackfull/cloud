<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Notifications\Application\Listeners\NotifyOnProvisioningOutcome;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * A paid plan-change resize the node can no longer hold stops in review with
 * the money held, and the customer is told the change is waiting (a residue
 * the round-six verifiers recorded).
 *
 * The room can go between the settlement of the proration invoice and the
 * resize (the settlement returns a change it already cannot deliver). The
 * resize's capacity refusal is retried and then stops in review, never
 * failed, with the money held for an operator to grow the machine or return
 * it (ResizeVpsHandler). The customer used to be sent the build's message -
 * "setting up the service did not finish cleanly" - which says nothing about
 * a plan change or the money behind it. They are now told the plan change is
 * waiting for the team and that what they paid for it is held.
 */
final class APaidResizeThatCannotFitTellsTheCustomerTheChangeIsWaitingTest extends TestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();
        $this->hypervisor->loseTheAnswerToCreates = false;
    }

    #[Test]
    public function a_resize_refused_for_capacity_to_the_end_tells_the_customer_the_change_is_waiting(): void
    {
        $build = $this->createJob();
        $this->runWorker($build);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $build->refresh()->status, (string) $build->last_error);
        $machine = VirtualMachine::query()->sole();

        $resize = ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::Resize,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Queued,
            'payload' => ['virtual_machine_id' => (string) $machine->id, 'vcpu' => 2, 'memory_mib' => 125_000, 'disk_gib' => 40],
        ]);

        Event::fake([ProvisioningJobNeedsReview::class]);

        for ($attempt = 0; $attempt < 10 && ! in_array($resize->refresh()->status, [ProvisioningJobStatus::NeedsReview, ProvisioningJobStatus::Failed, ProvisioningJobStatus::Succeeded], true); $attempt++) {
            DB::table('provisioning_jobs')->where('id', $resize->id)->update(['next_attempt_at' => null]);
            $this->runWorker($resize);
        }

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $resize->refresh()->status);
        $this->assertSame(FailureClass::Capacity, $resize->failure_class);

        $raised = Event::dispatched(ProvisioningJobNeedsReview::class)->map(static fn (array $call) => $call[0])->all();
        $this->assertCount(1, $raised);

        app(NotifyOnProvisioningOutcome::class)->needsReview($raised[0]);

        /** @var Notification $told */
        $told = Notification::query()->where('customer_id', $this->customer->id)->sole();
        $this->assertSame('service.plan_change_needs_review', $told->type->value, 'The customer was not told their paid plan change is waiting.');
        $this->assertStringContainsString('held', (string) trans('notifications.'.$told->type->value.'.body', ['service' => 'x'], 'en'));
    }

    #[Test]
    public function a_package_change_in_review_is_told_the_same_and_a_build_in_review_is_told_the_builds_message(): void
    {
        $service = Service::factory()->create(['customer_id' => $this->customer->id, 'kind' => 'shared_hosting', 'label' => 'site-one']);

        $listener = app(NotifyOnProvisioningOutcome::class);
        $listener->needsReview(new ProvisioningJobNeedsReview('job-package', ProvisioningJobKind::ChangeHostingPackage, (string) $service->id, null, FailureClass::Timeout, 'timed out'));
        $listener->needsReview(new ProvisioningJobNeedsReview('job-build', ProvisioningJobKind::CreateHostingAccount, (string) $service->id, null, FailureClass::Timeout, 'timed out'));

        $types = Notification::query()->where('customer_id', $this->customer->id)->orderBy('created_at')->orderBy('id')->get()->map(static fn (Notification $n): string => $n->type->value)->sort()->values()->all();
        $this->assertSame(['service.needs_review', 'service.plan_change_needs_review'], $types);
    }
}
