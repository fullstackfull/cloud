<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Queries\LiveServerWork;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;

/**
 * What a customer may see of the physical machine they bought.
 *
 * The rule this resource exists to enforce is one sentence: **the customer
 * sees their machine, not where it lives.** A dedicated server row is the most
 * infrastructure-shaped row on the customer surface — it names a datacenter, a
 * rack, a rack unit and one or more management controllers — and every one of
 * those is a fact about the platform's own estate rather than about what was
 * sold.
 *
 * Absent on purpose, and each for its own reason:
 *
 *  - **Everything about the BMC.** The endpoint rows are not loaded, not
 *    counted and not linked. `address` is a host on the management network,
 *    reachable only from inside it, and an out-of-band controller is a
 *    complete second computer with power control and virtual media attached to
 *    this chassis; `credentials_reference` names the configuration key its
 *    password is read from; `last_error` is whatever the controller last said,
 *    which is provider prose nobody has scrubbed for hostnames; and
 *    `firmware_version` is a fleet-wide patch-level disclosure one machine at
 *    a time.
 *  - **`datacenter_id`, `rack_id`, `rack_unit`, `height_units`.** Where the
 *    machine physically is, down to the shelf. A region has a public name and
 *    that is what should eventually be published here; a bare ULID and a rack
 *    position are a floor plan.
 *  - **`customer_id`.** The caller already knows which account they are acting
 *    for. The id buys them nothing but a shape to probe with.
 *  - **`reserved_by_order_id`, `reserved_until`.** The inventory hold that
 *    bought the machine, and a deadline that belongs to a reaper rather than
 *    to a customer.
 *  - **`notes`.** A free-text operations column. Whatever an engineer wrote
 *    there, they did not write it to a customer.
 *  - **`last_seen_at`.** When the platform's discovery sweep last reached the
 *    controller. It is a property of the monitoring, not of the server, and a
 *    customer reading a stale value concludes their machine is down when it
 *    is the poller that is late.
 *
 * `serial` IS published, and is the identifier this surface is built around:
 * it is stable across every rebuild and every reinstall, it is printed on the
 * front of the chassis, and it is what the reinstall endpoint requires as
 * confirmation — a confirmation the customer cannot type unless the platform
 * has told them what it is.
 *
 * `status` is published as the platform's own word rather than translated into
 * a customer vocabulary, because on physical hardware the words already are
 * the customer's: `maintenance` is exactly why a power request is being
 * refused, and inventing a gentler synonym would leave a client unable to
 * explain the 409 it just received.
 *
 * @mixin DedicatedServer
 */
final class DedicatedServerResource extends JsonResource
{
    public function __construct(
        DedicatedServer $resource,
        /**
         * The machine's most recent rebuild.
         *
         * Passed in rather than read through a relation, so the list endpoint
         * resolves every machine's rebuild in one query instead of one per
         * row — the same reason the VPS resource takes its addresses this way.
         */
        private readonly ?DedicatedReinstall $reinstall = null,
        /**
         * The live job against this machine, if any — the fact the operation
         * guard refuses on, resolved for the whole page in one query
         * ({@see LiveServerWork}) and handed in like the reinstall.
         */
        private readonly ?ProvisioningJobKind $liveWork = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // The machine itself, as it would be described on a delivery note.
            'serial' => $this->serial,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,

            'status' => $this->status->value,
            // What the controller last reported the chassis was doing.
            // `unknown` is a real answer and is passed through as one: a
            // machine whose controller did not respond is not a machine that
            // is off, and collapsing the two is how a customer power cycles a
            // host that is mid-install.
            'power_state' => $this->power_state->value,

            // Asked of the enum that decides, so a client's "can I press the
            // button?" cannot drift from what the platform would allow.
            'is_powered_on' => $this->resource->power_state->isOn(),

            /*
             * Whether each disruptive control would be accepted right now,
             * and if not, why — from the same facts
             * {@see \Lynomia\Modules\Dedicated\Domain\Services\DedicatedOperationGuard}
             * refuses on, so the portal never offers a button the API already
             * knows it will answer 409 to. The guard stays the authority; this
             * is what the screen says, the refusal is what the endpoint does.
             */
            'actions' => $this->actions(),

            // The service this machine fulfils, so a client can join it back
            // to what was bought. Within the acting account by construction —
            // it is the service on the customer's own machine.
            'service_id' => $this->service_id,

            /*
             * The service's activation date, not the row's created_at.
             *
             * A dedicated_servers row is stock: it exists before anybody buys
             * it and survives being wiped and re-sold. Publishing its
             * created_at gives a client something it will render as "server
             * created" and a customer will read as "when I got this machine",
             * and on a re-sold chassis it is neither - it is how long the
             * platform has had the hardware, which is nobody's business but
             * the platform's.
             *
             * Null until the service is active, which is honest: a machine
             * being provisioned has no delivery date yet.
             */
            'activated_at' => $this->whenLoaded(
                'service',
                fn (): ?string => $this->resource->service?->activated_at?->toIso8601String(),
                null,
            ),

            /*
             * The machine's most recent rebuild, when it has had one.
             *
             * `data_destroyed` is the field a client reads before saying
             * anything reassuring: it is true from the moment the machine was
             * told to boot into an installer, including when the platform
             * never heard back. The failure code and message are deliberately
             * absent — they are the platform's vocabulary for its own
             * controllers, and a customer reading
             * "dedicated.reinstall_timed_out" learns nothing they can act on.
             */
            'reinstall' => $this->reinstall === null ? null : [
                'id' => (string) $this->reinstall->getKey(),
                'state' => $this->reinstall->state->value,
                'in_flight' => $this->reinstall->state->isInFlight(),
                'needs_attention' => $this->reinstall->state->needsAttention(),
                'data_destroyed' => $this->reinstall->destroyedData(),
                'requested_at' => $this->reinstall->created_at->toIso8601String(),
                'completed_at' => $this->reinstall->completed_at?->toIso8601String(),
            ],

            // Loaded only where the caller asked for one machine; a list of
            // servers does not need every disk in every one of them.
            'components' => ServerComponentResource::collection(
                $this->whenLoaded('components')
            ),
        ];
    }

    /**
     * The guard's two questions, answered for the screen.
     *
     * Power is refused while the machine is not in service or a REINSTALL is
     * live; a reinstall is refused while the machine is not in service or ANY
     * job is live. The two differ on purpose — a power request is how a
     * customer recovers a host that stopped listening, and an unrelated job
     * must not take that away.
     *
     * @return array{power: bool, reinstall: bool, blocked_reason: string|null}
     */
    private function actions(): array
    {
        if ($this->resource->status !== DedicatedServerStatus::Active) {
            return ['power' => false, 'reinstall' => false, 'blocked_reason' => 'server_not_in_service'];
        }

        if ($this->liveWork === ProvisioningJobKind::ReinstallDedicated) {
            return ['power' => false, 'reinstall' => false, 'blocked_reason' => 'reinstall_in_flight'];
        }

        if ($this->liveWork !== null) {
            return ['power' => true, 'reinstall' => false, 'blocked_reason' => 'operation_in_flight'];
        }

        return ['power' => true, 'reinstall' => true, 'blocked_reason' => null];
    }
}
