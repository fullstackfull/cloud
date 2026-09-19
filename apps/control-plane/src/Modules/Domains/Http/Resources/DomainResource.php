<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Domains\Domain\DTOs\RedemptionAnswer;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\RedemptionSupport;
use Lynomia\Modules\Domains\Domain\Services\RedemptionAvailability;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;

/**
 * One name, as the account holding it may see it.
 *
 * `is_expiring` is computed here from the date rather than read from a stored
 * flag, because there is no stored flag: an "expiring" column would be a
 * second copy of `expires_at` that is wrong for as long as the sweep
 * maintaining it is late, and the symptom would be a customer not warned.
 *
 * Deliberately absent: the registrar's name and its reference, and the
 * contacts. The first two are the platform's business arrangement; the
 * contacts are personal data with their own endpoint and their own permission.
 *
 * @mixin Domain
 */
final class DomainResource extends JsonResource
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
            'state' => $this->state->value,

            // The four questions an account has about a name: is it mine, is
            // it working, is it going, and does somebody need to look at it.
            'is_held' => $this->state->isHeld(),
            'is_manageable' => $this->state->isManageable(),
            'is_renewable' => $this->state->isRenewable(),
            'is_redeemable' => $this->state->isRedeemable(),
            'needs_attention' => $this->state->needsAttention(),

            // Only for a name in redemption: whether it can be recovered here,
            // why not if not, the catalogue price, and where the last attempt
            // stands. Null for every other state, so a screen cannot offer a
            // recovery for a name that does not need one.
            'redemption' => $this->redemption(),

            'term_years' => $this->term_years,
            'auto_renew' => $this->auto_renew,
            'transfer_locked' => $this->transfer_locked,
            'nameservers' => $this->nameservers ?? [],
            'dns_zone_id' => $this->dns_zone_id,

            'registered_at' => $this->registered_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expiring' => $this->isExpiringWithin(
                (int) config('domains.renewal.warn_days', 45),
            ),

            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function redemption(): ?array
    {
        if (! $this->state->isRedeemable() && ! $this->latestRedemption() instanceof DomainOperation) {
            return null;
        }

        $tld = DomainTld::query()->where('tld', $this->tld)->first();
        $answer = $tld instanceof DomainTld
            ? app(RedemptionAvailability::class)->forTld($tld)
            : RedemptionAnswer::unavailable(RedemptionSupport::BlockedConfiguration, 'This namespace is not sold here.');
        $attempt = $this->latestRedemption();

        return [
            'support' => $answer->support->value,
            'reason' => $answer->reason,
            'currency' => $answer->price?->currency(),
            'price_minor' => $answer->price?->minorUnits(),
            'attempt' => $attempt === null ? null : [
                'id' => $attempt->id,
                'state' => $attempt->state->value,
                'invoice_id' => $attempt->invoice_id,
                'needs_attention' => $attempt->state->needsAttention(),
                'completed_at' => $attempt->completed_at?->toIso8601String(),
            ],
        ];
    }

    private function latestRedemption(): ?DomainOperation
    {
        /** @var DomainOperation|null $latest */
        $latest = $this->operations()
            ->where('kind', DomainOperationKind::Redeem->value)
            ->latest('created_at')
            ->first();

        return $latest;
    }
}
