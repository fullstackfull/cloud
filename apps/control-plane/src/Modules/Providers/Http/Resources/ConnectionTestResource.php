<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Providers\Infrastructure\Models\ConnectionTest;

/**
 * What a connection test found, step by step.
 *
 * The steps are the point. A result alone says "auth failed"; the steps say
 * that TCP and TLS succeeded first, which tells an operator the credential is
 * wrong rather than the network — a different afternoon entirely.
 *
 * `next_action` is here so the screen never has to decide what a blocker means.
 * The enum knows, one place, and both the API and the UI read it from there.
 *
 * Nothing in `steps` was ever the thing that was sent: a step records that
 * authentication was attempted and rejected, never with what.
 *
 * @mixin ConnectionTest
 */
final class ConnectionTestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'result' => $this->result->value,
            'reached' => $this->result->reached(),
            'usable' => $this->result->usable(),
            'blocker' => $this->result->blocker()?->value,
            'next_action' => $this->result->blocker()?->nextAction(),
            'steps' => $this->steps,
            'detail' => $this->detail,
            'tested_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
