<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * A provider account as the control centre shows it.
 *
 * ---------------------------------------------------------------------------
 * What is here that a plain serialisation would not have
 * ---------------------------------------------------------------------------
 *
 * `is_serving`, because Enabled and working are different facts and a screen
 * that shows only the first sends an operator to the wrong place when a
 * credential was revoked yesterday.
 *
 * `can_test`, because most drivers in the catalogue have an adapter and no
 * connection tester yet. A screen that offers a Test button for all of them
 * teaches operators that the button is broken; one that greys it out and says
 * why is telling the truth about this build.
 *
 * `next_action`, which is a translation key rather than a sentence. The API
 * does not choose a language.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately absent
 * ---------------------------------------------------------------------------
 *
 * Everything that could be used to authenticate as us. The credential's name
 * and state are here so an operator can tell which one is attached and whether
 * it works; its backend reference is not, because that is a path into the
 * secret store and this endpoint is reachable over the network.
 *
 * @mixin ProviderInstance
 */
final class ProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $testers = app(ConnectionTesterFactory::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->value,
            'driver' => $this->driver,
            'environment' => $this->environment->value,
            'state' => $this->state->value,

            // Enabled says an operator switched it on. Serving says work sent
            // here right now would actually arrive.
            'is_serving' => $this->isServing(),
            'can_test' => $testers->handles($this->driver),

            'endpoint' => $this->endpoint,

            'connection' => [
                'state' => $this->connection_state->value,
                'reached' => $this->connection_state->reached(),
                'usable' => $this->connection_state->usable(),
                'detail' => $this->connection_detail,
                'last_tested_at' => $this->last_connection_test_at?->toIso8601String(),
                'last_discovery_at' => $this->last_discovery_at?->toIso8601String(),
            ],

            'readiness' => [
                'state' => $this->readiness->value,
                'blocker' => $this->blocker?->value,
                'next_action' => $this->blocker?->nextAction(),
            ],

            'credential' => $this->whenLoaded('credential', fn (): ?array => $this->credential === null ? null : [
                'id' => $this->credential->id,
                'credential_name' => $this->credential->name,
                'credential_state' => $this->credential->state->value,
                'credential_environment' => $this->credential->environment->value,
            ]),

            'licence' => $this->whenLoaded('licence', fn (): ?array => $this->licence === null ? null : [
                'id' => $this->licence->id,
                'product' => $this->licence->product,
                'licence_state' => $this->licence->state->value,
                'expires_on' => $this->licence->expires_on?->toDateString(),
            ]),

            'server' => $this->whenLoaded('server', fn (): ?array => $this->server === null ? null : [
                'id' => $this->server->id,
                'server_name' => $this->server->name,
                'classification' => $this->server->safety_class->value,
            ]),

            'capabilities' => $this->whenLoaded('capabilities', fn (): array => $this->capabilities
                ->map(fn ($capability): array => [
                    'capability' => $capability->capability,
                    'capability_state' => $capability->state->value,
                    'observed_at' => $capability->observed_at?->toIso8601String(),
                ])
                ->all()),

            'enabled_at' => $this->enabled_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'disabled_reason' => $this->disabled_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
