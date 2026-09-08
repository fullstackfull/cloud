<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/services/{service}.
 *
 * One service and where it stands. The id in the path is the whole risk on
 * this endpoint, so most of what follows is about what happens when it names
 * somebody else's row.
 */
final class ServiceShowEndpointTest extends ServiceApiTestCase
{
    #[Test]
    public function it_returns_one_of_the_acting_customers_services(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer, [
            'status' => ServiceStatus::Active,
            'label' => 'db-01',
            'activated_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $service->id)
            ->assertJsonPath('data.label', 'db-01')
            ->assertJsonPath('data.state', CustomerServiceState::Active->value)
            ->assertJsonPath('data.is_usable', true)
            ->assertJsonPath('data.activated_at', $service->activated_at?->toIso8601String());
    }

    #[Test]
    public function another_customers_service_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $hers = $this->serviceFor($theirs, ['status' => ServiceStatus::Active]);

        // 403 would confirm the row exists, which on ULIDs is an enumeration
        // oracle over the platform's entire estate.
        $this->actingAs($user)
            ->getJson("/api/v1/services/{$hers->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function a_real_service_and_an_invented_id_are_refused_identically(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $real = $this->actingAs($user)->getJson('/api/v1/services/'.$this->serviceFor($theirs)->id);
        $invented = $this->actingAs($user)->getJson('/api/v1/services/01JZZZZZZZZZZZZZZZZZZZZZZZ');

        // Byte for byte the same, or the difference sorts real ids from
        // invented ones.
        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function a_build_that_timed_out_says_so_rather_than_provisioning_for_ever(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        [$service] = $this->serviceWaitingOnAPerson($customer);

        // The row still says provisioning; nobody is provisioning anything.
        $this->assertSame(ServiceStatus::Provisioning, $service->refresh()->status);

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.state', CustomerServiceState::UnderReview->value)
            ->assertJsonPath('data.is_usable', false);
    }

    #[Test]
    public function a_later_job_does_not_hide_a_build_that_is_still_waiting_on_a_person(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        [$service] = $this->serviceWaitingOnAPerson($customer);

        // Anything at all happening to the service afterwards — a reboot, a
        // second build attempt, an operator's power cycle — must not make the
        // stuck job disappear from what the customer is told. Asking only what
        // the most recent job did is how a service with a possible orphan at
        // the provider goes back to reading `provisioning` for ever.
        $this->jobFor($service, [
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.state', CustomerServiceState::UnderReview->value)
            ->assertJsonPath('data.is_usable', false);
    }

    #[Test]
    public function a_resolved_review_stops_holding_the_service_in_review(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        [$service, $job] = $this->serviceWaitingOnAPerson($customer);

        // The only way out of needs_review is a person deciding something. Once
        // they have — here, the orphan was adopted — the service must stop
        // saying somebody is still owed a decision, or the word means nothing.
        $job->forceFill(['status' => ProvisioningJobStatus::Succeeded, 'failure_class' => null])->save();

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.state', CustomerServiceState::Provisioning->value);
    }

    #[Test]
    public function a_failed_build_says_failed(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer, ['status' => ServiceStatus::Failed]);

        $this->actingAs($user)
            ->getJson("/api/v1/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.state', CustomerServiceState::Failed->value)
            ->assertJsonPath('data.is_usable', false);
    }

    #[Test]
    public function nothing_operational_is_in_the_document(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        [$service] = $this->serviceWaitingOnAPerson($customer);

        $response = $this->actingAs($user)->getJson("/api/v1/services/{$service->id}")->assertOk();

        $document = (array) $response->json('data');

        foreach ([
            'customer_id',
            'region_id',
            'status',
            'last_error',
            'provider',
            'remote_job_id',
            'credentials_reference',
            'provider_metadata',
            'internal_notes',
            'updated_at',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $document, sprintf('%s must not be published on a service.', $field));
        }

        // The whole key set, so that the working columns the scoped query hangs
        // on the model cannot arrive under a name this list did not predict.
        $this->assertSame([
            'id', 'kind', 'label', 'state', 'is_usable', 'resources',
            'plan_id', 'order_id', 'order_item_id', 'subscription_id',
            'activated_at', 'suspended_at', 'retention_ends_at', 'ended_reason',
            'terminated_at', 'created_at',
        ], array_keys($document));

        // And nothing the provider said, anywhere in the body — the node name
        // and the internal address on this job's last_error are exactly the
        // sort of thing the phase 9 review found reaching a stored column.
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('pve-03', $body);
        $this->assertStringNotContainsString('10.20.0.7', $body);
        $this->assertStringNotContainsString('UPID:', $body);
    }
}
