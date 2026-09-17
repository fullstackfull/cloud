<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A build that stopped, seen from the two places it has to be seen from.
 *
 * ---------------------------------------------------------------------------
 * Why this is a golden-path concern and not a controller test
 * ---------------------------------------------------------------------------
 *
 * Because the worst outcome of a cross-domain workflow is not a failure. It is
 * a failure nobody can see: a job in `needs_review` that no screen lists, a
 * customer refreshing a page that says "provisioning" for ever, and an
 * operator with no way to find either. §88 asks for the failure to be visible
 * *somewhere appropriate*, and there are exactly two appropriate somewheres —
 * so both are asserted here, over the same row.
 *
 * The other half is what must not be visible. The customer's view of a stopped
 * operation is allowed to say that it stopped and that somebody is looking; it
 * is not allowed to carry the provider's name, the node's hostname or the
 * task handle, because those are audit-log vocabulary and a customer reading
 * them learns only that the platform is leaking.
 *
 * Run in one process deliberately: this is about what two HTTP reads return
 * for a stored state, and a worker in the middle would add nothing.
 */
#[Group('golden-path')]
final class ATerminalFailureIsVisibleToBothSidesTest extends TestCase
{
    use RefreshDatabase;

    private const string PROVIDER_DETAIL = 'pve-node-07.internal.lynomia.test';

    private const string TASK_HANDLE = 'UPID:pve-node-07:00000ABC:fake@pve!lynomia:qmcreate';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    #[Test]
    public function the_customer_is_told_their_build_stopped_without_being_told_the_platforms_internals(): void
    {
        [$customer, $user, $job] = $this->stoppedBuild();

        $response = $this->actingAs($user)
            ->getJson(route('api.v1.operations.show', ['operation' => (string) $job->getKey()]))
            ->assertOk();

        $body = $response->json();

        // It says it stopped, in the lifecycle's own vocabulary.
        $this->assertSame('needs_review', data_get($body, 'data.state'));
        $this->assertTrue((bool) data_get($body, 'data.is_terminal'));

        // And not a word about where.
        $json = (string) $response->getContent();

        $this->assertStringNotContainsString(self::PROVIDER_DETAIL, $json);
        $this->assertStringNotContainsString(self::TASK_HANDLE, $json);
        $this->assertStringNotContainsString('UPID:', $json);
    }

    #[Test]
    public function an_operator_can_find_the_same_build_in_the_queue_that_exists_for_it(): void
    {
        [, , $job] = $this->stoppedBuild();

        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        $response = $this->actingAs($operator)
            ->getJson('/api/admin/provisioning/needs-review')
            ->assertOk();

        $ids = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            (array) $response->json('data'),
        );

        $this->assertContains((string) $job->getKey(), $ids, 'the stopped build is in no operator queue');

        /*
         * And the operator's view carries what the customer's must not: the
         * classification and the provider's own message are what a person with
         * a runbook needs in order to decide whether to adopt an orphan,
         * retry, or refund.
         */
        $row = collect((array) $response->json('data'))
            ->firstWhere('id', (string) $job->getKey());

        $this->assertIsArray($row);
        $this->assertSame('timeout', data_get($row, 'failure_class'));
    }

    /**
     * A customer, their user, and a build that stopped with the outcome unknown.
     *
     * @return array{0: Customer, 1: User, 2: ProvisioningJob}
     */
    private function stoppedBuild(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        /*
         * A membership, not a column. Access to an account is a row in
         * `customer_members` with an acceptance date on it — an invitation
         * nobody accepted grants nothing — and the customer surface reads that
         * rather than a foreign key on the user.
         */
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->getKey(),
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $service = Service::factory()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'status' => ServiceStatus::Provisioning,
        ]);

        $job = ProvisioningJob::factory()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $service->getKey(),
            'kind' => ProvisioningJobKind::CreateVps,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
            'provider' => 'fake',
            'remote_job_id' => self::TASK_HANDLE,
            'last_error' => 'the hypervisor stopped answering on '.self::PROVIDER_DETAIL,
            'idempotency_key' => 'visible:'.$service->getKey(),
        ]);

        return [$customer, $user, $job];
    }
}
