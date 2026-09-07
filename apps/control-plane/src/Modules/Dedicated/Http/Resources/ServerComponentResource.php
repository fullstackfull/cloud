<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;

/**
 * One part inside the customer's own machine.
 *
 * Published so that "is a disk failing?" has an answer that does not require a
 * support ticket, which on physical hardware is the single most useful thing
 * this API can say.
 *
 * Absent on purpose:
 *
 *  - `attributes`: the jsonb bag discovery writes, straight out of a Redfish
 *    or IPMI inventory. It is a raw provider payload whose shape no code in
 *    this module controls, and it carries the NIC's MAC address — the address
 *    the provisioning VLAN's boot server authorises installs against.
 *  - `serial`: the part's own serial number. It identifies a physical
 *    component that outlives this customer's tenancy on the machine, and an
 *    RMA is a conversation with support rather than a field a client prints.
 *  - `dedicated_server_id`: the machine is already named by the document this
 *    is nested in.
 *  - `health_checked_at`: when discovery last ran is a fact about the
 *    platform's own polling, not about the customer's hardware, and a stale
 *    timestamp invites a customer to conclude their server is unmonitored
 *    when in truth the sweep is on a different schedule.
 *
 * @mixin ServerComponent
 */
final class ServerComponentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this->kind->value,
            'model' => $this->model,
            'quantity' => $this->quantity,
            'health' => $this->health->value,
            // Asked of the enum that decides, so a client's "should I worry?"
            // cannot drift from what the platform would alert on.
            'needs_attention' => $this->resource->health->needsAttention(),
        ];
    }
}
