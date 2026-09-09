<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Providers\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;

/**
 * A credential as the control centre shows it — which is everything about it
 * except where it is and what it is.
 *
 * `backend_reference` is hidden on the model and not read here. `present`
 * answers the question a screen actually has — "does the controller have this
 * yet" — through a method that returns a boolean and never holds the value.
 *
 * `masked_hint` is at most four characters of a public identifier the operator
 * chose to record. It exists so two credentials can be told apart on a screen
 * and is never derived from the secret.
 *
 * @mixin CredentialReference
 */
final class CredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $secrets = app(SecretResolver::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'environment' => $this->environment->value,
            'backend' => $this->backend,
            'state' => $this->state->value,
            'usable' => $this->state->usable(),
            'present' => $secrets->exists($this->backend, $this->backend_reference),
            'masked_hint' => $this->masked_hint,

            'usage' => [
                'providers' => (int) ($this->provider_instances_count ?? 0),
                'servers' => (int) ($this->servers_count ?? 0),
            ],

            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'rotated_at' => $this->rotated_at?->toIso8601String(),
            'rotates_at' => $this->rotates_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
