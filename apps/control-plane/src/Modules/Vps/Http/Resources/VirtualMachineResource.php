<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;

/**
 * What a customer may see of their own machine.
 *
 * The interesting half of this class is the list of columns that are on the
 * model and are not here, each for its own reason:
 *
 *  - **provider_id** — the hypervisor's own VMID. It is the handle every
 *    provider call is made with, it is reused when a machine is destroyed, and
 *    it is the one field that makes a support-chat screenshot actionable
 *    against somebody else's machine. The customer's handle is the ULID.
 *  - **node_id / cluster_id** — which physical node a machine sits on is an
 *    operational fact about the platform, and about the other tenants sharing
 *    that node. Publishing it lets a customer work out who they are next to,
 *    and lets anyone who has read a status page work out which customers a
 *    failing node just took down.
 *  - **has_drift / drift_details / last_reconciled_at** — an operator's
 *    worklist. Drift means the platform's belief and the hypervisor disagree;
 *    it is recorded rather than healed precisely because a person has to look,
 *    and surfacing "your machine has 4 vCPU but we think it has 2" to a
 *    customer produces a support ticket about a discrepancy the platform is
 *    already handling.
 *  - **template_id** — the internal catalogue row. os_family and os_version
 *    are what the customer actually asked about.
 *
 * `service_id` IS here: it is the acting customer's own service, it is how a
 * client joins this machine to its invoices and its subscription, and it names
 * nothing outside the account.
 *
 * Addresses arrive as a constructor argument rather than through a relation.
 * IPAM's assignment is polymorphic and Compute may not depend on IPAM, so
 * there is no `$machine->addresses` to lazy-load — which is just as well: a
 * relation here would be a query per row on the list endpoint.
 *
 * @mixin VirtualMachine
 */
final class VirtualMachineResource extends JsonResource
{
    /**
     * @param  list<array{address: string, ip_version: int, is_primary: bool}>  $addresses
     */
    public function __construct(
        VirtualMachine $resource,
        private readonly array $addresses = [],
        private readonly ?VmReinstall $reinstall = null,
        /**
         * Why the operation guard would refuse this machine's service today,
         * or null when nothing is queued, running or stranded on it. Resolved
         * for the whole page in one query ({@see UnresolvedServiceWork}) and
         * handed in, for the same reason the addresses are.
         */
        private readonly ?string $unresolvedWork = null,
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
            'service_id' => $this->service_id,
            'hostname' => $this->hostname,

            /*
             * The lifecycle of the thing the customer bought, and the state of
             * the guest, are two different questions and are answered
             * separately. A service can be active while its guest is stopped —
             * that is a customer who turned their own machine off — and
             * collapsing the two into one "status" would make that look like
             * an outage.
             */
            'service_status' => $this->service?->status->value,
            'power_state' => $this->power_state->value,

            'resources' => [
                'vcpu' => $this->vcpu,
                'memory_mib' => $this->memory_mib,
                'disk_gib' => $this->disk_gib,
            ],

            'os_family' => $this->os_family,
            'os_version' => $this->os_version,

            'addresses' => $this->addresses,

            /*
             * Whether the platform may currently act on this machine, answered
             * by the same facts the endpoints check rather than re-derived by
             * the client. A client that computed this itself would eventually
             * disagree with the API, and disagree in the direction of showing
             * an enabled reinstall button.
             */
            'is_operable' => $this->service?->status->isUsable() === true && $this->existsRemotely(),

            /*
             * Whether each disruptive control would be accepted right now, and
             * if not, why — answered from the same facts the guard refuses on
             * ({@see \Lynomia\Modules\Vps\Domain\Services\VpsOperationGuard}),
             * so the portal never enables a button the API already knows it
             * will answer 409 to. `is_operable` alone was not enough: it
             * says the service is active and the machine exists, and a machine
             * whose last rebuild timed out is both of those and still refused,
             * because a person has to look before anything else touches it.
             *
             * The guard remains the authority. This is what the screen says;
             * the refusal is what the endpoint does.
             */
            'actions' => $this->actions(),

            /*
             * The machine's most recent rebuild, when it has had one.
             *
             * Present so the portal can say what is happening rather than
             * showing a machine that reads "running" while its disk is being
             * replaced. The failure code and message are deliberately not
             * here: they are the platform's own vocabulary for its scheduler
             * and its adapters, and a customer reading "vps.reinstall_storage
             * _unknown" learns nothing they can act on. The state is enough
             * for the portal to say a true sentence in the customer's own
             * language.
             */
            'reinstall' => $this->reinstall === null ? null : [
                'id' => (string) $this->reinstall->getKey(),
                'state' => $this->reinstall->state->value,
                'in_flight' => $this->reinstall->state->isInFlight(),
                'needs_attention' => $this->reinstall->state->needsAttention(),
                // The question a customer actually has when a rebuild goes
                // wrong, answered plainly rather than left to be inferred from
                // a state name.
                'data_destroyed' => $this->reinstall->destroyedData(),
                'requested_at' => $this->reinstall->created_at->toIso8601String(),
                'completed_at' => $this->reinstall->completed_at?->toIso8601String(),
            ],

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{power: bool, reinstall: bool, blocked_reason: string|null}
     */
    private function actions(): array
    {
        $reason = match (true) {
            $this->service?->status->isUsable() !== true => 'service_not_active',
            ! $this->existsRemotely() => 'not_provisioned',
            $this->unresolvedWork !== null => $this->unresolvedWork,
            default => null,
        };

        // Power and reinstall are refused on exactly the same facts today, and
        // are published separately so a client does not have to know that.
        return [
            'power' => $reason === null,
            'reinstall' => $reason === null,
            'blocked_reason' => $reason,
        ];
    }
}
