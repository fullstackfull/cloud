<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The receipt for a lifecycle request: what was asked for, and where it stands.
 *
 * A provisioning job row is mostly operator material, so almost none of it is
 * here:
 *
 *  - **payload** — carries the machine's internals and, on a reinstall, the
 *    customer's SSH keys. The redactor already keeps credentials out of it,
 *    but "redacted" is not "publishable".
 *  - **result / remote_job_id** — the hypervisor's own task handle, in
 *    Proxmox's case a UPID naming the node and the API user. It is the single
 *    most useful field an operator has and it describes the platform's
 *    internals, not the customer's machine.
 *  - **last_error / failure_class** — a provider's message, quoted verbatim
 *    from a hypervisor. It routinely names nodes, storage pools and cluster
 *    internals. A customer needs to know that their reboot failed, and that is
 *    what `status` says; why it failed is a support conversation.
 *  - **attempts / max_attempts / timeout_seconds** — retry policy. Publishing
 *    it invites a client to implement its own retry on top, which is how a
 *    timed-out operation gets retried from the outside after the engine
 *    carefully declined to retry it from the inside.
 *
 * `status` is the engine's own vocabulary rather than a simplification.
 * `needs_review` in particular is worth showing as itself: it means a person
 * has to look, and a client that rendered it as "failed" would invite the
 * customer to press the button again — which for a timed-out operation is
 * exactly what must not happen.
 *
 * @mixin ProvisioningJob
 */
final class ProvisioningOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->payload ?? [];

        return [
            'id' => $this->id,
            'service_id' => $this->service_id,

            'kind' => $this->kind->value,

            /*
             * The customer's own verb, echoed from the payload. The kind
             * cannot carry it: `stop` and `shutdown` are one kind, and a
             * client that showed "stop" for a graceful shutdown would be
             * telling the customer the plug was pulled.
             */
            'action' => is_string($payload['power_action'] ?? null) ? $payload['power_action'] : null,

            'status' => $this->status->value,
            'is_settled' => $this->status->isSettled(),
            'needs_attention' => $this->status->needsAttention(),

            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
