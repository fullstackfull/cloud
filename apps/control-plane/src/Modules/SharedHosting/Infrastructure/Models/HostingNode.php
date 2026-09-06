<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HostingNodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;

/**
 * One shared hosting machine, running one control panel.
 *
 * Two different sets of numbers live on this row, and confusing them is the
 * most consequential mistake this module can make:
 *
 *  - disk_total_mib, disk_used_mib, load_average and panel_version are what the
 *    NODE observes, refreshed from the panel by a health sync;
 *  - account_count is what the PLATFORM has committed, written only under the
 *    node's row lock by ReserveHostingNodeCapacity and released by
 *    TerminateHostingAccount.
 *
 * A health sync must never write account_count. The panel does not know about
 * an account that is still being created, so it reports one fewer than exist
 * for as long as a create is in flight — and a scheduler that believed it
 * would place a second account into a slot that is already spoken for.
 *
 * Credentials are NOT on this row. credentials_reference is a config key name;
 * the token it points at is read from configuration at the moment of use. A
 * WHM root API token in the database is that token in every backup, every
 * replica and every support export — and it is root on a machine holding
 * several hundred customers' websites, databases and mail.
 *
 * @property string $id
 * @property string $datacenter_id
 * @property string $slug
 * @property string $hostname
 * @property HostingPanel $panel
 * @property ?string $panel_version
 * @property ?string $api_endpoint
 * @property ?string $credentials_reference
 * @property bool $verify_tls
 * @property HostingNodeStatus $status
 * @property bool $accepts_new_accounts
 * @property bool $panel_licensed
 * @property ?CarbonImmutable $licence_checked_at
 * @property ?string $licence_status
 * @property bool $cloudlinux
 * @property bool $litespeed
 * @property ?int $max_accounts
 * @property int $account_count
 * @property ?int $disk_total_mib
 * @property ?int $disk_used_mib
 * @property ?float $load_average
 * @property ?CarbonImmutable $last_synced_at
 * @property ?string $last_sync_error
 */
class HostingNode extends Model
{
    /** @use HasFactory<HostingNodeFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'panel' => HostingPanel::class,
            'status' => HostingNodeStatus::class,
            'verify_tls' => 'boolean',
            'accepts_new_accounts' => 'boolean',
            'panel_licensed' => 'boolean',
            'cloudlinux' => 'boolean',
            'litespeed' => 'boolean',
            'licence_checked_at' => 'immutable_datetime',
            'max_accounts' => 'integer',
            'account_count' => 'integer',
            // Explicit integer casts because PostgreSQL returns bigint columns
            // as strings through PDO, and capacity arithmetic on a string is a
            // silent float conversion waiting for a big enough node.
            'disk_total_mib' => 'integer',
            'disk_used_mib' => 'integer',
            'load_average' => 'float',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Datacenter, $this>
     */
    public function datacenter(): BelongsTo
    {
        return $this->belongsTo(Datacenter::class);
    }

    /**
     * @return HasMany<HostingAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(HostingAccount::class, 'hosting_node_id');
    }

    /**
     * Whether this node may take a new account at all, before any threshold is
     * considered.
     *
     * The licence is part of the answer and not a separate concern. A node
     * whose cPanel licence has lapsed still answers its API, still lists its
     * accounts and still looks healthy — right up to the point where the panel
     * stops serving. Placing a paid order onto one is how a customer discovers
     * a licensing problem on the platform's behalf.
     */
    public function isSchedulable(): bool
    {
        return $this->status->acceptsNewAccounts()
            && $this->accepts_new_accounts
            && $this->isLicensed();
    }

    /**
     * Whether the panel on this node is licensed to serve.
     *
     * There is no override, no grace flag and no configuration that makes this
     * return true for an unlicensed node. cPanel/WHM, DirectAdmin, CloudLinux
     * and LiteSpeed are commercial products, and the platform's answer to a
     * missing licence is to stop.
     */
    public function isLicensed(): bool
    {
        if (! $this->panel->requiresLicence()) {
            return true;
        }

        return $this->panel_licensed;
    }

    /**
     * Disk in use as a percentage, or null when the node has not reported.
     *
     * Null rather than 0.0 deliberately. Disk is the heaviest term in
     * placement, and a node that failed to report would otherwise present
     * itself as completely empty — which is exactly the node a scheduler
     * would then fill.
     */
    public function diskUsedPercent(): ?float
    {
        if ($this->disk_total_mib === null || $this->disk_total_mib < 1 || $this->disk_used_mib === null) {
            return null;
        }

        return ($this->disk_used_mib / $this->disk_total_mib) * 100;
    }

    public function freeDiskMib(): ?int
    {
        if ($this->disk_total_mib === null || $this->disk_used_mib === null) {
            return null;
        }

        return max(0, $this->disk_total_mib - $this->disk_used_mib);
    }

    /**
     * The account ceiling this node is held to.
     *
     * The row wins over config when it is set, because a node's ceiling is a
     * fact about its hardware — a machine with 8 GiB of RAM cannot run 250
     * accounts' worth of PHP workers however the fleet default is tuned.
     */
    public function accountLimit(): int
    {
        if ($this->max_accounts !== null && $this->max_accounts > 0) {
            return $this->max_accounts;
        }

        return max(1, (int) config('hosting.scheduler.max_accounts_per_node', 250));
    }

    public function freeAccountSlots(): int
    {
        return max(0, $this->accountLimit() - $this->account_count);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSchedulable(Builder $query): Builder
    {
        return $query
            ->where('status', HostingNodeStatus::Active->value)
            ->where('accepts_new_accounts', true);
    }
}
