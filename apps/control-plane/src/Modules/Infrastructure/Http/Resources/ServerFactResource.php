<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ServerFact;

/**
 * One thing known about a machine, and how it came to be known.
 *
 * `source` is the field that matters on a screen: a serial number the machine
 * reported and one an operator typed are different kinds of knowledge, and an
 * inventory that cannot tell them apart is one where the typed value wins by
 * looking the same.
 *
 * @mixin ServerFact
 */
final class ServerFactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'source' => $this->source->value,
            'observed_at' => $this->observed_at->toIso8601String(),
            'superseded_at' => $this->superseded_at?->toIso8601String(),
        ];
    }
}
