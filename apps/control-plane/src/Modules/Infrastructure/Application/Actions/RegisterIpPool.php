<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;

/**
 * A block of addresses the platform may hand out, and the scope that decides
 * to whom.
 *
 * The scope is the security-relevant field on this row. `management` reaches
 * the hypervisor and BMC control planes; IpAllocator refuses to allocate from
 * one to a customer service and LocalPlacementFeasibility refuses to count one
 * as capacity. Both of those read the enum this stores, so an operator form
 * cannot produce a pool that means something different from what the runtime
 * will do with it — which is the whole reason the scope is written once, at
 * creation, and is not among the things an amendment may change. Reclassifying
 * an existing pool would silently move addresses between the customer estate
 * and the control plane.
 */
final readonly class RegisterIpPool
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(
        Datacenter $datacenter,
        string $slug,
        string $name,
        IpVersion $version,
        IpPoolScope $scope,
        int $quarantineDays,
        User $operator,
    ): IpPool {
        return $this->record->execute(
            act: fn (): IpPool => IpPool::query()->create([
                'datacenter_id' => $datacenter->getKey(),
                'slug' => $slug,
                'name' => $name,
                'ip_version' => $version,
                'scope' => $scope,
                'quarantine_days' => $quarantineDays,
                'is_active' => true,
            ]),
            describe: fn (IpPool $pool): AuditedAct => new AuditedAct(
                action: AuditAction::IpPoolRegistered,
                subject: $pool,
                context: [
                    'slug' => $pool->slug,
                    'scope' => $scope->value,
                    'ip_version' => $version->value,
                    'customer_allocatable' => $scope->isCustomerAllocatable(),
                    'datacenter' => $datacenter->slug,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
