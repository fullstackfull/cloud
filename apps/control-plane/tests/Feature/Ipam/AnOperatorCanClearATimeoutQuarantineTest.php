<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Application\Actions\ReleaseQuarantinedAddresses;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Application\Queries\CustomerIpAssignments;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Application\Actions\CompensateFailedJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A timeout quarantine ends when a person has looked, and a person can now end
 * one (F-34).
 *
 * `ReleaseReason::requiresOperatorClearance()` says what clearing is: the
 * operator looks at the provider, and then *either* the resource is adopted —
 * the address stays with the machine — *or* it demonstrably does not exist and
 * the address is released by hand, as `OperatorAction`. The sweep skips these
 * rows on purpose, because a clock answers none of the timeout's question. So
 * until an operator surface existed, a timed-out address left a finite pool
 * for good: no route named an address, no command cleared one, and
 * `ipam:capacity` counted the leak without offering anything to do about it.
 *
 * These tests drive the surface an operator uses — the list, and the two acts
 * the docblock names — against quarantines produced by production code: a
 * reservation taken by the allocator, and a timeout compensated by
 * `CompensateFailedJob`, which is what quarantines it in the running system.
 *
 * `QuarantineLifecycleTest` is F-44's and is deliberately not touched.
 */
final class AnOperatorCanClearATimeoutQuarantineTest extends TestCase
{
    use RefreshDatabase;

    private const string AWAITING = '/api/admin/infrastructure/ip-addresses/awaiting-clearance';

    private IpAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->allocator = app(IpAllocator::class);
    }

    #[Test]
    public function an_operator_can_find_every_address_waiting_for_a_person(): void
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.8/29', '198.51.100.9');

        [$timedOut, $job] = $this->timedOutAddress($subnet, $customer);

        // Two quarantines in the same pool that are not an operator's to
        // clear: an ordinary one, which ends on the pool's window, and a held
        // one, which ends when its machine is declared empty.
        $this->allocator->releaseAssignment($this->liveAssignmentIn($subnet, $customer), ReleaseReason::ServiceTerminated);
        $this->allocator->holdAssignment($this->liveAssignmentIn($subnet, $customer), ReleaseReason::ServiceTerminated);

        $response = $this->actingAs($this->operator(Role::NetworkEngineer))
            ->getJson(self::AWAITING)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data');

        $response->assertJsonPath('data.0.id', (string) $timedOut->getKey());
        $response->assertJsonPath('data.0.address', $timedOut->address);
        $response->assertJsonPath('data.0.quarantine_reason', ReleaseReason::ProvisioningTimedOut->value);
        $response->assertJsonPath('data.0.provisioning_job_id', (string) $job->getKey());
        $response->assertJsonPath('data.0.customer_id', (string) $customer->getKey());

        // The row carries a `quarantined_until` like every quarantine does,
        // and nothing will ever act on it. This asserts what an operator
        // reads next to that date — not how the field is computed, which no
        // row this endpoint can return is able to distinguish.
        $response->assertJsonPath('data.0.ends_on_a_clock', false);
    }

    #[Test]
    public function adopting_keeps_the_address_with_the_machine_the_build_made(): void
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.16/29', '198.51.100.17');

        [$address, $job, $service] = $this->timedOutAddress($subnet, $customer);

        $this->actingAs($this->operator(Role::NetworkEngineer))
            ->postJson($this->adoptUrl($address), [
                'evidence' => 'VM 10123 on pve-01 is running with this address on net0.',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $address->getKey())
            ->assertJsonPath('data.status', IpAddressStatus::Assigned->value)
            ->assertJsonPath('data.customer_id', (string) $customer->getKey())
            ->assertJsonPath('data.service_id', (string) $service->getKey());

        // Assigned, not available: adoption is the half that keeps the
        // address. A clearance path that handed it back here would put the
        // next customer on the address the adopted machine answers on.
        $address->refresh();
        $this->assertSame(IpAddressStatus::Assigned, $address->status);
        $this->assertNull($address->quarantined_until);
        $this->assertNull($address->quarantine_reason);

        $assignment = IpAssignment::query()->where('ip_address_id', $address->getKey())->sole();
        $this->assertTrue($assignment->isLive());
        $this->assertSame((string) $customer->getKey(), $assignment->customer_id);
        $this->assertSame((string) $service->getKey(), $assignment->service_id);
        $this->assertTrue($assignment->is_primary);

        // The customer sees the address their machine answers on.
        $this->assertSame(1, CustomerIpAssignments::of($customer)->count());

        $entry = AuditEntry::query()->sole();
        $this->assertSame(AuditAction::QuarantinedAddressAdopted, $entry->action);
        $this->assertTrue($entry->action->isAnAssertionAboutTheWorld());
        $this->assertSame((string) $address->getKey(), $entry->subject_id);
        $this->assertSame((string) $customer->getKey(), $entry->customer_id);
        $this->assertSame((string) $job->getKey(), $entry->context['provisioning_job_id'] ?? null);
        $this->assertStringContainsString('VM 10123 on pve-01', (string) ($entry->context['evidence'] ?? ''));
    }

    #[Test]
    public function releasing_by_hand_returns_the_address_to_the_pool_as_the_sweep_would(): void
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.24/29', '198.51.100.25');

        [$address, $job] = $this->timedOutAddress($subnet, $customer);

        $this->actingAs($this->operator(Role::NetworkEngineer))
            ->postJson($this->releaseUrl($address), [
                'evidence' => 'pve-01 and pve-02 have no VM with this address; the task log shows the create never started.',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', (string) $address->getKey())
            ->assertJsonPath('data.status', IpAddressStatus::Available->value);

        // Exactly the state ReleaseQuarantinedAddresses leaves a row in.
        $address->refresh();
        $this->assertSame(IpAddressStatus::Available, $address->status);
        $this->assertNull($address->quarantined_until);
        $this->assertNull($address->quarantine_reason);

        // History is not rewritten: the reservation still says a timeout
        // ended it, which is true.
        $reservation = IpReservation::query()->where('ip_address_id', $address->getKey())->sole();
        $this->assertSame(ReleaseReason::ProvisioningTimedOut, $reservation->released_reason);

        $this->assertSame(0, IpAssignment::query()->where('ip_address_id', $address->getKey())->count());

        // The by-hand reason lives in the trail.
        $entry = AuditEntry::query()->sole();
        $this->assertSame(AuditAction::QuarantinedAddressReleased, $entry->action);
        $this->assertTrue($entry->action->isAnAssertionAboutTheWorld());
        $this->assertSame((string) $address->getKey(), $entry->subject_id);
        $this->assertSame((string) $customer->getKey(), $entry->customer_id);
        $this->assertSame(ReleaseReason::OperatorAction->value, $entry->context['release_reason'] ?? null);
        $this->assertSame((string) $job->getKey(), $entry->context['provisioning_job_id'] ?? null);

        // And the pool has its fifth address back: before the release a
        // five-address reservation from this /29 could not be satisfied.
        $next = ProvisioningJob::factory()->create();
        $handed = $this->allocator->reserve($subnet, (string) $next->getKey(), count: 5);
        $this->assertContains(
            (string) $address->getKey(),
            array_map(static fn (IpReservation $r): string => (string) $r->ip_address_id, $handed),
        );
    }

    #[Test]
    public function a_quarantine_that_is_not_a_timeout_is_refused_by_both_doors(): void
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.32/29', '198.51.100.33');

        // The quarantine that production actually produces every day: a
        // destroyed VPS's address, released as ServiceTerminated onto the
        // pool's window. And the held kind, which waits for a machine to be
        // declared empty. Neither is this door's to end.
        $ordinary = $this->allocator->releaseAssignment($this->liveAssignmentIn($subnet, $customer), ReleaseReason::ServiceTerminated);
        $held = $this->allocator->holdAssignment($this->liveAssignmentIn($subnet, $customer), ReleaseReason::ServiceTerminated);

        $operator = $this->operator(Role::NetworkEngineer);

        foreach ([$ordinary, $held] as $assignment) {
            /** @var IpAddress $address */
            $address = IpAddress::query()->findOrFail($assignment->ip_address_id);
            $before = $address->quarantined_until?->toIso8601String();

            $this->actingAs($operator)
                ->postJson($this->adoptUrl($address), ['evidence' => 'Trying to shorten a window.'])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'ipam.quarantine_not_clearable');

            $this->actingAs($operator)
                ->postJson($this->releaseUrl($address), ['evidence' => 'Trying to shorten a window.'])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'ipam.quarantine_not_clearable');

            $address->refresh();
            $this->assertSame(IpAddressStatus::Quarantined, $address->status);
            $this->assertSame(ReleaseReason::ServiceTerminated, $address->quarantine_reason);
            $this->assertSame($before, $address->quarantined_until?->toIso8601String());
            $this->assertSame(0, IpAssignment::query()->where('ip_address_id', $address->getKey())->live()->count());
        }

        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function an_address_that_is_not_quarantined_is_refused_by_both_doors(): void
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.40/29', '198.51.100.41');

        [$address] = $this->timedOutAddress($subnet, $customer);
        $operator = $this->operator(Role::NetworkEngineer);

        $this->actingAs($operator)
            ->postJson($this->adoptUrl($address), ['evidence' => 'VM 10124 is running on this address.'])
            ->assertOk();

        // Cleared once. A second clearance of either kind is refused rather
        // than writing a second assignment or handing a live address back.
        $this->actingAs($operator)
            ->postJson($this->adoptUrl($address), ['evidence' => 'Again.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ipam.address_not_quarantined');

        $this->actingAs($operator)
            ->postJson($this->releaseUrl($address), ['evidence' => 'Again.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ipam.address_not_quarantined');

        $this->assertSame(IpAddressStatus::Assigned, $address->refresh()->status);
        $this->assertSame(1, IpAssignment::query()->where('ip_address_id', $address->getKey())->count());

        // An address that was never quarantined at all.
        /** @var IpAddress $free */
        $free = IpAddress::query()
            ->where('subnet_id', $subnet->getKey())
            ->where('status', IpAddressStatus::Available->value)
            ->firstOrFail();

        $this->actingAs($operator)
            ->postJson($this->adoptUrl($free), ['evidence' => 'Nothing.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ipam.address_not_quarantined');

        $this->assertSame(IpAddressStatus::Available, $free->refresh()->status);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    #[Test]
    public function clearing_needs_the_evidence_the_operator_looked_at(): void
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.48/29', '198.51.100.49');

        [$address] = $this->timedOutAddress($subnet, $customer);
        $operator = $this->operator(Role::NetworkEngineer);

        $this->actingAs($operator)->postJson($this->adoptUrl($address), [])->assertStatus(422);
        $this->actingAs($operator)->postJson($this->releaseUrl($address), [])->assertStatus(422);

        // A NIC nobody could configure is refused before anything is written.
        $this->actingAs($operator)
            ->postJson($this->adoptUrl($address), ['evidence' => 'VM 10125 is running.', 'mac_address' => 'not-a-mac'])
            ->assertStatus(422);

        $address->refresh();
        $this->assertSame(IpAddressStatus::Quarantined, $address->status);
        $this->assertSame(ReleaseReason::ProvisioningTimedOut, $address->quarantine_reason);
        $this->assertSame(0, IpAssignment::query()->where('ip_address_id', $address->getKey())->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function only_an_operator_who_manages_addresses_may_clear_one(): void
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.56/29', '198.51.100.57');

        [$address] = $this->timedOutAddress($subnet, $customer);

        // The NOC can see the provisioning queue and cannot see addresses.
        $noc = $this->operator(Role::Noc);
        $this->actingAs($noc)->getJson(self::AWAITING)->assertForbidden();
        $this->actingAs($noc)->postJson($this->adoptUrl($address), ['evidence' => 'Seen.'])->assertForbidden();
        $this->actingAs($noc)->postJson($this->releaseUrl($address), ['evidence' => 'Seen.'])->assertForbidden();

        // Seeing the list is not the authority to act on it.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::IpamView->value);

        $this->actingAs($viewer)->getJson(self::AWAITING)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($viewer)->postJson($this->adoptUrl($address), ['evidence' => 'Seen.'])->assertForbidden();
        $this->actingAs($viewer)->postJson($this->releaseUrl($address), ['evidence' => 'Seen.'])->assertForbidden();

        $this->assertSame(IpAddressStatus::Quarantined, $address->refresh()->status);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function time_alone_still_ends_no_timeout_quarantine(): void
    {
        // The exit is a person, and building one did not add a clock. Green
        // before the operator surface existed; kept because deleting the
        // sweep's exclusion turns it red, so it is a lock on the half of the
        // design that says what does NOT clear these rows.
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.64/29', '198.51.100.65');

        [$address] = $this->timedOutAddress($subnet, $customer);

        $this->travel(5 * 365)->days();

        $this->assertSame(0, app(ReleaseQuarantinedAddresses::class)->execute());
        $this->assertSame(IpAddressStatus::Quarantined, $address->refresh()->status);
        $this->assertSame(ReleaseReason::ProvisioningTimedOut, $address->quarantine_reason);
    }

    #[Test]
    public function a_platform_address_is_adopted_onto_nobody(): void
    {
        [$address, $customer] = $this->platformAddressWithACustomerBehindIt('198.51.100.72/29', '198.51.100.73');
        $operator = $this->operator(Role::NetworkEngineer);

        // The queue row an operator reads names nobody either.
        $this->actingAs($operator)
            ->getJson(self::AWAITING)
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $address->getKey())
            ->assertJsonPath('data.0.customer_id', null);

        $this->actingAs($operator)
            ->postJson($this->adoptUrl($address), ['evidence' => 'The platform build VM 900 is running on this address.'])
            ->assertOk()
            ->assertJsonPath('data.customer_id', null);

        $assignment = IpAssignment::query()->where('ip_address_id', $address->getKey())->live()->sole();

        $this->assertNull(
            $assignment->customer_id,
            'A platform address was adopted onto the last customer who ever held it.',
        );

        // The consequence that makes it matter: the address would appear in
        // that customer's /api/v1/ips, with its PTR theirs to set.
        $this->assertSame(0, CustomerIpAssignments::of($customer)->count());

        $this->assertNull(AuditEntry::query()->sole()->customer_id);
    }

    #[Test]
    public function releasing_a_platform_address_by_hand_names_nobody_either(): void
    {
        [$address, , $platformJob] = $this->platformAddressWithACustomerBehindIt('198.51.100.80/29', '198.51.100.81');

        $this->actingAs($this->operator(Role::NetworkEngineer))
            ->postJson($this->releaseUrl($address), ['evidence' => 'The platform build never reached the hypervisor.'])
            ->assertOk();

        $entry = AuditEntry::query()->sole();

        // The job the timeout closed, and its customer, which is nobody.
        $this->assertSame((string) $platformJob->getKey(), $entry->context['provisioning_job_id'] ?? null);
        $this->assertNull($entry->customer_id, 'A by-hand release named a customer the address was not held for.');
    }

    #[Test]
    public function the_operator_can_say_which_nic_and_service_the_adopted_address_is_on(): void
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet('198.51.100.88/29', '198.51.100.89');

        [$address] = $this->timedOutAddress($subnet, $customer);

        // The service the timed-out job named is the fact most likely to be
        // wrong at exactly this moment, and the NIC is one the platform never
        // heard back about.
        $actual = Service::factory()->create(['customer_id' => $customer->id, 'kind' => 'vps']);

        $this->actingAs($this->operator(Role::NetworkEngineer))
            ->postJson($this->adoptUrl($address), [
                'evidence' => 'VM 10126 on pve-02 carries this address on its first NIC.',
                'service_id' => (string) $actual->getKey(),
                'mac_address' => 'bc-24-11-0a-0b-0c',
            ])
            ->assertOk()
            ->assertJsonPath('data.service_id', (string) $actual->getKey())
            ->assertJsonPath('data.mac_address', 'BC:24:11:0A:0B:0C');

        $assignment = IpAssignment::query()->where('ip_address_id', $address->getKey())->live()->sole();
        $this->assertSame((string) $actual->getKey(), $assignment->service_id);
        $this->assertSame('BC:24:11:0A:0B:0C', $assignment->mac_address);
    }

    /**
     * An address quarantined as ProvisioningTimedOut the way production does
     * it: the allocator reserves it for a create, the create times out, and
     * compensation quarantines it.
     *
     * @return array{0: IpAddress, 1: ProvisioningJob, 2: Service}
     */
    private function timedOutAddress(Subnet $subnet, Customer $customer): array
    {
        $service = Service::factory()->create(['customer_id' => $customer->id, 'kind' => 'vps']);

        $job = ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'kind' => 'create_vps',
            'provider' => 'fake',
            'status' => 'running',
        ]);

        [$reservation] = $this->allocator->reserve($subnet, (string) $job->getKey(), $customer);

        $job->forceFill(['status' => 'needs_review', 'failure_class' => FailureClass::Timeout])->save();
        app(CompensateFailedJob::class)->execute($job, FailureClass::Timeout);

        /** @var IpAddress $address */
        $address = IpAddress::query()->findOrFail($reservation->ip_address_id);

        $this->assertSame(IpAddressStatus::Quarantined, $address->status);
        $this->assertSame(ReleaseReason::ProvisioningTimedOut, $address->quarantine_reason);

        return [$address, $job, $service];
    }

    /**
     * A timed-out address reserved for the platform itself — a job with no
     * customer, the shape `provisioning_jobs.customer_id` is nullable for —
     * on an address a customer held in an earlier cycle.
     *
     * Every allocatable address in a fresh /29 is reserved for a customer's
     * job first and released as JobFailed, the reason that does not
     * quarantine, so whichever address the platform job is given next is
     * certain to have a customer's reservation behind it.
     *
     * @return array{0: IpAddress, 1: Customer, 2: ProvisioningJob}
     */
    private function platformAddressWithACustomerBehindIt(string $cidr, string $gateway): array
    {
        $customer = Customer::factory()->create();
        $subnet = $this->subnet($cidr, $gateway);

        $earlier = ProvisioningJob::factory()->create(['customer_id' => $customer->id, 'status' => 'running']);

        foreach ($this->allocator->reserve($subnet, (string) $earlier->getKey(), $customer, count: 5) as $reservation) {
            $this->allocator->release($reservation, ReleaseReason::JobFailed);
        }

        /*
         * An hour between the two cycles, so that "newest" is settled by
         * created_at rather than by the tie-break. Not load-bearing:
         * created_at is timestamp(0), and a tie within one second falls to
         * the ULID, which is generated from the real clock and in order — so
         * these tests pass without it. It is here so that they do not depend
         * on that.
         */
        $this->travel(1)->hour();

        $platformJob = ProvisioningJob::factory()->create(['customer_id' => null, 'status' => 'running']);
        [$reservation] = $this->allocator->reserve($subnet, (string) $platformJob->getKey());

        $platformJob->forceFill(['status' => 'needs_review', 'failure_class' => FailureClass::Timeout])->save();
        app(CompensateFailedJob::class)->execute($platformJob, FailureClass::Timeout);

        /** @var IpAddress $address */
        $address = IpAddress::query()->findOrFail($reservation->ip_address_id);

        $this->assertSame(ReleaseReason::ProvisioningTimedOut, $address->quarantine_reason);
        $this->assertTrue(
            IpReservation::query()
                ->where('ip_address_id', $address->getKey())
                ->where('customer_id', $customer->getKey())
                ->exists(),
            'The fixture is degenerate: no customer reservation stands behind the platform address.',
        );

        return [$address, $customer, $platformJob];
    }

    /** A live assignment on a fresh address of this subnet, made by commit(). */
    private function liveAssignmentIn(Subnet $subnet, Customer $customer): IpAssignment
    {
        $job = ProvisioningJob::factory()->create(['customer_id' => $customer->id, 'status' => 'running']);
        [$reservation] = $this->allocator->reserve($subnet, (string) $job->getKey(), $customer);

        return $this->allocator->commit($reservation);
    }

    private function subnet(string $cidr, string $gateway): Subnet
    {
        $subnet = Subnet::factory()->forBlock($cidr, gateway: $gateway)->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        return $subnet;
    }

    private function adoptUrl(IpAddress $address): string
    {
        return '/api/admin/infrastructure/ip-addresses/'.$address->getKey().'/adopt';
    }

    private function releaseUrl(IpAddress $address): string
    {
        return '/api/admin/infrastructure/ip-addresses/'.$address->getKey().'/release';
    }

    private function operator(Role $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
