<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * Addresses a timed-out build left in quarantine, and the two ways an operator
 * ends one (F-34).
 *
 * A timeout quarantine does not end on a clock. The platform stopped waiting,
 * the provider may not have, and a week later it still does not know whether
 * a machine was built with the address on it — so ReleaseQuarantinedAddresses
 * skips these rows, and ReleaseReason::requiresOperatorClearance() says who
 * ends them instead: an operator who has looked at the provider, with one of
 * two outcomes. Before this controller neither outcome had a route and no
 * admin route named an address, so every timeout took an address out of a
 * finite pool for good, visibly (`ipam:capacity` counts it) and with nothing
 * to press.
 *
 * The list is here because "can never be cleared" includes "cannot be found":
 * clearance is by address, and nothing else on this surface names one. It is
 * the one piece the enum's docblock implies rather than names. It lists only
 * the quarantines an operator may clear.
 *
 * Adoption keeps the address away from the pool for ever by recording the
 * assignment that was already true; release hands it back.
 *
 * There is no way back. `releaseAssignment()` has one production caller,
 * `DestroyVpsHandler`, which finds assignments by
 * `assignable_type`/`assignable_id`, and so does the only other path that ends
 * one — `holdAssignmentsOf()`, called by `DecommissionDedicatedServer`.
 * Adoption writes neither, because the only surface that calls
 * `adoptQuarantinedAddress()` — this one — names no machine: it takes none,
 * and for a timed-out VPS there is none to take — `AdoptOrphanResource`
 * creates no machine row to point at. An adopted address leaves the pool
 * permanently, and no surface on this platform can return it.
 *
 * Both acts are assertions about the provider, so the evidence the operator
 * looked at is required and lands in the audit trail, in the same
 * transaction as the act. Both refuse (409) anything that is not a timeout
 * quarantine; IpAllocator::lockForClearance() says why.
 */
final class IpamQuarantineController
{
    use ListsAcrossTenants;

    /**
     * The addresses waiting for a person.
     *
     * Each row names the job the timeout closed and the customer it was held
     * for — the reservation read by the rule lastReservationFor() applies,
     * eager-loaded, so a whole page's reservations are one query rather than
     * one per row.
     * `ends_on_a_clock` is there because the row also carries a
     * `quarantined_until`, which every quarantine is stamped with and which
     * nothing will act on for these; it says so next to the date.
     */
    public function index(Request $request): JsonResponse
    {
        $addresses = IpAddress::query()
            ->where('status', IpAddressStatus::Quarantined->value)
            ->whereIn('quarantine_reason', ReleaseReason::requiringOperatorClearance())
            ->with([
                // lastReservationFor()'s rule: newest by created_at then id, unfiltered.
                'reservations' => static fn (HasMany $query): HasMany => $query->orderByDesc('created_at')->orderByDesc('id'),
            ])
            // Stamped at the timeout as the pool's window from then, so within
            // a pool this is the order they timed out in.
            ->orderBy('quarantined_until')
            ->orderBy('id')
            ->paginate($this->perPage($request));

        return $this->paginated($addresses, static function (IpAddress $address): array {
            /** @var IpReservation|null $closed */
            $closed = $address->reservations->first();

            return [
                'id' => $address->id,
                'address' => $address->address,
                'subnet_id' => $address->subnet_id,
                'quarantine_reason' => $address->quarantine_reason?->value,
                'quarantined_until' => $address->quarantined_until?->toIso8601String(),
                'ends_on_a_clock' => $address->quarantine_reason?->requiresOperatorClearance() !== true,
                'provisioning_job_id' => $closed?->provisioning_job_id,
                'customer_id' => $closed?->customer_id,
                'timed_out_at' => $closed?->released_at?->toIso8601String(),
            ];
        });
    }

    /**
     * The machine exists: keep the address with it.
     *
     * The service defaults to the one the timed-out job was building, and the
     * operator may name another — that is the value most likely to be wrong
     * at exactly this moment. The NIC is optional and is the one fact about
     * the address the platform never heard back; a customer's address list
     * publishes it as their own.
     */
    public function adopt(Request $request, string $address): JsonResponse
    {
        $found = IpAddress::query()->findOrFail($address);

        $validated = $request->validate([
            'evidence' => ['required', 'string', 'min:3', 'max:1000'],
            'service_id' => ['sometimes', 'nullable', 'ulid', 'exists:services,id'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $closed = $this->lastReservationFor($found);
        $job = $closed?->provisioning_job_id === null
            ? null
            : ProvisioningJob::query()->find($closed->provisioning_job_id);

        $serviceId = array_key_exists('service_id', $validated) ? $validated['service_id'] : $job?->service_id;

        /** @var IpAssignment $assignment */
        $assignment = app(RecordActAtomically::class)->execute(
            act: static fn (): IpAssignment => app(IpAllocator::class)->adoptQuarantinedAddress(
                address: $found,
                serviceId: $serviceId,
                macAddress: $validated['mac_address'] ?? null,
            ),
            describe: static fn (IpAssignment $assignment): AuditedAct => new AuditedAct(
                action: AuditAction::QuarantinedAddressAdopted,
                subject: $found,
                // The allocator's answer, so the trail and the assignment it
                // describes cannot disagree about who holds the address.
                customerId: $assignment->customer_id,
                context: [
                    'evidence' => $validated['evidence'],
                    'address' => $found->address,
                    'provisioning_job_id' => $closed?->provisioning_job_id,
                    'assignment_id' => $assignment->id,
                    'service_id' => $assignment->service_id,
                    'mac_address' => $assignment->mac_address,
                ],
            ),
        );

        return response()->json([
            'data' => [
                'id' => $found->id,
                'address' => $found->address,
                'status' => $found->refresh()->status->value,
                'assignment_id' => $assignment->id,
                'customer_id' => $assignment->customer_id,
                'service_id' => $assignment->service_id,
                'mac_address' => $assignment->mac_address,
            ],
        ]);
    }

    /**
     * The machine demonstrably does not exist: give the address back.
     *
     * The reason, ReleaseReason::CLEARED_BY_HAND, is recorded here and only
     * here: no column carries it (IpAllocator::releaseQuarantinedAddress()
     * says why).
     */
    public function release(Request $request, string $address): JsonResponse
    {
        $found = IpAddress::query()->findOrFail($address);

        $validated = $request->validate([
            'evidence' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $closed = $this->lastReservationFor($found);

        /** @var IpAddress $released */
        $released = app(RecordActAtomically::class)->execute(
            act: static fn (): IpAddress => app(IpAllocator::class)->releaseQuarantinedAddress($found),
            describe: static fn (IpAddress $released): AuditedAct => new AuditedAct(
                action: AuditAction::QuarantinedAddressReleased,
                subject: $released,
                customerId: $closed?->customer_id,
                context: [
                    'evidence' => $validated['evidence'],
                    'address' => $released->address,
                    'release_reason' => ReleaseReason::CLEARED_BY_HAND->value,
                    'provisioning_job_id' => $closed?->provisioning_job_id,
                ],
            ),
        );

        return response()->json([
            'data' => [
                'id' => $released->id,
                'address' => $released->address,
                'status' => $released->status->value,
            ],
        ]);
    }

    /**
     * The reservation a timeout closed, which names the job and the customer
     * the audit entry records.
     *
     * Newest by `created_at` then `id`, unfiltered — the same rule as
     * IpAllocator::customerWhoWasHolding() and as the eager load in index(),
     * and it must stay the same rule in all three. Filtering to reservations
     * that name a customer here would walk past a platform job's reservation
     * to an older customer's, and the trail would name a customer the
     * address was never held for this time.
     */
    private function lastReservationFor(IpAddress $address): ?IpReservation
    {
        /** @var IpReservation|null $reservation */
        $reservation = IpReservation::query()
            ->where('ip_address_id', $address->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $reservation;
    }
}
