<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Models;

use Database\Factories\HostingPackageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;

/**
 * The platform's copy of a package that exists on the panels.
 *
 * panel_package_name is the load-bearing column: it is what WHM's createacct
 * and DirectAdmin's CMD_API_ACCOUNT_USER are given, and the quotas here are a
 * copy of what that package defines on the node. The copy exists so that the
 * scheduler can reason about how much disk an account will eventually want
 * without asking every node about every package on the hot path of an order.
 *
 * The copy is never authoritative over the panel. An account is created
 * against the package NAME, so a quota edited on the panel and not here makes
 * placement slightly wrong; a create that spelled the quotas out inline
 * instead would make the customer's account disagree with the package they are
 * billed for, which is worse and much harder to notice.
 *
 * The cpu/memory/io/process limits are only enforced by the kernel where
 * CloudLinux is licensed and installed. Recorded here regardless, because the
 * platform states what a package promises and then says plainly whether a
 * given node can enforce it — rather than implying isolation it does not have.
 *
 * @property string $id
 * @property ?string $plan_id
 * @property string $slug
 * @property string $panel_package_name
 * @property ?int $disk_quota_mib
 * @property ?int $bandwidth_quota_mib
 * @property ?int $max_addon_domains
 * @property ?int $max_subdomains
 * @property ?int $max_databases
 * @property ?int $max_email_accounts
 * @property ?int $cpu_limit_percent
 * @property ?int $memory_limit_mib
 * @property ?int $io_limit_kbps
 * @property ?int $process_limit
 * @property ?int $entry_process_limit
 * @property bool $is_active
 */
class HostingPackage extends Model
{
    /** @use HasFactory<HostingPackageFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disk_quota_mib' => 'integer',
            'bandwidth_quota_mib' => 'integer',
            'max_addon_domains' => 'integer',
            'max_subdomains' => 'integer',
            'max_databases' => 'integer',
            'max_email_accounts' => 'integer',
            'cpu_limit_percent' => 'integer',
            'memory_limit_mib' => 'integer',
            'io_limit_kbps' => 'integer',
            'process_limit' => 'integer',
            'entry_process_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<HostingAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(HostingAccount::class, 'hosting_package_id');
    }

    /**
     * Whether this package promises limits that only CloudLinux can enforce.
     *
     * Used to tell a customer the truth about what they bought rather than to
     * silently place them somewhere the promise does not hold.
     */
    public function requiresKernelIsolation(): bool
    {
        return $this->cpu_limit_percent !== null
            || $this->memory_limit_mib !== null
            || $this->io_limit_kbps !== null
            || $this->process_limit !== null
            || $this->entry_process_limit !== null;
    }

    /**
     * Disk the node must be able to give this account, in MiB.
     *
     * An unlimited package returns null, which the scheduler treats as "cannot
     * be sized" rather than "needs nothing": an unlimited account on a node
     * with 2 GiB free is a node that fills next week.
     */
    public function diskFootprintMib(): ?int
    {
        return $this->disk_quota_mib;
    }
}
