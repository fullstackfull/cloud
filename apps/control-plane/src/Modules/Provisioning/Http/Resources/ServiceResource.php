<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Provisioning\Application\Queries\CustomerServices;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Provisioning\Infrastructure\Queries\ServiceIdentities;

/**
 * What a customer may see of a service they bought.
 *
 * The service row is the provider-agnostic half of the platform by design, so
 * most of what is dangerous lives on the fulfilment side — the virtual
 * machine, the hosting account, the provisioning job — and is not reachable
 * from here at all. What is left to get right is the state.
 *
 * `state` is published; `status` is not. They are nearly the same word for six
 * of the seven cases, and the seventh is the point: a service whose build
 * timed out sits in `provisioning` or `failed` with a job nobody will retry
 * until a person has looked, and `under_review` is the honest answer to "what
 * is happening to my server". Publishing the raw column alongside would put
 * two answers in one document and guarantee that a client renders the wrong
 * one.
 *
 * Absent on purpose, and each for its own reason:
 *
 *  - customer_id: the caller already knows which account they are acting for.
 *    The id buys them nothing but a shape to probe with.
 *  - region_id: an internal identifier for a geography that no customer-facing
 *    endpoint resolves. A region has a public slug, and that is what should
 *    eventually be published here; a bare ULID is a field a client can only
 *    print.
 *  - anything about fulfilment — the node, the cluster, the hypervisor, the
 *    provider's own id for the machine. None of it is on this row, and none of
 *    it should be reached through this row: what the customer bought and what
 *    happens to be running it are separate documents on purpose.
 *
 * `resources` is the entitlement snapshotted from the plan at purchase, which
 * is the substance of what was bought and is already published by the
 * catalogue. It is the plan's shape, not the machine's, so a later plan edit
 * cannot make this row describe something the customer did not buy.
 *
 * @mixin Service
 */
final class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'label' => $this->label,

            /*
             * The name the customer uses for the thing that was created: the
             * hostname, the primary domain, the serial.
             *
             * The label above comes from the catalogue and is the same string
             * for everyone who bought that plan, which is why the services
             * index could not tell two servers apart. Resolved through
             * ServiceIdentities, in one query per kind for a whole page, and
             * null while the service is still being created — "being created"
             * is true and useful; a fabricated hostname is not.
             */
            'identity' => ServiceIdentities::identityOf($this->resource),

            /*
             * The thing that fulfils this service: its family and its own id.
             *
             * Published as a handle rather than as a path, because routes
             * belong to the portal and not to the API — the same contract the
             * notification inbox uses, so one map in the client turns either
             * into a destination. Null while nothing has been created yet.
             */
            'resource' => ServiceIdentities::handleOf($this->resource),

            'state' => $this->customerState()->value,
            // Asked of the enum that decides, so a client's "can I use this?"
            // cannot drift away from what the platform would actually allow.
            'is_usable' => $this->resource->isUsable(),

            'resources' => $this->resources,

            // The catalogue plan and the customer's own order line and
            // subscription, so a client can link a service back to what bought
            // it. All three are within the acting account or the public
            // catalogue; none is another tenant's.
            'plan_id' => $this->plan_id,
            'order_id' => $this->order_id,
            'subscription_id' => $this->subscription_id,

            'activated_at' => $this->activated_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),

            /*
             * The date the data behind this service is destroyed, and why it
             * is going. Published because a customer who cancelled has one
             * question left about the thing they cancelled, and "when do I
             * lose it" is it — a portal that knows the date and does not show
             * it is keeping a deadline to itself.
             */
            'retention_ends_at' => $this->retention_ends_at?->toIso8601String(),
            'ended_reason' => $this->ended_reason,
            'terminated_at' => $this->terminated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The single word this service is described by.
     *
     * Whether any of the service's provisioning work is still waiting on a
     * person arrives as a counted subquery column rather than a loaded
     * relation — see CustomerServices — so a service fetched some other way
     * simply has no opinion here and falls back to its own status. That
     * degrades to the truthful-but-less-specific answer rather than to a wrong
     * one.
     *
     * Cast through int rather than a bare `(bool)`: the alias is an aggregate,
     * and PDO hands aggregates back as strings on more than one driver — where
     * `(bool) '0'` is false but `(bool) 'f'` would not be.
     */
    private function customerState(): CustomerServiceState
    {
        $pending = $this->resource->getAttribute(CustomerServices::REVIEWS_PENDING);

        return CustomerServiceState::for(
            $this->resource->status,
            is_numeric($pending) && (int) $pending > 0,
        );
    }
}
