<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerProvisioningEventState;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The receipt for a reinstall the platform has accepted.
 *
 * A provisioning job is the most operationally loaded row in the system, and
 * this resource is the short list of what leaves it. Everything else stays
 * behind, and for the same reasons the Provisioning module's own event
 * resource states at length: `last_error` is provider prose carrying node
 * names and addresses that no redactor can recognise as sensitive; `payload`
 * and `result` are provider request and response bodies; `provider` is which
 * controller protocol the platform speaks to its own hardware; `attempts` and
 * `max_attempts` invite a customer to count down to a promise nobody made;
 * `remote_job_id`, `correlation_id`, `customer_id` and `order_id` are internal
 * joins.
 *
 * The state is published through the customer-facing vocabulary rather than as
 * the engine's raw status, so that a job parked for a person reads
 * `under_review` — the honest answer to "what is happening to my server" —
 * instead of a word that means something else to the scheduler.
 *
 * `id` is published because it is the one thing a customer can usefully quote
 * to support, and it names a job already scoped to their own machine.
 *
 * @mixin ProvisioningJob
 */
final class ReinstallRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => CustomerProvisioningEventState::for($this->resource->status)->value,
            // Whether the platform is still working on this. Asked of the enum
            // that decides, so it cannot disagree with what a worker would do.
            'is_settled' => $this->resource->status->isSettled(),
            'requested_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
