<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainQuote;

/**
 * A price the platform will honour, and the id that proves it.
 *
 * Deliberately absent: `cost_minor`, `provider`, and `provider_reference`.
 * What this platform pays its registrar is its own business arrangement, and
 * publishing the wholesale price beside the retail one on the checkout screen
 * would put the margin on every customer's screen.
 *
 * `expires_at` is published because a customer whose quote goes stale between
 * the search and the payment deserves to know why they are being asked again,
 * rather than seeing a price change with no explanation.
 *
 * @mixin DomainQuote
 */
final class DomainQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tld' => $this->tld,
            'operation' => $this->operation->value,
            'term_years' => $this->term_years,
            'premium' => $this->premium,
            'currency' => $this->currency,
            'price_minor' => $this->price_minor,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
