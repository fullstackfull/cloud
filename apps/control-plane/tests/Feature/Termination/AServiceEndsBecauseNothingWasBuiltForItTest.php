<?php

declare(strict_types=1);

namespace Tests\Feature\Termination;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\Provisioning\Application\Actions\EndExpiredServices;
use Lynomia\Modules\Provisioning\Application\Actions\RetryProvisioningJob;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\RetryRefusedException;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F-19: a service whose build left nothing behind can be ended, and one whose
 * build may have left something cannot be ended by pretending it did not.
 *
 * An order ends when what it bought ends, and a purchase nobody could build
 * had no way to end at all: `TerminateVpsService` refused a service with no
 * machine row, `DecommissionDedicatedServer` one with no server row, and
 * EndOfService threw on a hosting service with no account row — so the sweep
 * logged the same failure every night for ever, and the plan unit and coupon
 * hold such an order carried were never given back.
 *
 * The absent row is not the evidence, though. The one failure the engine
 * refuses to retry AUTOMATICALLY is a timeout, because the resource may exist
 * at the provider with no row here; reading the missing row as "never built"
 * would let an operator close that very case with one click. So the evidence
 * is the service's build history, read by EvidenceOfABuild.
 */
final class AServiceEndsBecauseNothingWasBuiltForItTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Queue::fake([RunProvisioningJob::class]);
    }

    /**
     * @return iterable<string, array{string, ProvisioningJobKind}>
     */
    public static function kinds(): iterable
    {
        yield 'vps' => ['vps', ProvisioningJobKind::CreateVps];
        yield 'dedicated' => ['dedicated', ProvisioningJobKind::ProvisionDedicated];
        yield 'shared hosting' => ['shared_hosting', ProvisioningJobKind::CreateHostingAccount];
    }

    #[Test]
    #[DataProvider('kinds')]
    public function a_build_refused_before_anything_existed_can_be_ended(string $kind, ProvisioningJobKind $build): void
    {
        [$service, $order] = $this->failedPurchase($kind, OrderStatus::ProvisioningFailed);

        ProvisioningJob::factory()->kind($build)->failedWith(FailureClass::Permanent)->create([
            'service_id' => $service->getKey(),
            'order_id' => $order->getKey(),
            'attempts' => 1,
        ]);

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$service->getKey(), ['reason' => 'Build refused; ticket 4410.'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.provisioning_job_id', null);

        $this->assertSame(ServiceStatus::Terminated, $service->fresh()?->status);

        // Nothing was asked of any provider: there was nothing to destroy.
        $this->assertSame(1, ProvisioningJob::query()->where('service_id', $service->getKey())->count());
        Queue::assertNotPushed(RunProvisioningJob::class);

        // And the purchase ends with it, which is what gives its unit back.
        $this->assertSame(OrderStatus::Terminated, $order->fresh()?->status);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function buildsThatMayHaveLeftSomething(): iterable
    {
        /*
         * The case the engine refuses to retry automatically, and the one an
         * operator's retry would nevertheless accept: a timeout with no
         * provider task recorded. Nothing here says it was built; nothing says
         * it was not.
         */
        yield 'timed out, no provider task recorded' => [
            ['status' => ProvisioningJobStatus::NeedsReview, 'failure_class' => FailureClass::Timeout, 'remote_job_id' => null, 'attempts' => 1],
            'timed out',
        ];

        yield 'the provider accepted a task' => [
            ['status' => ProvisioningJobStatus::Failed, 'failure_class' => FailureClass::Permanent, 'remote_job_id' => 'UPID:node1:0001', 'attempts' => 1],
            'UPID:node1:0001',
        ];

        yield 'the provider reported a resource' => [
            ['status' => ProvisioningJobStatus::Failed, 'failure_class' => FailureClass::Permanent, 'result' => ['provider_reference' => 'vm-4471'], 'attempts' => 1],
            'vm-4471',
        ];

        yield 'still queued' => [
            ['status' => ProvisioningJobStatus::Queued, 'attempts' => 0],
            'has not finished',
        ];

        yield 'still running' => [
            ['status' => ProvisioningJobStatus::Running, 'attempts' => 1, 'started_at' => CarbonImmutable::now()],
            'has not finished',
        ];
    }

    /**
     * @param  array<string, mixed>  $job
     */
    #[Test]
    #[DataProvider('buildsThatMayHaveLeftSomething')]
    public function a_build_that_may_have_left_something_is_not_read_as_nothing_built(array $job, string $named): void
    {
        [$service, $order] = $this->failedPurchase('vps');

        ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateVps)->create(array_merge([
            'service_id' => $service->getKey(),
            'order_id' => $order->getKey(),
        ], $job));

        $response = $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$service->getKey(), [
                'reason' => 'Looks like nothing was built.',
                // Not even the override: the window is not the question.
                'force' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'provisioning.build_may_exist');

        $this->assertStringContainsString($named, (string) $response->json('error.message'));
        $this->assertSame(ServiceStatus::Failed, $service->fresh()?->status);
        $this->assertSame(OrderStatus::ManualReview, $order->fresh()?->status);
    }

    #[Test]
    public function the_operators_retry_is_not_the_evidence_because_it_never_reads_the_failure_class(): void
    {
        /*
         * Pinned because two docblocks rest on it. RetryProvisioningJob refuses
         * a job only on a recorded provider task or resource; it does not read
         * FailureClass at all. So a timed-out build with nothing recorded is
         * requeued by the operator's button — the refusal of a timeout lives in
         * FailureClass::isAutomaticallyRetryable(), whose production callers
         * are in RunProvisioningJob. EvidenceOfABuild therefore cannot borrow
         * "the retry would refuse it" as its reason, and does not.
         */
        [$service, $order] = $this->failedPurchase('vps');

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateVps)->create([
            'service_id' => $service->getKey(),
            'order_id' => $order->getKey(),
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
            'remote_job_id' => null,
            'attempts' => 1,
        ]);

        $this->assertFalse(FailureClass::Timeout->isAutomaticallyRetryable());

        $requeued = app(RetryProvisioningJob::class)->execute($job);

        $this->assertSame(ProvisioningJobStatus::Queued, $requeued->status);
    }

    #[Test]
    public function a_build_for_a_service_that_has_ended_cannot_be_run_again(): void
    {
        /*
         * The door ending an unbuilt service opens: its failed build job is
         * still on the review screen with a retry button beside it. Pressing
         * it would build a machine for a purchase that is over.
         */
        [$service, $order] = $this->failedPurchase('vps', OrderStatus::ProvisioningFailed);

        $build = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateVps)->failedWith(FailureClass::Permanent)->create([
            'service_id' => $service->getKey(),
            'order_id' => $order->getKey(),
            'attempts' => 1,
        ]);

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$service->getKey(), ['reason' => 'Build refused; ticket 4411.'])
            ->assertStatus(202);

        try {
            app(RetryProvisioningJob::class)->execute($build->fresh() ?? $build);
            $this->fail('A build was requeued for a service that has ended.');
        } catch (RetryRefusedException $e) {
            $this->assertSame('provisioning.retry_after_the_service_ended', $e->errorCode());
        }

        $this->assertSame(ProvisioningJobStatus::Failed, $build->fresh()?->status);
        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function the_sweep_ends_a_hosting_service_that_never_got_an_account(): void
    {
        /*
         * The nightly failure that could not be found: EndOfService threw on a
         * hosting service with no account row, the sweep logged `failed=1`,
         * and the operator's repair query joins `hosting_accounts`, so the
         * stuck service was invisible to the very query written to find it.
         */
        $service = Service::factory()->create([
            'kind' => 'shared_hosting',
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now()->subDays(40),
            'retention_ends_at' => CarbonImmutable::now()->subDay(),
            'ended_reason' => BeginRetentionWindow::BY_CUSTOMER,
        ]);

        ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->failedWith(FailureClass::Permanent)->create([
            'service_id' => $service->getKey(),
            'attempts' => 1,
        ]);

        $outcome = app(EndExpiredServices::class)->execute();

        $this->assertSame(['ended' => 1, 'warned' => 0, 'failed' => 0], $outcome);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()?->status);
    }

    #[Test]
    public function a_service_that_still_has_its_machine_is_not_ended_as_if_nothing_was_built(): void
    {
        // The ordinary path is untouched: a machine row means the machine is
        // destroyed by a worker, and the window still applies.
        $service = Service::factory()->create([
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        VirtualMachine::factory()
            ->onNode(ComputeNode::factory()->create())
            ->forService($service)
            ->create();

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$service->getKey(), ['reason' => 'Customer asked.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.termination_before_suspension');

        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);
    }

    /**
     * A paid purchase whose build failed: the service `failed`, the order
     * saying so, and no resource row of any kind.
     *
     * @return array{Service, Order}
     */
    private function failedPurchase(string $kind, OrderStatus $orderStatus = OrderStatus::ManualReview): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $order = Order::factory()->for($customer)->create([
            'status' => $orderStatus,
            'paid_at' => now(),
        ]);

        $service = Service::factory()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $order->getKey(),
            'kind' => $kind,
            'status' => ServiceStatus::Failed,
        ]);

        return [$service, $order];
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
