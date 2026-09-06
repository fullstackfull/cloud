<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\ReleaseQuarantinedAddresses;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Application\Actions\AdoptOrphanResource;
use Lynomia\Modules\Provisioning\Application\Actions\CompensateFailedJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A timeout quarantine does not end on a clock, because time answers nothing.
 *
 * Every other quarantine is a waiting period: the address WAS in service, its
 * reputation decays, and after the pool's window it is safe to hand on. A
 * timeout is different in kind — the platform stopped waiting and does not
 * know whether a machine was built with the address configured on it, and it
 * will not know a week later either. IpAllocator::release() says so in as many
 * words: "the address sits out until an operator has checked rather than going
 * to the next customer and putting two machines on one address".
 *
 * The sweeper used to read only status and expiry, so the module's own
 * documented recovery from a timeout produced the exact incident the
 * reserve/commit/release lifecycle exists to prevent: an operator finds the
 * machine the cluster really did build and adopts it — that machine is live,
 * billed and configured with the address — and quarantine_days later the
 * address was handed to the next customer while the first machine was still
 * answering on it. Two tenants on one address on one segment; whichever wins
 * the ARP race receives the other's inbound traffic, and the victim's outage
 * reads as a network fault rather than a control-plane one.
 *
 * This is cross-cutting rather than an IPAM test: the defect only appears
 * where the provisioning engine's timeout rule and the IPAM sweeper meet.
 */
final class TimedOutQuarantineIsNotSweptBackIntoThePoolTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_address_a_recovered_machine_runs_on_is_never_handed_to_the_next_customer(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        $pool = $subnet->ipPool;

        $firstCustomer = Customer::factory()->create();
        $service = Service::factory()->create(['customer_id' => $firstCustomer->id, 'kind' => 'vps']);

        $job = ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $firstCustomer->id,
            'kind' => 'create_vps',
            'provider' => 'fake',
            'status' => 'running',
        ]);

        $allocator = app(IpAllocator::class);

        // The build takes the address and writes it into the machine's
        // cloud-init before the hypervisor is called.
        $reservation = $allocator->reserve(
            scope: $pool,
            provisioningJobId: (string) $job->getKey(),
            customer: $firstCustomer,
        )[0];

        $addressId = $reservation->ip_address_id;
        $addressValue = IpAddress::query()->findOrFail($addressId)->address;

        // The create times out. Compensation quarantines rather than releasing.
        $job->forceFill([
            'status' => 'needs_review',
            'failure_class' => FailureClass::Timeout,
            'remote_job_id' => 'UPID:pve-01:0000A1B2',
        ])->save();

        app(CompensateFailedJob::class)->execute($job, FailureClass::Timeout);

        $address = IpAddress::query()->findOrFail($addressId);
        $this->assertSame(IpAddressStatus::Quarantined, $address->status);
        $this->assertSame(ReleaseReason::ProvisioningTimedOut, $address->quarantine_reason);

        // An operator goes and looks, finds the machine the cluster really did
        // build — configured with this very address — and adopts it.
        app(AdoptOrphanResource::class)->execute(
            job: $job,
            providerReference: '10123',
            remoteJobId: 'UPID:pve-01:0000A1B2',
            evidence: ['seen_on' => 'pve-01', 'ipv4' => $addressValue],
            adoptedBy: 'noc@lynomia.test',
        );

        // Long past any window the pool would have applied.
        $this->travel(($pool->quarantine_days * 10) + 1)->days();

        $this->assertSame(
            0,
            app(ReleaseQuarantinedAddresses::class)->execute(),
            'A timeout quarantine was swept back into the pool on a clock.',
        );

        $address->refresh();
        $this->assertSame(IpAddressStatus::Quarantined, $address->status);
        $this->assertSame(ReleaseReason::ProvisioningTimedOut, $address->quarantine_reason);

        // And the next customer is given a different address.
        $secondCustomer = Customer::factory()->create();
        $secondService = Service::factory()->create(['customer_id' => $secondCustomer->id, 'kind' => 'vps']);
        $secondJob = ProvisioningJob::factory()->create([
            'service_id' => $secondService->id,
            'customer_id' => $secondCustomer->id,
            'kind' => 'create_vps',
            'provider' => 'fake',
            'status' => 'running',
        ]);

        $handed = $allocator->reserve(
            scope: $pool,
            provisioningJobId: (string) $secondJob->getKey(),
            customer: $secondCustomer,
        )[0];

        $this->assertNotSame(
            $addressId,
            $handed->ip_address_id,
            'Two customers on one address: the adopted machine is still configured with '.$addressValue.'.',
        );
    }

    #[Test]
    public function an_ordinary_quarantine_still_expires_on_the_pools_window(): void
    {
        // The exclusion is narrow on purpose. Every other reason IS a waiting
        // period, and a pool that never recycled anything would bleed an
        // address per cancellation until the subnet ran out.
        $subnet = Subnet::factory()->forBlock('198.51.100.16/29', gateway: '198.51.100.17')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['customer_id' => $customer->id, 'kind' => 'vps']);
        $job = ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'kind' => 'create_vps',
            'provider' => 'fake',
            'status' => 'running',
        ]);

        $allocator = app(IpAllocator::class);
        $reservation = $allocator->reserve(
            scope: $subnet->ipPool,
            provisioningJobId: (string) $job->getKey(),
            customer: $customer,
        )[0];

        $assignment = $allocator->commit($reservation);
        $allocator->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);

        $this->travel($subnet->ipPool->quarantine_days + 1)->days();

        $this->assertSame(1, app(ReleaseQuarantinedAddresses::class)->execute());
        $this->assertSame(
            IpAddressStatus::Available,
            IpAddress::query()->findOrFail($assignment->ip_address_id)->status,
        );
    }
}
