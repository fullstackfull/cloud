<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Services\SafetyGate;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;

/**
 * A machine as the control centre shows it.
 *
 * Two things are deliberately here and one is deliberately not.
 *
 * Here: `permits`, so a screen can disable a control and say why rather than
 * offering it and refusing afterwards. And the credential's NAME, so an
 * operator can see which credential is attached without the response carrying
 * anything that could open the machine.
 *
 * Not here: the credential's backend reference. It is a path into the secret
 * store, and a path into the secret store is a map for somebody who has got as
 * far as this endpoint. The model hides it; this never asks for it.
 *
 * @mixin ManagedServer
 */
final class ServerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gate = app(SafetyGate::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'environment' => $this->environment->value,
            'state' => $this->state->value,

            'safety' => [
                'classification' => $this->safety_class->value,
                'allow_reimage' => $this->allow_reimage,
                'reason' => $this->safety_reason,
                'changed_at' => $this->safety_changed_at?->toIso8601String(),
                // What a screen may offer. Advisory: the gate itself is the
                // enforcement, and nothing relies on a button being hidden.
                'permits' => [
                    'read' => $gate->permits($this->safety_class, $this->allow_reimage, InfrastructureAction::Read),
                    'configure' => $gate->permits($this->safety_class, $this->allow_reimage, InfrastructureAction::Configure),
                    'reimage' => $gate->permits($this->safety_class, $this->allow_reimage, InfrastructureAction::Reimage),
                ],
            ],

            'location' => [
                'datacenter_id' => $this->datacenter_id,
                'rack_id' => $this->rack_id,
                'rack_unit' => $this->rack_unit,
                'height_units' => $this->height_units,
            ],

            'hardware' => [
                'vendor' => $this->vendor,
                'model' => $this->model,
                'serial' => $this->serial,
                'asset_tag' => $this->asset_tag,
                'operating_system' => $this->operating_system,
            ],

            'connection' => [
                'state' => $this->connection_state->value,
                'blocker' => $this->connection_state->blocker()?->value,
                'management_address' => $this->management_address,
                'bmc_address' => $this->bmc_address,
                'credential' => $this->whenLoaded('credential', fn (): ?array => $this->credential === null ? null : [
                    'id' => $this->credential->id,
                    'name' => $this->credential->name,
                    'state' => $this->credential->state->value,
                ]),
                'last_tested_at' => $this->last_connection_test_at?->toIso8601String(),
            ],

            'last_discovery_at' => $this->last_discovery_at?->toIso8601String(),
            'last_deployment_at' => $this->last_deployment_at?->toIso8601String(),
            'last_verification_at' => $this->last_verification_at?->toIso8601String(),

            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
