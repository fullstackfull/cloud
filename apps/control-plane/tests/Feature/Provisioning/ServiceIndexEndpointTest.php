<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/services.
 *
 * The index across every kind of thing a customer bought. Most of what is
 * asserted here is what it does NOT return: another account's rows, an
 * unbounded page, or the operational half of a service row.
 */
final class ServiceIndexEndpointTest extends ServiceApiTestCase
{
    #[Test]
    public function it_lists_the_acting_customers_services(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer, [
            'status' => ServiceStatus::Active,
            'label' => 'web-01',
            'activated_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $service->id)
            ->assertJsonPath('data.0.label', 'web-01')
            ->assertJsonPath('data.0.kind', 'vps')
            ->assertJsonPath('data.0.state', CustomerServiceState::Active->value)
            ->assertJsonPath('data.0.is_usable', true)
            ->assertJsonPath('meta.total', 1);

        // The entitlement snapshotted from the plan is the substance of what
        // was bought, and is already published by the catalogue.
        // assertEquals rather than assertSame: jsonb does not preserve key
        // order, and asserting on the order Postgres happened to store would
        // be a test of the storage engine.
        $this->assertEquals(
            ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80],
            $response->json('data.0.resources'),
        );
    }

    #[Test]
    public function another_customers_services_are_not_in_the_list(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $ours = $this->serviceFor($mine);
        $hers = $this->serviceFor($theirs);

        $response = $this->actingAs($user)->getJson('/api/v1/services')->assertOk();

        $this->assertSame([$ours->id], array_column((array) $response->json('data'), 'id'));
        $this->assertNotContains($hers->id, array_column((array) $response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    #[Test]
    public function a_page_cannot_be_larger_than_the_ceiling(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        foreach (range(1, 8) as $ignored) {
            $this->serviceFor($customer);
        }

        // A client asking for a hundred thousand rows is answered with the
        // ceiling rather than refused — but never with a hundred thousand.
        $this->actingAs($user)
            ->getJson('/api/v1/services?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.max_per_page', 100)
            ->assertJsonCount(8, 'data');

        $this->actingAs($user)
            ->getJson('/api/v1/services?per_page=3')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonCount(3, 'data');

        // Zero is a nonsense page, not a small one: it must not become a slow
        // way to walk the whole collection.
        $this->actingAs($user)
            ->getJson('/api/v1/services?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    #[Test]
    public function a_build_nobody_is_working_on_is_not_reported_as_provisioning(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        [$stuck] = $this->serviceWaitingOnAPerson($customer);
        $building = $this->serviceFor($customer, ['status' => ServiceStatus::Provisioning]);
        $this->jobFor($building, ['status' => ProvisioningJobStatus::Running, 'started_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/v1/services')->assertOk();

        $states = array_column((array) $response->json('data'), 'state', 'id');

        $this->assertSame(CustomerServiceState::UnderReview->value, $states[$stuck->id]);
        $this->assertSame(CustomerServiceState::Provisioning->value, $states[$building->id]);
    }

    #[Test]
    public function a_failed_reboot_does_not_make_a_running_service_look_broken(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // The job needs a person; the server it rebooted is still running, and
        // saying otherwise would be a lie in the direction that costs support
        // a ticket.
        $service = $this->serviceFor($customer, ['status' => ServiceStatus::Active, 'activated_at' => now()]);
        $this->jobFor($service, ['status' => ProvisioningJobStatus::NeedsReview]);

        $this->actingAs($user)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonPath('data.0.state', CustomerServiceState::Active->value)
            ->assertJsonPath('data.0.is_usable', true);
    }

    #[Test]
    public function the_state_filter_asks_the_database_what_the_list_prints(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        [$stuck] = $this->serviceWaitingOnAPerson($customer);
        $building = $this->serviceFor($customer, ['status' => ServiceStatus::Provisioning]);
        $active = $this->serviceFor($customer, ['status' => ServiceStatus::Active]);

        $underReview = $this->actingAs($user)
            ->getJson('/api/v1/services?state=under_review')
            ->assertOk();
        $this->assertSame([$stuck->id], array_column((array) $underReview->json('data'), 'id'));

        // The service waiting on a person is provisioning in the database and
        // must not come back under a filter that prints a different word.
        $provisioning = $this->actingAs($user)
            ->getJson('/api/v1/services?state=provisioning')
            ->assertOk();
        $this->assertSame([$building->id], array_column((array) $provisioning->json('data'), 'id'));

        $activeOnly = $this->actingAs($user)
            ->getJson('/api/v1/services?state=active')
            ->assertOk();
        $this->assertSame([$active->id], array_column((array) $activeOnly->json('data'), 'id'));
    }

    #[Test]
    public function a_service_that_has_never_been_worked_on_still_appears_under_its_own_state(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // No jobs at all, so the latest-job column is null. A bare `<>` in the
        // filter would have compared against null and dropped this row.
        $pending = $this->serviceFor($customer, ['status' => ServiceStatus::Pending]);

        $response = $this->actingAs($user)->getJson('/api/v1/services?state=pending')->assertOk();

        $this->assertSame([$pending->id], array_column((array) $response->json('data'), 'id'));
    }

    #[Test]
    public function the_kind_filter_selects_one_product_line(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $vps = $this->serviceFor($customer, ['kind' => 'vps']);
        $this->serviceFor($customer, ['kind' => 'dedicated']);

        $response = $this->actingAs($user)->getJson('/api/v1/services?kind=vps')->assertOk();

        $this->assertSame([$vps->id], array_column((array) $response->json('data'), 'id'));
    }

    #[Test]
    public function a_state_the_vocabulary_does_not_contain_is_a_422(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/services?state=provisioning_forever')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['state']]]]);
    }

    #[Test]
    public function nothing_operational_is_in_a_listed_service(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->serviceWaitingOnAPerson($customer);

        $service = (array) $this->actingAs($user)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->json('data.0');

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
            $this->assertArrayNotHasKey($field, $service, sprintf('%s must not be published on a service.', $field));
        }

        // And the whole key set, not only the fields somebody thought to name.
        // The list endpoint is where the scoped query hangs its own working
        // columns on the model — a count of jobs in review today, whatever the
        // next derived state needs tomorrow — and a resource that ever grew a
        // `parent::toArray()` would publish every one of them under a name no
        // blocklist above could have predicted.
        $this->assertSame([
            'id', 'kind', 'label', 'state', 'is_usable', 'resources',
            'plan_id', 'order_id', 'order_item_id', 'subscription_id',
            'activated_at', 'suspended_at', 'retention_ends_at', 'ended_reason',
            'terminated_at', 'created_at',
        ], array_keys($service));

        // Nothing the provider said, anywhere in the body. The listed service's
        // stuck job carries a node name and an internal address in its
        // last_error, and the list is read far more often than the detail view.
        $body = (string) $this->actingAs($user)->getJson('/api/v1/services')->getContent();
        $this->assertStringNotContainsString('pve-03', $body);
        $this->assertStringNotContainsString('10.20.0.7', $body);
        $this->assertStringNotContainsString('UPID:', $body);
    }

    #[Test]
    public function a_later_job_does_not_hide_a_build_that_is_still_waiting_on_a_person(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // A service accumulates jobs. If the derived state asks what the LATEST
        // job did rather than whether anything is still owed a decision, then
        // one reboot after a timed-out build is enough to put the service back
        // to reading `provisioning` — with an orphan possibly running at the
        // provider and nobody looking at it. That is the exact lie under_review
        // exists to stop telling.
        [$stuck] = $this->serviceWaitingOnAPerson($customer);

        $this->jobFor($stuck, [
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
            'created_at' => now(),
        ]);

        $underReview = $this->actingAs($user)->getJson('/api/v1/services?state=under_review')->assertOk();
        $this->assertSame([$stuck->id], array_column((array) $underReview->json('data'), 'id'));

        $provisioning = $this->actingAs($user)->getJson('/api/v1/services?state=provisioning')->assertOk();
        $this->assertSame([], array_column((array) $provisioning->json('data'), 'id'));
    }

    #[Test]
    public function a_service_with_several_jobs_in_review_is_listed_once(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $service = $this->serviceFor($customer, ['status' => ServiceStatus::Provisioning]);

        foreach (range(1, 3) as $ignored) {
            $this->jobFor($service, ['status' => ProvisioningJobStatus::NeedsReview]);
        }

        // A filter written as a join rather than as an existence test would
        // return this service three times, put it on two pages and make
        // meta.total a lie. There is no id a caller could use to notice.
        $response = $this->actingAs($user)->getJson('/api/v1/services?state=under_review')->assertOk();

        $this->assertSame([$service->id], array_column((array) $response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    #[Test]
    public function a_filter_does_not_widen_the_list_beyond_the_acting_account(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $ours = $this->serviceFor($mine, ['status' => ServiceStatus::Active, 'kind' => 'vps']);
        $hers = $this->serviceFor($theirs, ['status' => ServiceStatus::Active, 'kind' => 'vps']);
        [$herStuck] = $this->serviceWaitingOnAPerson($theirs);

        // Every filter is applied to the already-scoped relation. A filter
        // built on a fresh query — the obvious way to add one later — would
        // answer from the whole estate.
        foreach (['state=active', 'kind=vps', 'state=under_review', 'state=active&kind=vps'] as $filter) {
            $ids = array_column(
                (array) $this->actingAs($user)->getJson('/api/v1/services?'.$filter)->assertOk()->json('data'),
                'id'
            );

            $this->assertNotContains($hers->id, $ids, $filter);
            $this->assertNotContains($herStuck->id, $ids, $filter);
        }

        $this->assertSame(
            [$ours->id],
            array_column((array) $this->actingAs($user)->getJson('/api/v1/services?state=active')->assertOk()->json('data'), 'id')
        );
    }

    #[Test]
    public function a_member_of_the_account_may_read_its_services(): void
    {
        // The narrowest role there is. Watching the estate is what it is for,
        // so the authorisation check must not stand in its way.
        [$customer, $user] = $this->accountWithOwner(CustomerRole::Member);
        $this->serviceFor($customer);

        $this->actingAs($user)->getJson('/api/v1/services')->assertOk()->assertJsonCount(1, 'data');
    }
}
