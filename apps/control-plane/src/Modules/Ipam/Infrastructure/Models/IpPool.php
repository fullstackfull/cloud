<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IpPoolFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;

/**
 * A administratively coherent set of subnets in one datacenter.
 *
 * The pool, not the subnet, owns the quarantine policy: how long a released
 * address must sit out before it may be given to somebody else. It lives here
 * because the answer depends on what the addresses are used for — a private
 * RFC1918 pool has no external reputation to inherit and can recycle in a day,
 * while a public pool that appears in third-party allow-lists needs a week or
 * more.
 *
 * @property string $id
 * @property string $datacenter_id
 * @property string $slug
 * @property IpVersion $ip_version
 * @property IpPoolScope $scope
 * @property bool $is_active
 * @property int $quarantine_days
 */
class IpPool extends Model
{
    /** @use HasFactory<IpPoolFactory> */
    use HasFactory, HasUlids;

    /**
     * How much longer an abuse-released address sits out than a normally
     * released one.
     *
     * Abuse reports, blocklist entries and third-party takedown notices arrive
     * days after the traffic that caused them, and they name the address, not
     * the customer. Multiplying the window is the cheapest way to make sure
     * the reports land while the address is still nobody's.
     */
    public const int ABUSE_QUARANTINE_MULTIPLIER = 4;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ip_version' => IpVersion::class,
            'scope' => IpPoolScope::class,
            'is_active' => 'boolean',
            'quarantine_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<Subnet, $this>
     */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    /**
     * When an address released now for this reason may be handed out again.
     */
    public function quarantineExpiryFor(ReleaseReason $reason): CarbonImmutable
    {
        $days = $this->quarantine_days;

        if ($reason->isAbuse()) {
            $days *= self::ABUSE_QUARANTINE_MULTIPLIER;
        }

        return CarbonImmutable::now()->addDays($days);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
