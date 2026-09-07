<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Admin\Http\Controllers\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * What the platform is made of, and how full it is.
 *
 * Read-only. Inventory is declared in the infrastructure repository and applied
 * by Ansible, not typed into a web form: a node that exists because somebody
 * added a row is a node with no configuration behind it, and the first thing it
 * does is take a customer's workload it cannot run.
 */
final class InfrastructureController
{
    use ListsAcrossTenants;

    public function nodes(Request $request): JsonResponse
    {
        $nodes = ComputeNode::query()
            ->with('cluster.datacenter')
            ->orderBy('provider_name')
            ->paginate($this->perPage($request));

        return $this->paginated($nodes, static function (ComputeNode $node): array {
            /*
             * Headroom as the scheduler computes it, not as a naive
             * allocated/total ratio would: memory keeps a reserve the
             * hypervisor itself needs, and CPU is overcommitted on purpose.
             * Publishing the raw ratio would have an operator believe a node is
             * empty when the placement engine considers it full.
             */
            $cpuCapacity = (int) round($node->cpu_cores * $node->cpu_overcommit_ratio);
            $memoryCapacity = (int) round($node->memory_mib * (1 - $node->memory_headroom_percent / 100));

            return [
                'id' => $node->id,
                'name' => $node->provider_name,
                'cluster' => $node->cluster?->slug,
                'datacenter' => $node->cluster?->datacenter?->slug,
                'status' => $node->status->value,
                'is_healthy' => $node->is_healthy,
                'vm_count' => $node->vm_count,
                'cpu' => [
                    'cores' => $node->cpu_cores,
                    'allocatable' => $cpuCapacity,
                    'allocated' => $node->allocated_cpu_cores,
                ],
                'memory_mib' => [
                    'total' => $node->memory_mib,
                    'allocatable' => $memoryCapacity,
                    'allocated' => $node->allocated_memory_mib,
                ],
                'storage_gib' => [
                    'total' => $node->storage_gib,
                    'allocated' => $node->allocated_storage_gib,
                ],
                'last_seen_at' => $node->last_seen_at?->toIso8601String(),
            ];
        });
    }

    public function ipPools(Request $request): JsonResponse
    {
        $pools = IpPool::query()
            ->with('datacenter')
            ->withCount('subnets')
            ->orderBy('slug')
            ->paginate($this->perPage($request));

        return $this->paginated($pools, static fn (IpPool $pool): array => [
            'id' => $pool->id,
            'slug' => $pool->slug,
            'name' => $pool->name,
            'ip_version' => $pool->ip_version->value,
            'scope' => $pool->scope->value,
            'datacenter' => $pool->datacenter->slug,
            'subnets_count' => $pool->subnets_count,
            'quarantine_days' => $pool->quarantine_days,
            'is_active' => $pool->is_active,
        ]);
    }

    public function dedicatedInventory(Request $request): JsonResponse
    {
        $servers = DedicatedServer::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->orderBy('hardware_profile')
            ->orderBy('serial')
            ->paginate($this->perPage($request));

        return $this->paginated($servers, static fn (DedicatedServer $server): array => [
            'id' => $server->id,
            'serial' => $server->serial,
            'asset_tag' => $server->asset_tag,
            'manufacturer' => $server->manufacturer,
            'model' => $server->model,
            'hardware_profile' => $server->hardware_profile,
            'status' => $server->status->value,
            'power_state' => $server->power_state->value,
            // Present here and never on the customer surface: which account a
            // machine belongs to is the operator's question.
            'customer_id' => $server->customer_id,
            'service_id' => $server->service_id,
            'rack_unit' => $server->rack_unit,
        ]);
    }

    public function hostingNodes(Request $request): JsonResponse
    {
        $nodes = HostingNode::query()
            ->orderBy('slug')
            ->paginate($this->perPage($request));

        return $this->paginated($nodes, static fn (HostingNode $node): array => [
            'id' => $node->id,
            'slug' => $node->slug,
            'hostname' => $node->hostname,
            'panel' => $node->panel->value,
            'panel_version' => $node->panel_version,
            'status' => $node->status->value,
            'accepts_new_accounts' => $node->accepts_new_accounts,
            // Whether a panel is licensed decides whether the platform may
            // provision onto it at all, so it belongs on this screen.
            'panel_licensed' => $node->panel_licensed,
            'licence_status' => $node->licence_status,
            'account_count' => $node->account_count,
            'max_accounts' => $node->max_accounts,
            'disk_used_mib' => $node->disk_used_mib,
            'disk_total_mib' => $node->disk_total_mib,
            'load_average' => $node->load_average,
            'last_synced_at' => $node->last_synced_at?->toIso8601String(),

            /*
             * Deliberately absent: api_endpoint and credentials_reference. An
             * operator screen has no use for either, and a support tool that
             * displays where the credentials live is a support tool that puts
             * them in a screenshot.
             */
        ]);
    }
}
