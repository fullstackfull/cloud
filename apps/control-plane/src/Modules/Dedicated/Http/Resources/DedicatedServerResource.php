<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;

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
            'hardware_profile' => $this->hardware_profile,

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

            // The service this machine fulfils, so a client can join it back
            // to what was bought. Within the acting account by construction —
            // it is the service on the customer's own machine.
            'service_id' => $this->service_id,

            'created_at' => $this->created_at?->toIso8601String(),

            // Loaded only where the caller asked for one machine; a list of
            // servers does not need every disk in every one of them.
            'components' => ServerComponentResource::collection(
                $this->whenLoaded('components')
            ),
        ];
    }
}
