<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HostingAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;

/**
 * The platform's record of one account on a shared hosting node.
 *
 * The row is written BEFORE the panel is asked to create anything, in the same
 * transaction that commits the node's capacity, and it starts in Pending. That
 * ordering is what makes a lost response survivable: the unique index on
 * (hosting_node_id, username) means a retried job finds its own half-finished
 * work instead of committing a second account's worth of capacity, and a
 * create whose answer never arrived leaves a row to reconcile against rather
 * than an account on a node that nothing in the platform knows about.
 *
 * There is no password column, and there never will be one. The platform
 * cannot return a customer's panel password because it does not keep it; a
 * customer who loses theirs gets a reset. A password column here would be the
 * single highest-value target in the database and would appear in every
 * backup.
 *
 * disk_used_mib and bandwidth_used_mib are readings, not commitments. They are
 * written only from a panel answer that actually contained numbers — see
 * SyncAccountUsage — because writing an empty answer as zero would bill a
 * customer for nothing and lift every quota the platform enforces.
 *
 * @property string $id
 * @property string $hosting_node_id
 * @property ?string $hosting_package_id
 * @property string $customer_id
 * @property ?string $service_id
 * @property string $username
 * @property string $primary_domain
 * @property HostingAccountStatus $status
 * @property ?string $ip_address_id
 * @property ?int $disk_used_mib
 * @property ?int $bandwidth_used_mib
 * @property ?CarbonImmutable $usage_synced_at
 * @property ?SslStatus $ssl_status
 * @property ?CarbonImmutable $ssl_expires_at
 * @property ?CarbonImmutable $suspended_at
 * @property ?string $suspension_reason
 * @property ?CarbonImmutable $terminated_at
 */
class HostingAccount extends Model
{
    /** @use HasFactory<HostingAccountFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HostingAccountStatus::class,
            'ssl_status' => SslStatus::class,
            'disk_used_mib' => 'integer',
            'bandwidth_used_mib' => 'integer',
            'usage_synced_at' => 'immutable_datetime',
            'ssl_expires_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<HostingNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(HostingNode::class, 'hosting_node_id');
    }

    /**
     * @return BelongsTo<HostingPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(HostingPackage::class, 'hosting_package_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<IpAddress, $this>
     */
    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class, 'ip_address_id');
    }

    /**
     * When this account's data may be released.
     *
     * Termination is irreversible, so the window is measured from the moment
     * service stopped rather than from the moment somebody decided to
     * terminate. Most suspensions are billing disputes that end with the
     * customer paying, and the platform cannot tell "cancelled" from "paying
     * on Tuesday" while the dunning run is still going.
     */
    public function retentionReleasesAt(?int $days = null): ?CarbonImmutable
    {
        if ($this->suspended_at === null) {
            return null;
        }

        $days ??= max(0, (int) config('hosting.retention.suspended_days', 30));

        return $this->suspended_at->addDays($days);
    }

    /**
     * Whether the retention window has elapsed and the data may be released.
     */
    public function retentionHasElapsed(?int $days = null): bool
    {
        $releasesAt = $this->retentionReleasesAt($days);

        return $releasesAt === null || $releasesAt->isPast();
    }

    public function isSuspended(): bool
    {
        return $this->status === HostingAccountStatus::Suspended;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOccupyingCapacity(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            static fn (HostingAccountStatus $status): string => $status->value,
            array_values(array_filter(
                HostingAccountStatus::cases(),
                static fn (HostingAccountStatus $status): bool => $status->occupiesNodeCapacity(),
            )),
        ));
    }
}
