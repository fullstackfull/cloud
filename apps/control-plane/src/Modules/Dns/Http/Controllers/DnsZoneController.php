<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dns\Application\Actions\ClaimZone;
use Lynomia\Modules\Dns\Application\Actions\ReleaseZone;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Http\Requests\ClaimZoneRequest;
use Lynomia\Modules\Dns\Http\Requests\ReleaseZoneRequest;
use Lynomia\Modules\Dns\Http\Resources\DnsZoneResource;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * The zones an account holds.
 *
 * **Scoped, not checked.** Every zone is reached through a `where` on the
 * acting customer, so a zone id from another account is a 404 rather than a
 * 403 — there is nothing here to enumerate into a confirmation that somebody
 * else holds a particular domain.
 *
 * **Claiming is not verifying.** Nothing on this surface establishes that the
 * account owns the domain, and nothing pretends to. See {@see ClaimZone} and
 * docs/dns.md.
 */
final class DnsZoneController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly ClaimZone $claim,
        private readonly ReleaseZone $release,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $zones = DnsZone::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->where('state', '!=', DnsState::Deleted->value)
            ->withCount('liveRecords')
            ->orderBy('name')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => DnsZoneResource::collection($zones),
            'meta' => ['total' => $zones->count()],
        ]);
    }

    public function show(Request $request, string $zone): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        return (new DnsZoneResource($this->scoped($zone)))->response();
    }

    public function store(ClaimZoneRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $user = $request->user();

        $claimed = app(RecordActAtomically::class)->execute(
            fn () => $this->claim->execute(
                customer: $this->actingCustomer->get(),
                name: (string) $request->input('name'),
                actor: $user instanceof User ? $user : null,
                serviceId: $this->ownServiceId($request->input('service_id')),
            ),
            fn (DnsZone $zone) => new AuditedAct(
                action: AuditAction::DnsZoneCreated,
                subject: $zone,
                customerId: (string) $zone->customer_id,
                context: ['zone' => $zone->name, 'service_id' => $zone->service_id],
            ),
        );

        /*
         * Re-read, because the publish runs as soon as the transaction
         * commits and the in-memory row still says `pending`. On a deployment
         * with a real queue this will usually *still* say pending, which is
         * the honest answer — the difference is that it is read from the
         * database rather than assumed.
         */
        return (new DnsZoneResource($claimed->refresh()->loadCount('liveRecords')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Give the domain up.
     *
     * The typed name is compared here, before anything is read about the zone,
     * and with `hash_equals` so the comparison cannot be shortened. It is not a
     * lookup: the id in the path already says which zone. It is evidence that
     * a person read the screen and knows which domain stops resolving.
     */
    public function destroy(ReleaseZoneRequest $request, string $zone): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($zone);

        if (! hash_equals(strtolower($found->name), $request->confirmation())) {
            throw DnsRefusedException::confirmationDoesNotMatch();
        }

        $released = app(RecordActAtomically::class)->execute(
            fn () => $this->release->execute($found),
            fn (DnsZone $marked) => new AuditedAct(
                action: AuditAction::DnsZoneDeleted,
                subject: $marked,
                customerId: (string) $marked->customer_id,
                context: ['zone' => $marked->name],
            ),
        );

        return (new DnsZoneResource($released->refresh()->loadCount('liveRecords')))->response();
    }

    private function scoped(string $zone): DnsZone
    {
        /** @var DnsZone $found */
        $found = DnsZone::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->where('state', '!=', DnsState::Deleted->value)
            ->withCount('liveRecords')
            ->whereKey($zone)
            ->firstOrFail();

        return $found;
    }

    /**
     * A service id only if this account holds it.
     *
     * A zone pointed at somebody else's service would put that service's id on
     * an account that cannot see it, and every screen joining the two would
     * quietly show one account a fact about another.
     */
    private function ownServiceId(mixed $serviceId): ?string
    {
        if (! is_string($serviceId) || $serviceId === '') {
            return null;
        }

        $owned = Service::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->whereKey($serviceId)
            ->exists();

        return $owned ? $serviceId : null;
    }
}
