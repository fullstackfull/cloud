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
 *    what `state` says; why it failed is a support conversation.
 *  - **attempts / max_attempts / timeout_seconds** — retry policy. Publishing
 *    it invites a client to implement its own retry on top, which is how a
 *    timed-out operation gets retried from the outside after the engine
 *    carefully declined to retry it from the inside.
 *
 * ---------------------------------------------------------------------------
 * One vocabulary, shared through the enum rather than through this class
 * ---------------------------------------------------------------------------
 *
 * This used to publish `status` straight off the row — the engine's own word,
 * chosen to say which worker may touch the job next. A customer read `queued`
 * from here, `scheduled` from the service event list, and `queued` again from
 * the operation endpoint, about one reboot.
 *
 * Now it publishes the canonical customer state, and it gets it from
 * `ProvisioningJobStatus::customerState()` — the one mapping in the platform.
 * It cannot get it from `CustomerOperationResource`, which publishes the same
 * words on `GET /operations/{operation}`: the layering gate forbids one
 * module reaching into another's HTTP surface, and a receipt issued by the
 * VPS endpoints belongs to the VPS module. So the two classes share the
 * vocabulary rather than the code, and a contract test asserts that the state
 * this receipt reports is the state the operation endpoint reports for the
 * same job.
 *
 * `retry_advice` is published for the same reason it is published there: a
 * screen must never derive what may be done next from the state it happens to
 * be looking at.
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
        /** @var ProvisioningJob $job */
        $job = $this->resource;

        /** @var array<string, mixed> $payload */
        $payload = $job->payload ?? [];

        $state = $job->status->customerState();

        return [
            'id' => $job->id,
            'service_id' => $job->service_id,

            'kind' => $job->kind->value,

            /*
             * The customer's own verb, echoed from the payload. The kind
             * cannot carry it: `stop` and `shutdown` are one kind, and a
             * client that showed "stop" for a graceful shutdown would be
             * telling the customer the plug was pulled.
             */
            'action' => is_string($payload['power_action'] ?? null) ? $payload['power_action'] : null,

            'state' => $state->value,
            'is_terminal' => $state->isTerminal(),
            'needs_attention' => $state->needsAttention(),
            'retry_advice' => $state->retryAdvice()->value,

            'requested_at' => $job->created_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
        ];
    }
}
