<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Enums\NetworkPurpose;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;

/**
 * A layer-2 segment in a building.
 *
 * `is_customer_facing` is not inferred from the purpose, and the two are not
 * the same question: a public segment that has not been wired to customer
 * bridges yet is public and not customer-facing, and a management segment is
 * never customer-facing whatever anybody ticks. So the flag is forced off for
 * the purposes where the answer is not the operator's to give — the rule the
 * allocator and the placement path both rely on is that control-plane
 * addressing never reaches a customer workload, and a form that could tick it
 * on would be a lateral-movement path with a label.
 */
final readonly class RegisterNetwork
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(
        Datacenter $datacenter,
        string $slug,
        string $name,
        NetworkPurpose $purpose,
        ?int $vlanId,
        ?string $bridge,
        bool $customerFacing,
        User $operator,
    ): Network {
        $reachesCustomers = $customerFacing && $this->mayFaceCustomers($purpose);

        return $this->record->execute(
            act: fn (): Network => Network::query()->create([
                'datacenter_id' => $datacenter->getKey(),
                'slug' => $slug,
                'name' => $name,
                'purpose' => $purpose,
                'vlan_id' => $vlanId,
                'bridge' => $bridge,
                'is_customer_facing' => $reachesCustomers,
                'is_active' => true,
            ]),
            describe: fn (Network $network): AuditedAct => new AuditedAct(
                action: AuditAction::NetworkRegistered,
                subject: $network,
                context: [
                    'slug' => $network->slug,
                    'purpose' => $purpose->value,
                    'datacenter' => $datacenter->slug,
                    'is_customer_facing' => $reachesCustomers,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }

    /**
     * Management and cluster interconnect never carry customer workloads,
     * whatever the form said.
     */
    private function mayFaceCustomers(NetworkPurpose $purpose): bool
    {
        return $purpose !== NetworkPurpose::Management
            && $purpose !== NetworkPurpose::ClusterInterconnect;
    }
}
