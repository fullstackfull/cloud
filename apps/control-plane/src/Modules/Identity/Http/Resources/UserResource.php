<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'phone' => $this->phone,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Platform permissions, so the SPA can hide affordances the user
            // cannot use. The server still authorises every request: this list
            // is a convenience, never the enforcement point.
            'permissions' => $this->whenLoaded(
                'permissions',
                fn (): array => $this->getAllPermissions()->pluck('name')->all(),
                fn (): array => $this->getAllPermissions()->pluck('name')->all(),
            ),

            'customers' => CustomerResource::collection($this->whenLoaded('customers')),
        ];
    }
}
