<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Tests\TestCase;

/**
 * Shared scaffolding for the service endpoints.
 *
 * Two customers appear in almost every test here on purpose: the interesting
 * question about a service API is not whether it can show you your own
 * servers, it is whether it can be talked into showing you somebody else's —
 * or into telling you what a provider said while failing to build one.
 */
abstract class ServiceApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * A customer account with an accepted owner membership, and the user who
     * holds it.
     *
     * @return array{0: Customer, 1: User}
     */
    protected function accountWithOwner(CustomerRole $role = CustomerRole::Owner): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        return [$customer, $this->memberOf($customer, $role)];
    }

    protected function memberOf(Customer $customer, CustomerRole $role = CustomerRole::Owner, ?User $user = null): User
    {
        $user ??= User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            // An invitation that was never accepted grants nothing, so every
            // membership these tests rely on is explicitly accepted.
            'accepted_at' => now(),
        ]);

        return $user;
    }

    /**
     * A service belonging to a given account.
     *
     * The customer is passed rather than left to the factory to invent, since
     * every test here turns on which account a row belongs to.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function serviceFor(Customer $customer, array $attributes = []): Service
    {
        return Service::factory()->create(['customer_id' => $customer->id] + $attributes);
    }

    /**
     * A provisioning job against a given service.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function jobFor(Service $service, array $attributes = []): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $service->customer_id,
        ] + $attributes);
    }

    /**
     * The exact shape the platform is left in by a build that timed out: the
     * job settled in needs_review because a person has to go and look for an
     * orphan, and the service row still says provisioning because the
     * stale-job sweeper deliberately does not touch it.
     *
     * @return array{0: Service, 1: ProvisioningJob}
     */
    protected function serviceWaitingOnAPerson(Customer $customer): array
    {
        $service = $this->serviceFor($customer, ['status' => ServiceStatus::Provisioning]);

        $job = $this->jobFor($service, [
            'kind' => ProvisioningJobKind::CreateVps,
            'status' => ProvisioningJobStatus::NeedsReview,
            'failure_class' => FailureClass::Timeout,
            'last_error' => 'Proxmox node pve-03.kw1.internal timed out at 10.20.0.7 (task UPID:pve-03:000A1B2C)',
            'remote_job_id' => 'UPID:pve-03:000A1B2C',
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(15),
        ]);

        return [$service, $job];
    }
}
