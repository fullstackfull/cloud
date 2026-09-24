<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * A hosting package mapping as an operator sees it.
 *
 * `verified` is deliberately absent rather than false. This platform has not
 * asked a panel whether a package by this name exists — 30B.0-E is blocked and
 * `REAL_HOSTING_VERIFIED` is NONE — and a field reading `verified: false`
 * invites a screen to render "not verified yet" as though verification were
 * pending. It is not pending; it is a phase that has not started. What the row
 * can honestly say is whether it is mapped to a plan and switched on.
 *
 * @mixin HostingPackage
 */
final class OperatorHostingPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HostingPackage $package */
        $package = $this->resource;

        return [
            'id' => (string) $package->getKey(),
            'slug' => $package->slug,
            'panel_package_name' => $package->panel_package_name,
            'plan_id' => $package->plan_id === null ? null : (string) $package->plan_id,
            'is_active' => $package->is_active,
            'mapped' => $package->plan_id !== null,
            // Flat, matching the request that sets them and the customer
            // resource that reads them. Null is the model's unlimited, never
            // zero: a package granting zero disk is an account that cannot
            // hold a file, recorded as configured.
            'disk_quota_mib' => $package->disk_quota_mib,
            'bandwidth_quota_mib' => $package->bandwidth_quota_mib,
            'max_addon_domains' => $package->max_addon_domains,
            'max_subdomains' => $package->max_subdomains,
            'max_databases' => $package->max_databases,
            'max_email_accounts' => $package->max_email_accounts,
            'cpu_limit_percent' => $package->cpu_limit_percent,
            'memory_limit_mib' => $package->memory_limit_mib,
            'io_limit_kbps' => $package->io_limit_kbps,
            'process_limit' => $package->process_limit,
            'entry_process_limit' => $package->entry_process_limit,
        ];
    }
}
