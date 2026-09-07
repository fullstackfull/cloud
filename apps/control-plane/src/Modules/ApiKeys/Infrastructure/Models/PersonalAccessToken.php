<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A scoped API token for the public customer API.
 *
 * Extends Sanctum's model to add:
 *  - ULID keys, matching the rest of the schema;
 *  - the customer the token acts for, so a token belonging to a user who is a
 *    member of several accounts can never act outside the one it was issued
 *    for;
 *  - a per-token rate limit and CIDR allow-list;
 *  - revocation that is recorded rather than performed by deletion, so the
 *    audit trail survives.
 *
 * @property string $id
 * @property ?string $customer_id
 * @property ?int $rate_limit_per_minute
 * @property ?array<int, string> $allowed_ip_ranges
 * @property ?CarbonImmutable $revoked_at
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'allowed_ip_ranges' => 'array',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Whether the token's CIDR allow-list, if it has one, admits this address.
     *
     * An empty or absent list means "anywhere". A list that is set but cannot
     * be evaluated — no resolvable client address — fails closed: an allow-list
     * that silently stops applying is worse than no allow-list at all.
     */
    public function allowsRequestFrom(?string $ipAddress): bool
    {
        $ranges = $this->allowed_ip_ranges;

        if ($ranges === null || $ranges === []) {
            return true;
        }

        if ($ipAddress === null || $ipAddress === '') {
            return false;
        }

        return IpUtils::checkIp($ipAddress, array_values($ranges));
    }

    public function revoke(string $reason): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => substr($reason, 0, 128),
        ])->save();
    }
}
