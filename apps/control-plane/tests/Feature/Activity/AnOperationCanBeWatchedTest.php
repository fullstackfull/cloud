<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\Enums\CustomerOperationState;
use Lynomia\Modules\Shared\Domain\Enums\RetryAdvice;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A 202 that can be followed up.
 *
 * AR-12. Every asynchronous action returned an accepted job and there was no
 * endpoint that turned one back into a state, so "nothing happened" was the
 * customer's experience of every power action, rebuild and build. The portal
 * could not have polled if it wanted to.
 *
 * The contract asserted here is the one the client depends on to behave: a
 * bounded state vocabulary, retry advice the server decides, and a poll hint
 * that goes null the moment there is nothing left to wait for — which is what
 * makes "polling stops on terminal states" a property of the API rather than a
 * promise about a component.
 */
final class AnOperationCanBeWatchedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Customer, 1: User}
     */
    private function account(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }

    private function machineFor(Customer $customer): VirtualMachine
    {
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        $cluster = ComputeCluster::factory()->create();
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->id]);

        return VirtualMachine::factory()->onNode($node)->forService($service)->create();
    }

    private function jobFor(Customer $customer, VirtualMachine $machine, ProvisioningJobStatus $status): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => $status,
            'payload' => ['power_action' => 'restart'],
        ]);
    }

    #[Test]
    public function work_still_running_says_so_and_says_when_to_look_again(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer);
        $job = $this->jobFor($customer, $machine, ProvisioningJobStatus::Running);

        $response = $this->actingAs($user)->getJson('/api/v1/operations/'.$job->id);

        $response->assertOk();

        $data = $response->json('data');

        self::assertSame(CustomerOperationState::Processing->value, $data['state']);
        self::assertFalse($data['is_terminal']);

        // Nothing is wrong; the answer is simply not in yet.
        self::assertSame(RetryAdvice::Wait->value, $data['retry_advice']);

        // And the server says when to ask again, so the client does not invent
        // an interval.
        self::assertIsInt($data['poll_after_ms']);
        self::assertGreaterThan(0, $data['poll_after_ms']);

        // The customer's own verb, not the job kind: `restart`, not `stop`.
        self::assertSame('restart', $data['action']);
    }

    #[Test]
    public function finished_work_tells_the_client_to_stop_asking(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer);
        $job = $this->jobFor($customer, $machine, ProvisioningJobStatus::Succeeded);

        $data = $this->actingAs($user)->getJson('/api/v1/operations/'.$job->id)->json('data');

        self::assertSame(CustomerOperationState::Succeeded->value, $data['state']);
        self::assertTrue($data['is_terminal']);

        /*
         * The server half of "polling stops on terminal states". A client that
         * only ever schedules its next read from this field cannot keep asking
         * about an operation that finished, however the screen was written.
         */
        self::assertNull($data['poll_after_ms']);
        self::assertSame(RetryAdvice::NotRetryable->value, $data['retry_advice']);
    }

    #[Test]
    public function work_waiting_on_a_person_is_not_reported_as_a_failure(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer);
        $job = $this->jobFor($customer, $machine, ProvisioningJobStatus::NeedsReview);

        $data = $this->actingAs($user)->getJson('/api/v1/operations/'.$job->id)->json('data');

        // Not `failed`. Telling a customer it failed invites them to do it
        // again, on a machine that may be half-built.
        self::assertSame(CustomerOperationState::NeedsReview->value, $data['state']);
        self::assertTrue($data['is_terminal']);
        self::assertTrue($data['needs_attention']);
        self::assertNull($data['poll_after_ms']);

        // Support, not a retry button.
        self::assertSame(RetryAdvice::SupportRequired->value, $data['retry_advice']);
    }

    #[Test]
    public function a_clean_failure_is_the_one_state_that_may_be_retried(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer);
        $job = $this->jobFor($customer, $machine, ProvisioningJobStatus::Failed);

        $data = $this->actingAs($user)->getJson('/api/v1/operations/'.$job->id)->json('data');

        self::assertSame(CustomerOperationState::Failed->value, $data['state']);

        // The platform knows it did not happen, so asking again is safe.
        self::assertSame(RetryAdvice::SafeToRetry->value, $data['retry_advice']);
    }

    #[Test]
    public function watching_an_operation_publishes_nothing_operational(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer);

        $job = ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::ReinstallVps,
            'status' => ProvisioningJobStatus::NeedsReview,
            'provider' => 'proxmox',
            'remote_job_id' => 'UPID:pve-node-07:0000BEEF',
            'last_error' => 'template clone failed on datastore ceph-02',
            'attempts' => 2,
            'max_attempts' => 3,
        ]);

        $body = (string) $this->actingAs($user)
            ->getJson('/api/v1/operations/'.$job->id)
            ->getContent();

        foreach ([
            'proxmox',
            'UPID',
            'pve-node-07',
            'ceph-02',
            'template clone failed',
            'datastore',
        ] as $operational) {
            self::assertStringNotContainsString(
                $operational,
                $body,
                sprintf('The operation contract published "%s".', $operational),
            );
        }

        /*
         * The attempt counters are absent too. "Attempt 2 of 3" is a promise
         * the platform has not made, and a customer counting down to it learns
         * nothing they can act on.
         */
        $data = json_decode($body, true);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('attempts', $data['data']);
        self::assertArrayNotHasKey('max_attempts', $data['data']);
    }

    #[Test]
    public function another_tenants_operation_is_not_found_rather_than_refused(): void
    {
        [, $mine] = $this->account();
        [$theirs] = $this->account();

        $theirMachine = $this->machineFor($theirs);
        $theirJob = $this->jobFor($theirs, $theirMachine, ProvisioningJobStatus::Running);

        // 404, not 403: a refusal would confirm the id names real work.
        $this->actingAs($mine)
            ->getJson('/api/v1/operations/'.$theirJob->id)
            ->assertNotFound();
    }

    #[Test]
    public function there_is_no_generic_retry_endpoint(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer);
        $job = $this->jobFor($customer, $machine, ProvisioningJobStatus::Failed);

        /*
         * Retrying is re-asking the product for the same thing, through that
         * product's own idempotency key and its own guards. A generic retry
         * would be a second path to every mutation in the platform, wired to
         * whatever the last status read said — which is exactly how an
         * indeterminate operation gets asked for twice.
         */
        $this->actingAs($user)
            ->postJson('/api/v1/operations/'.$job->id.'/retry')
            ->assertStatus(404);
    }

    #[Test]
    public function the_unread_count_is_its_own_small_read(): void
    {
        [$customer, $user] = $this->account();

        Notification::factory()
            ->count(3)
            ->create(['customer_id' => $customer->id, 'read_at' => null]);

        Notification::factory()
            ->create(['customer_id' => $customer->id, 'read_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/v1/notifications/unread-count');

        $response->assertOk();
        self::assertSame(3, $response->json('data.unread'));

        // And it does not carry the inbox with it.
        $data = $response->json('data');
        self::assertSame(['unread'], array_keys($data));
    }

    #[Test]
    public function marking_one_read_moves_the_count(): void
    {
        [$customer, $user] = $this->account();

        $notifications = Notification::factory()
            ->count(2)
            ->create(['customer_id' => $customer->id, 'read_at' => null]);

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/'.$notifications[0]->id.'/read')
            ->assertOk();

        self::assertSame(
            1,
            $this->actingAs($user)->getJson('/api/v1/notifications/unread-count')->json('data.unread'),
        );

        $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertOk();

        self::assertSame(
            0,
            $this->actingAs($user)->getJson('/api/v1/notifications/unread-count')->json('data.unread'),
        );
    }

    #[Test]
    public function support_context_pointed_at_another_tenants_service_is_refused(): void
    {
        [$customer, $user] = $this->account();
        [$theirs] = $this->account();

        $mine = $this->machineFor($customer);
        $theirMachine = $this->machineFor($theirs);

        // My own service: accepted, and the ticket carries it.
        $accepted = $this->actingAs($user)->postJson('/api/v1/support/tickets', [
            'subject' => 'The rebuild did not finish',
            'category' => 'technical',
            'priority' => 'normal',
            'body' => 'Operation stopped and says it needs review.',
            'service_id' => (string) $mine->service_id,
        ]);

        $accepted->assertCreated();

        /*
         * Somebody else's: refused. Attaching a ticket to another tenant's
         * machine is not access to that machine, but it does confirm the id
         * names a real one — so the server checks ownership rather than shape.
         */
        $this->actingAs($user)->postJson('/api/v1/support/tickets', [
            'subject' => 'Asking about a machine that is not mine',
            'category' => 'technical',
            'priority' => 'normal',
            'body' => 'Tampering with the service id.',
            'service_id' => (string) $theirMachine->service_id,
        ])->assertNotFound();
    }
}
