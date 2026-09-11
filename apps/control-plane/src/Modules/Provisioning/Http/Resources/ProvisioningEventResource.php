<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerFailureReason;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * One thing the platform did, or tried to do, to a customer's service.
 *
 * A provisioning job is the most operationally loaded row in the system, and
 * this resource is the list of what does not leave it. Every omission below is
 * a field that was found on the wrong side of the boundary in the phase 9
 * review or would have been:
 *
 *  - last_error: whatever the provider said. The redactor strips credentials
 *    from it; it cannot strip a node name, a cluster, an internal address or a
 *    stack frame, because those are not secret-shaped. `failure_reason` is the
 *    customer's version, and it is an enum precisely so that no provider
 *    sentence can ever be carried in it.
 *  - payload and result: provider request and response bodies, nested and
 *    written by adapters this module does not control. Redacted on the way in,
 *    and still not a customer document.
 *  - provider: which vendor fulfils a service is a commercial and operational
 *    fact about the platform, not a property of what the customer bought.
 *  - remote_job_id: the provider's own handle for the work. It is the field
 *    that makes a timeout recoverable, and it is only useful to somebody who
 *    can talk to that provider's API.
 *  - attempts, max_attempts, timeout_seconds, next_attempt_at: the engine's
 *    retry machinery. A customer reading "attempt 2 of 3" learns nothing they
 *    can act on and is invited to count down to a promise the platform has not
 *    made.
 *  - correlation_id, service_id, order_id, customer_id: internal joins. The
 *    service is already named by the URL that returned this list.
 *
 * The attempt log is not published at all, for the same reason as last_error:
 * every row of it is a provider's error message and a provider's response
 * metadata. It is the record support reads, not the record a customer does.
 *
 * `id` is published, because it is the one thing a customer can usefully quote
 * to support, and it names a job that is already scoped to their own service.
 *
 * @mixin ProvisioningJob
 */
final class ProvisioningEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = $this->resource->status->customerState();

        return [
            'id' => $this->id,
            // Product terms rather than provider terms — "create_vps", never
            // "clone template 9000 on pve-03". The enum is stated that way for
            // the engine's benefit, and it happens to be exactly what a
            // customer can be told.
            'kind' => $this->kind->value,
            /*
             * The one customer vocabulary, from the one mapping. This list
             * used to speak its own set of words — `completed` here where the
             * operation endpoint said `succeeded`, `under_review` where it
             * said `needs_review` — about the very same job row.
             */
            'state' => $state->value,

            // Whether the platform is still working on this. Asked of the enum
            // that decides, so it cannot disagree with what a worker would do.
            'is_terminal' => $state->isTerminal(),

            'needs_attention' => $state->needsAttention(),

            /*
             * What may be done next, decided by the server. A row in this list
             * is the one place a customer sees a rebuild that stopped, and a
             * screen that derived "offer a retry" from the state itself would
             * eventually offer one beside an operation whose result nobody
             * knows.
             */
            'retry_advice' => $state->retryAdvice()->value,

            'failure_reason' => CustomerFailureReason::for($this->resource->failure_class)?->value,

            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
