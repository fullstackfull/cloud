<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Lynomia\Modules\Provisioning\Domain\Enums\CustomerFailureReason;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerProvisioningEventState;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/services/{service}/events.
 *
 * The provisioning history, and the endpoint with the most to leak: every row
 * behind it carries a provider's response, a provider's job id and a failure
 * message written by an adapter this module does not control.
 */
final class ServiceEventsEndpointTest extends ServiceApiTestCase
{
    #[Test]
    public function it_returns_the_history_newest_first(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer, ['status' => ServiceStatus::Active]);

        $build = $this->jobFor($service, [
            'kind' => ProvisioningJobKind::CreateVps,
            'status' => ProvisioningJobStatus::Succeeded,
            'created_at' => now()->subHours(2),
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2)->addMinutes(4),
        ]);

        $reboot = $this->jobFor($service, [
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Running,
            'created_at' => now()->subMinutes(2),
            'started_at' => now()->subMinutes(2),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}/events")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $reboot->id)
            ->assertJsonPath('data.0.kind', 'restart')
            ->assertJsonPath('data.0.state', CustomerProvisioningEventState::InProgress->value)
            ->assertJsonPath('data.0.is_settled', false)
            ->assertJsonPath('data.0.failure_reason', null)
            ->assertJsonPath('data.1.id', $build->id)
            ->assertJsonPath('data.1.kind', 'create_vps')
            ->assertJsonPath('data.1.state', CustomerProvisioningEventState::Completed->value)
            ->assertJsonPath('data.1.is_settled', true)
            ->assertJsonPath('meta.service_id', $service->id)
            ->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function the_history_of_another_customers_service_is_a_404(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $hers = $this->serviceFor($theirs, ['status' => ServiceStatus::Active]);
        $this->jobFor($hers, ['status' => ProvisioningJobStatus::Succeeded]);

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$hers->id}/events")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function another_customers_jobs_are_never_mixed_into_a_history(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $ours = $this->serviceFor($mine);
        $hers = $this->serviceFor($theirs);

        $ourJob = $this->jobFor($ours, ['status' => ProvisioningJobStatus::Succeeded]);
        $herJob = $this->jobFor($hers, ['status' => ProvisioningJobStatus::Succeeded]);

        $ids = array_column(
            (array) $this->actingAs($user)
                ->getJson("/api/v1/services/{$ours->id}/events")
                ->assertOk()
                ->json('data'),
            'id'
        );

        $this->assertSame([$ourJob->id], $ids);
        $this->assertNotContains($herJob->id, $ids);
    }

    #[Test]
    public function a_service_nothing_has_happened_to_yet_has_an_empty_history(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer);

        // Not a 404: the service exists, and telling a client otherwise would
        // make it think the service was gone.
        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}/events")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function a_page_of_history_cannot_be_larger_than_the_ceiling(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer);

        foreach (range(1, 6) as $ignored) {
            $this->jobFor($service, ['status' => ProvisioningJobStatus::Succeeded]);
        }

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}/events?per_page=100000")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.max_per_page', 100)
            ->assertJsonCount(6, 'data');

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}/events?per_page=2")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_page_number_that_is_not_a_page_number_is_a_422(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $service = $this->serviceFor($customer);

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}/events?page=0")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['page']]]]);
    }

    #[Test]
    public function a_timeout_is_reported_as_unconfirmed_rather_than_as_a_failure(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        [$service, $job] = $this->serviceWaitingOnAPerson($customer);

        $this->assertSame(FailureClass::Timeout, $job->refresh()->failure_class);

        // The platform stopped waiting; the machine may well exist. Calling
        // that a failure is how a support ticket is wrong from its first line.
        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}/events")
            ->assertOk()
            ->assertJsonPath('data.0.state', CustomerProvisioningEventState::UnderReview->value)
            ->assertJsonPath('data.0.failure_reason', CustomerFailureReason::AwaitingConfirmation->value)
            ->assertJsonPath('data.0.is_settled', true);
    }

    #[Test]
    public function a_retry_that_is_waiting_its_turn_reads_as_scheduled(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer, ['status' => ServiceStatus::Provisioning]);

        // Exactly what the engine leaves behind after a transient fault: back
        // to queued, with the previous failure still classified on the row.
        $this->jobFor($service, [
            'status' => ProvisioningJobStatus::Queued,
            'attempts' => 1,
            'failure_class' => FailureClass::Transient,
            'last_error' => 'connection refused by 10.20.0.7',
            'next_attempt_at' => now()->addMinute(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}/events")
            ->assertOk()
            ->assertJsonPath('data.0.state', CustomerProvisioningEventState::Scheduled->value)
            ->assertJsonPath('data.0.is_settled', false)
            ->assertJsonPath('data.0.failure_reason', CustomerFailureReason::TemporaryIssue->value);
    }

    #[Test]
    public function nothing_the_provider_said_reaches_the_customer(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        [$service] = $this->serviceWaitingOnAPerson($customer);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}/events")
            ->assertOk();

        $event = (array) $response->json('data.0');

        foreach ([
            'last_error',
            'failure_class',
            'provider',
            'remote_job_id',
            'payload',
            'result',
            'correlation_id',
            'attempts',
            'max_attempts',
            'timeout_seconds',
            'next_attempt_at',
            'service_id',
            'customer_id',
            'order_id',
            'idempotency_key',
            'status',
            'attempt_records',
            'response_metadata',
            'error_message',
            'updated_at',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $event, sprintf('%s must not be published on a provisioning event.', $field));
        }

        // And the whole key set. This is the row that carries the provider's
        // response, its job id and its error text; a resource that ever grew a
        // `parent::toArray()` would publish all three at once, and no blocklist
        // catches the column nobody has added yet.
        $this->assertSame(
            ['id', 'kind', 'state', 'is_settled', 'failure_reason', 'created_at', 'started_at', 'finished_at'],
            array_keys($event),
        );

        // The node name, the internal address and the provider's own task id
        // are all in this job's last_error. None of them may appear anywhere
        // in the body, under any key.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('pve-03', $body);
        $this->assertStringNotContainsString('10.20.0.7', $body);
        $this->assertStringNotContainsString('UPID:', $body);
        $this->assertStringNotContainsString('internal', $body);
    }
}
