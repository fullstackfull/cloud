<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Exceptions\AddressNotAllocatableException;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReservationExpiredException;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * The only place an address changes hands.
 *
 * An IP address is a scarce resource with a hard uniqueness requirement: two
 * services holding one address is not a slow query or a wrong total, it is two
 * customers' traffic arriving at one machine. Every method here therefore runs
 * inside a transaction and re-reads the contended row under a lock; nothing
 * outside this class writes ip_addresses.status.
 *
 * The lifecycle is deliberately three-legged — reserve, commit, release —
 * rather than a single "allocate". Provisioning takes minutes, and the address
 * has to be chosen before the VM is built but must not be committed until the
 * build succeeds, or a failed build leaks addresses out of the pool forever.
 */
final readonly class IpAllocator
{
    /**
     * How long a reservation holds an address by default.
     *
     * This bounds when the reaper starts *looking* at a reservation. It does
     * not, on its own, ever release one — see ReapExpiredReservations.
     */
    private const int FALLBACK_TTL_SECONDS = 3600;

    /**
     * Hold $count addresses from $scope for a job.
     *
     * Idempotent per (job, scope): calling it twice for the same job returns
     * the addresses that job already holds rather than taking more, because a
     * provisioning job that is retried runs this method again from the top.
     *
     * @param  Subnet|IpPool  $scope  A specific subnet, or a pool to pick any of its subnets from.
     * @param  string  $provisioningJobId  The job the addresses are held for; the reaper reads this to
     *                                     find out whether the claim is still worth honouring.
     * @return list<IpReservation>
     *
     * @throws IpPoolExhaustedException
     * @throws AddressNotAllocatableException
     */
    public function reserve(
        Subnet|IpPool $scope,
        string $provisioningJobId,
        ?Customer $customer = null,
        int $count = 1,
        ?int $ttlSeconds = null,
    ): array {
        if ($count < 1) {
            throw new \InvalidArgumentException('At least one address must be requested.');
        }

        $this->assertScopeMayServe($scope, $customer);

        $ttl = $ttlSeconds ?? (int) config('provisioning.reservation_ttl_seconds', self::FALLBACK_TTL_SECONDS);

        return DB::transaction(function () use ($scope, $provisioningJobId, $customer, $count, $ttl): array {
            $subnetIds = $this->subnetIdsFor($scope);

            /*
             * What this job already holds here, before anything new is taken.
             *
             * A provisioning job is retried: the engine puts a failed attempt
             * back on the queue and a second worker runs the same job from the
             * top. Without this lookup that second attempt takes a *second*
             * address, and the first one is stranded — the reaper only
             * releases reservations whose job reached a terminal failure, so
             * once the retry succeeds the abandoned address stays `reserved`
             * for ever. One leaked address per retry, invisible until the
             * subnet runs out.
             *
             * Scoped to the requested subnets rather than to the job alone,
             * because one job legitimately holds several addresses from
             * different places — a public address from one pool and a private
             * one from another — and returning the public reservation to a
             * request for a private address would put the wrong address on
             * the NIC.
             */
            $existing = $this->liveReservationsFor($provisioningJobId, $subnetIds);

            if (count($existing) >= $count) {
                return array_slice($existing, 0, $count);
            }

            $wanted = $count - count($existing);
            $addressIds = $this->lockAvailableAddresses($subnetIds, $wanted);

            if (count($addressIds) < $wanted) {
                /*
                 * Thrown, not returned as null or an empty list. Exhaustion has
                 * to abort the surrounding transaction: a caller that receives
                 * fewer addresses than it asked for and does not notice builds
                 * a machine with no route to the internet, bills for it, and
                 * discovers the problem from the customer.
                 */
                throw $this->exhausted($scope, $count, count($existing) + count($addressIds));
            }

            // One statement for the whole batch: the rows are already locked,
            // and a per-row save() would issue N round trips while holding
            // locks that every other allocator in the fleet is skipping past.
            IpAddress::query()
                ->whereIn('id', $addressIds)
                ->update([
                    'status' => IpAddressStatus::Reserved->value,
                    'updated_at' => now(),
                ]);

            $expiresAt = now()->addSeconds($ttl);

            $reservations = $existing;

            foreach ($addressIds as $addressId) {
                // Individual inserts rather than one bulk insert, so that the
                // live-reservation partial unique index rejects exactly the
                // address that is doubly claimed instead of failing the batch
                // anonymously.
                $reservations[] = IpReservation::create([
                    'ip_address_id' => $addressId,
                    'provisioning_job_id' => $provisioningJobId,
                    'customer_id' => $customer?->getKey(),
                    'expires_at' => $expiresAt,
                ]);
            }

            return $reservations;
        });
    }

    /**
     * Turn a held reservation into a live assignment.
     *
     * @param  ?string  $serviceId  The service the address now belongs to. A string id rather than a
     *                              Service model on purpose: services are owned by another module, and
     *                              IPAM must not take a dependency on it to hand out an address.
     *
     * @throws ReservationExpiredException
     * @throws AddressNotAllocatableException
     * @throws InvalidIpAddressException
     */
    public function commit(
        IpReservation $reservation,
        ?string $serviceId = null,
        ?string $macAddress = null,
        bool $isPrimary = true,
        ?Model $assignable = null,
    ): IpAssignment {
        $mac = $macAddress === null ? null : $this->normaliseMacAddress($macAddress);

        return DB::transaction(function () use ($reservation, $serviceId, $mac, $isPrimary, $assignable): IpAssignment {
            /** @var IpReservation $locked */
            $locked = IpReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());

            if (! $locked->isLive()) {
                throw ReservationExpiredException::released(
                    (string) $locked->getKey(),
                    $locked->released_reason?->value,
                );
            }

            /** @var IpAddress $address */
            $address = IpAddress::query()->lockForUpdate()->findOrFail($locked->ip_address_id);

            if ($address->status !== IpAddressStatus::Reserved) {
                /*
                 * The claim outlived the address. An elapsed window is the
                 * likeliest explanation, so say so — but note that a merely
                 * slow job is not affected: while the reservation is live the
                 * partial unique index guarantees nobody else can hold this
                 * address, so a commit an hour past the TTL still succeeds.
                 * The window bounds when the reaper looks, not when the claim
                 * dies.
                 */
                if ($locked->hasElapsed()) {
                    throw ReservationExpiredException::elapsed(
                        (string) $locked->getKey(),
                        $locked->expires_at->toIso8601String(),
                    );
                }

                throw AddressNotAllocatableException::becauseOfStatus($address->address, $address->status);
            }

            $assignment = IpAssignment::create([
                'ip_address_id' => $address->getKey(),
                'customer_id' => $locked->customer_id,
                'service_id' => $serviceId,
                'assignable_type' => $assignable?->getMorphClass(),
                'assignable_id' => $assignable?->getKey(),
                'is_primary' => $isPrimary,
                'mac_address' => $mac,
                'assigned_at' => now(),
            ]);

            $address->forceFill(['status' => IpAddressStatus::Assigned])->save();

            /*
             * The reservation is closed, not left open. Its job is done — the
             * assignment is now the authoritative live record — and leaving it
             * live would occupy the live-reservation index slot for an address
             * that has moved on, so a later release-and-reserve cycle would
             * collide with a claim nobody is waiting on.
             */
            $locked->forceFill([
                'released_at' => now(),
                'released_reason' => ReleaseReason::Committed,
            ])->save();

            return $assignment;
        });
    }

    /**
     * Give up a reservation without ever having used the address.
     *
     * The address goes straight back to available rather than into quarantine,
     * and the difference from releaseAssignment() is the whole point: this
     * address was chosen but never configured, never announced, never resolved
     * and never sent a packet. It has no history to inherit. Quarantining it
     * would burn a week of a scarce resource to protect against a reputation
     * that cannot exist.
     */
    public function release(IpReservation $reservation, ReleaseReason $reason): IpReservation
    {
        return DB::transaction(function () use ($reservation, $reason): IpReservation {
            /** @var IpReservation $locked */
            $locked = IpReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());

            // Idempotent: the reaper and an operator can arrive at the same
            // reservation at the same time, and the second one must not undo
            // the first one's reason.
            if (! $locked->isLive()) {
                return $locked;
            }

            /** @var IpAddress $address */
            $address = IpAddress::query()->lockForUpdate()->findOrFail($locked->ip_address_id);

            $locked->forceFill([
                'released_at' => now(),
                'released_reason' => $reason,
            ])->save();

            // Only a reserved address returns to the pool. If it is already
            // assigned, something committed it and this reservation row is
            // stale bookkeeping; returning it would hand out a live address.
            if ($address->status === IpAddressStatus::Reserved) {
                $address->forceFill([
                    'status' => IpAddressStatus::Available,
                    'quarantined_until' => null,
                    'quarantine_reason' => null,
                ])->save();
            }

            return $locked;
        });
    }

    /**
     * End a live assignment and put the address into quarantine.
     *
     * Quarantine is not tidiness, it is protection for the *next* customer.
     * A released address keeps arriving at its old destination for days:
     * recursive resolvers hold the old A record until the TTL expires, SPF and
     * allow-lists at third parties still name it, monitoring systems still
     * probe it, and abuse reports about last week's traffic are still being
     * written. Hand it straight to a new customer and they inherit an
     * investigation, a blocklist entry and somebody else's inbound traffic —
     * none of which they can explain or appeal.
     *
     * An address released for abuse sits out several times longer and keeps
     * the reason on the row, because for those the reputation damage is known
     * rather than merely possible, and the reports arrive latest of all.
     */
    public function releaseAssignment(IpAssignment $assignment, ReleaseReason $reason): IpAssignment
    {
        return DB::transaction(function () use ($assignment, $reason): IpAssignment {
            /** @var IpAssignment $locked */
            $locked = IpAssignment::query()->lockForUpdate()->findOrFail($assignment->getKey());

            if (! $locked->isLive()) {
                return $locked;
            }

            /** @var IpAddress $address */
            $address = IpAddress::query()
                ->with('subnet.ipPool')
                ->lockForUpdate()
                ->findOrFail($locked->ip_address_id);

            /** @var IpPool $pool */
            $pool = $address->subnet->ipPool;

            // The row is stamped, never deleted: "who held this address on the
            // 4th of March" has to stay answerable long after the service is
            // gone.
            $locked->forceFill(['released_at' => now()])->save();

            /*
             * Only an address that was actually in service goes to quarantine.
             *
             * An operator can take an address out of circulation while it is
             * still assigned — the block is being renumbered, the upstream has
             * withdrawn the route, the address is being handed back. Writing
             * `quarantined` over that would put it on the sweeper's list, and
             * a week later ReleaseQuarantinedAddresses would return it to the
             * pool: an address the platform no longer routes, handed to a new
             * customer as if it were good. Whatever an operator set stands.
             */
            if ($address->status === IpAddressStatus::Assigned) {
                $address->forceFill([
                    'status' => IpAddressStatus::Quarantined,
                    'quarantined_until' => $pool->quarantineExpiryFor($reason),
                    'quarantine_reason' => $reason,
                ])->save();
            }

            $this->withdrawReverseDns($address);

            return $locked;
        });
    }

    /**
     * Mark the address's PTR record for withdrawal.
     *
     * Quarantine holds the address back so the next customer does not inherit
     * the last one's reputation, and a published PTR is that reputation in its
     * most literal form: until the record is withdrawn, every reverse lookup
     * of the address still answers with the previous customer's hostname —
     * their company name in somebody else's mail headers, their identity in
     * somebody else's traceroute, and a mismatched forward/reverse pair that
     * makes the new customer's mail bounce.
     *
     * Only the intent is recorded here. Publishing and withdrawing PTRs needs
     * a DNS provider client that this module does not yet have, so this leaves
     * a row a reconciler (or an operator) can act on rather than a record that
     * silently stays live.
     */
    private function withdrawReverseDns(IpAddress $address): void
    {
        ReverseDnsRecord::query()
            ->where('ip_address_id', $address->getKey())
            ->whereNot('status', ReverseDnsStatus::Removing->value)
            ->update([
                'status' => ReverseDnsStatus::Removing->value,
                'updated_at' => now(),
            ]);
    }

    /**
     * The live reservations this job already holds inside these subnets.
     *
     * Locked, not merely read: two workers that somehow run the same job at
     * once must not each conclude that the job holds nothing. (The queue's own
     * claim normally prevents that; this makes the outcome safe rather than
     * likely.)
     *
     * @param  list<string>  $subnetIds
     * @return list<IpReservation>
     */
    private function liveReservationsFor(string $provisioningJobId, array $subnetIds): array
    {
        if ($subnetIds === []) {
            return [];
        }

        /** @var list<IpReservation> $reservations */
        $reservations = IpReservation::query()
            ->live()
            ->where('provisioning_job_id', $provisioningJobId)
            ->whereIn(
                'ip_address_id',
                DB::table('ip_addresses')->select('id')->whereIn('subnet_id', $subnetIds),
            )
            // Oldest first, so a job that is topped up keeps handing its
            // caller the same address in the same position on every retry.
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->all();

        return $reservations;
    }

    /**
     * The locking read at the centre of the module.
     *
     * @param  list<string>  $subnetIds
     * @return list<string>
     */
    private function lockAvailableAddresses(array $subnetIds, int $count): array
    {
        if ($subnetIds === []) {
            return [];
        }

        /*
         * SELECT ... FOR UPDATE SKIP LOCKED, and the SKIP LOCKED is not an
         * optimisation.
         *
         * Without it, a second job asking for an address at the same moment
         * blocks on the first job's row lock. When the first commits, the
         * second wakes up, re-reads the row it was queued on — and finds it
         * reserved, because the whole point of the lock was that the first job
         * was changing it. Postgres re-evaluates the WHERE clause after the
         * wait, so the row silently drops out of the result and the second job
         * gets back fewer addresses than it asked for, having waited for the
         * privilege. Under real load, with ten workers on one subnet, they
         * queue behind each other one at a time and allocation throughput
         * collapses to one address per transaction round trip.
         *
         * With SKIP LOCKED the second job never joins that queue: locked rows
         * are simply not candidates, so it takes the next free address and
         * both jobs succeed at the same time. That is the correct semantic as
         * well as the fast one — the jobs do not care *which* address they
         * get, only that it is theirs alone.
         *
         * ORDER BY address is what makes the choice deterministic; note that
         * it orders the text, not the numeric address, so .10 precedes .9.
         * Determinism is what matters here (it keeps two allocators walking
         * the subnet in the same direction, and it matches the partial index
         * on (subnet_id, address) WHERE status = 'available'), not numeric
         * ordering.
         */
        /** @var list<string> $ids */
        $ids = DB::table('ip_addresses')
            ->select('id')
            ->whereIn('subnet_id', $subnetIds)
            ->where('status', IpAddressStatus::Available->value)
            ->orderBy('address')
            ->limit($count)
            ->lock('for update skip locked')
            ->pluck('id')
            ->map(static fn (string $id): string => trim($id))
            ->all();

        return $ids;
    }

    /**
     * Refuse a request that must never be satisfied from this scope at all.
     *
     * Checked before the transaction opens, because neither answer depends on
     * a row that anybody else is contending for.
     *
     * @throws AddressNotAllocatableException
     */
    private function assertScopeMayServe(Subnet|IpPool $scope, ?Customer $customer): void
    {
        if ($customer === null) {
            return;
        }

        $pool = $this->poolOf($scope);

        /*
         * A management address reaches the hypervisor and BMC control planes.
         * Putting one on a customer's NIC does not merely give them an address
         * from the wrong pool: it puts a machine that runs arbitrary customer
         * code on the same layer-3 segment as the interfaces that can power
         * cycle and reinstall every other machine in the rack. The pool's
         * scope is the only thing that records that difference, so it is
         * enforced here rather than left to each caller to remember.
         */
        if (! $pool->scope->isCustomerAllocatable()) {
            throw AddressNotAllocatableException::notCustomerAllocatable($pool->slug, $pool->scope);
        }
    }

    private function poolOf(Subnet|IpPool $scope): IpPool
    {
        return $scope instanceof IpPool ? $scope : $scope->ipPool()->sole();
    }

    /**
     * @return list<string>
     */
    private function subnetIdsFor(Subnet|IpPool $scope): array
    {
        if ($scope instanceof Subnet) {
            /*
             * The pool's own flag counts even when the caller named a subnet.
             * is_active on a pool is an operator's kill switch — the block is
             * being renumbered, handed back to the RIR or moved between
             * datacenters — and a switch that only works when the caller
             * happens to pass the pool is not a switch. Deactivating a pool
             * has to stop allocation from every subnet in it.
             */
            return $scope->is_active && $this->poolOf($scope)->is_active
                ? [(string) $scope->getKey()]
                : [];
        }

        if (! $scope->is_active) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = Subnet::query()
            ->where('ip_pool_id', $scope->getKey())
            ->where('is_active', true)
            // v6 subnets have no address rows to hand out at all: the family
            // is delegated as a prefix per service, so including them here
            // would only widen the scan.
            ->where('ip_version', IpVersion::V4->value)
            ->orderBy('cidr')
            ->pluck('id')
            ->map(static fn (string $id): string => trim($id))
            ->all();

        return $ids;
    }

    private function exhausted(Subnet|IpPool $scope, int $requested, int $found): IpPoolExhaustedException
    {
        return $scope instanceof Subnet
            ? IpPoolExhaustedException::forSubnet((string) $scope->getKey(), $scope->cidr, $requested, $found)
            : IpPoolExhaustedException::forPool((string) $scope->getKey(), $scope->slug, $requested, $found);
    }

    /**
     * A MAC is stored in one shape — upper case, colon separated — so that the
     * same NIC written two ways is not two rows, and so the 17-character
     * column can never be overflowed by a vendor's dash-separated spelling.
     */
    private function normaliseMacAddress(string $macAddress): string
    {
        $candidate = str_replace('-', ':', trim($macAddress));

        if (filter_var($candidate, FILTER_VALIDATE_MAC) === false) {
            throw InvalidIpAddressException::forMacAddress($macAddress);
        }

        return strtoupper($candidate);
    }
}
