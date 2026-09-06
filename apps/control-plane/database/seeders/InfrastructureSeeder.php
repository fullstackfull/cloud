<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Enums\NetworkPurpose;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use RuntimeException;

/**
 * A complete, coherent inventory for local development: one region, one
 * datacenter, a compute cluster with two nodes and storage, a public IPv4
 * subnet expanded into allocatable addresses, a shared-hosting node with
 * packages mapped to catalogue plans, and a rack of dedicated machines whose
 * hardware profiles match what the dedicated plans sell.
 *
 * Coherence is the point. A cluster without storage places nothing; a subnet
 * that was never expanded allocates nothing; a dedicated plan whose profile
 * names no machine sells something that cannot be delivered. Fixtures that are
 * individually plausible and collectively inconsistent are how a development
 * environment ends up disagreeing with production about which code paths are
 * even reachable.
 *
 * Every endpoint here is fake or unroutable by construction:
 *
 *   - the cluster driver is `fake`, never `proxmox`, so nothing dials out;
 *   - the hosting panel is `fake`, never cPanel or DirectAdmin;
 *   - addresses come from 198.51.100.0/24 and 2001:db8::/32, the documentation
 *     ranges reserved by RFC 5737 and RFC 3849, so a misconfigured development
 *     box cannot announce or contact somebody's real network;
 *   - hostnames sit under .test, reserved by RFC 6761 and never delegated.
 *
 * There are no BMC credentials here, and no addresses on a management network.
 * A development machine has no business holding either.
 */
final class InfrastructureSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'InfrastructureSeeder must never run in production: real inventory is declared, not seeded.'
            );
        }

        $datacenter = $this->datacenter();
        $cluster = $this->computeCluster($datacenter);
        $this->computeNodes($cluster);
        $this->vmTemplates($cluster);
        $this->ipam($datacenter);
        $this->hosting($datacenter);
        $this->dedicated($datacenter);

        $this->command?->info(sprintf(
            'Infrastructure seeded: %d nodes, %d allocatable addresses, %d hosting nodes, %d dedicated servers.',
            ComputeNode::count(),
            // Allocatable, not total: the network, gateway and broadcast
            // addresses are rows too, and counting them would overstate what
            // the pool can actually hand out.
            IpAddress::query()->where('status', IpAddressStatus::Available)->count(),
            HostingNode::count(),
            DedicatedServer::count(),
        ));
    }

    private function datacenter(): Datacenter
    {
        $region = Region::updateOrCreate(
            ['slug' => 'kw-central'],
            [
                'name' => ['en' => 'Kuwait Central', 'ar' => 'الكويت الوسطى'],
                'country' => 'KW',
                'city' => 'Kuwait City',
                'is_active' => true,
                'accepts_new_services' => true,
            ],
        );

        return Datacenter::updateOrCreate(
            ['slug' => 'kw-central-1'],
            [
                'region_id' => $region->getKey(),
                'name' => 'Kuwait Central 1',
                'facility' => 'Development facility',
                'is_active' => true,
            ],
        );
    }

    private function computeCluster(Datacenter $datacenter): ComputeCluster
    {
        return ComputeCluster::updateOrCreate(
            ['slug' => 'pve-kw-1'],
            [
                'datacenter_id' => $datacenter->getKey(),
                'name' => 'Development hypervisor cluster',
                // Never `proxmox`. A development seeder that points at a real
                // cluster is a development seeder that can destroy one.
                'driver' => ComputeDriver::Fake,
                'api_endpoint' => null,
                'credentials_reference' => null,
                'verify_tls' => true,
                'status' => ClusterStatus::Active,
            ],
        );
    }

    private function computeNodes(ComputeCluster $cluster): void
    {
        // Two nodes rather than one, because anti-affinity, live migration and
        // "the placement engine picked the emptier host" are all untestable on
        // a single-node cluster, and those are the parts worth exercising.
        foreach (['pve-kw-1-a', 'pve-kw-1-b'] as $name) {
            $node = ComputeNode::updateOrCreate(
                ['cluster_id' => $cluster->getKey(), 'provider_name' => $name],
                [
                    'status' => NodeStatus::Active,
                    'cpu_cores' => 32,
                    'memory_mib' => 262_144,
                    'storage_gib' => 4096,
                    'cpu_overcommit_ratio' => 4.0,
                    'memory_headroom_percent' => 10,
                    'is_healthy' => true,
                    'last_seen_at' => now(),
                    'capabilities' => ['architecture' => 'x86_64', 'nested_virtualisation' => true],
                ],
            );

            ComputeStorage::updateOrCreate(
                ['cluster_id' => $cluster->getKey(), 'node_id' => $node->getKey(), 'provider_name' => 'local-nvme'],
                [
                    'storage_class' => StorageClass::Nvme,
                    'shared' => false,
                    'total_gib' => 4096,
                    'available_gib' => 4096,
                    'is_active' => true,
                ],
            );
        }

        // One shared pool alongside the local disks: shared storage is counted
        // once for the whole cluster rather than once per node, and having a
        // real example of each in development is what keeps that distinction
        // honest.
        ComputeStorage::updateOrCreate(
            ['cluster_id' => $cluster->getKey(), 'node_id' => null, 'provider_name' => 'ceph-rbd'],
            [
                'storage_class' => StorageClass::Ceph,
                'shared' => true,
                'total_gib' => 16_384,
                'available_gib' => 16_384,
                'is_active' => true,
            ],
        );
    }

    private function vmTemplates(ComputeCluster $cluster): void
    {
        $templates = [
            ['ubuntu-24-04', ['en' => 'Ubuntu 24.04 LTS', 'ar' => 'أوبونتو 24.04 LTS'], OsFamily::Ubuntu, '24.04'],
            ['debian-13', ['en' => 'Debian 13', 'ar' => 'دبيان 13'], OsFamily::Debian, '13'],
            ['almalinux-10', ['en' => 'AlmaLinux 10', 'ar' => 'ألما لينكس 10'], OsFamily::Alma, '10'],
            ['rocky-10', ['en' => 'Rocky Linux 10', 'ar' => 'روكي لينكس 10'], OsFamily::Rocky, '10'],
        ];

        foreach ($templates as [$slug, $name, $family, $version]) {
            VmTemplate::updateOrCreate(
                ['cluster_id' => $cluster->getKey(), 'slug' => $slug],
                [
                    'name' => $name,
                    'os_family' => $family,
                    'os_version' => $version,
                    'architecture' => CpuArchitecture::X86_64,
                    'cloud_init' => true,
                    'guest_agent' => true,
                    // No Windows template is seeded: the image is licensed, and
                    // the platform records that rather than shipping one.
                    'requires_licence' => false,
                    'is_active' => true,
                ],
            );
        }
    }

    private function ipam(Datacenter $datacenter): void
    {
        $public = Network::updateOrCreate(
            ['datacenter_id' => $datacenter->getKey(), 'slug' => 'public'],
            [
                'name' => 'Customer public',
                'purpose' => NetworkPurpose::Public,
                // A real VLAN id comes from the inventory of the site being
                // built. This one is a development placeholder and is only safe
                // because nothing here reaches a switch.
                'vlan_id' => 100,
                'bridge' => 'vmbr0',
                'is_customer_facing' => true,
                'is_active' => true,
            ],
        );

        $pool = IpPool::updateOrCreate(
            ['slug' => 'kw-public-v4'],
            [
                'datacenter_id' => $datacenter->getKey(),
                'name' => 'Kuwait public IPv4',
                'ip_version' => IpVersion::V4,
                'scope' => IpPoolScope::Public,
                'is_active' => true,
                // Short enough that a developer can watch an address leave
                // quarantine within one working day, long enough that the
                // quarantine is visibly not a no-op.
                'quarantine_days' => 1,
            ],
        );

        // RFC 5737 TEST-NET-3. A /26 gives 61 usable addresses: enough to
        // provision against repeatedly, small enough to exhaust on purpose.
        $subnet = Subnet::updateOrCreate(
            ['cidr' => '198.51.100.0/26'],
            [
                'ip_pool_id' => $pool->getKey(),
                'network_id' => $public->getKey(),
                'ip_version' => IpVersion::V4,
                'prefix_length' => 26,
                'gateway' => '198.51.100.1',
                'is_active' => true,
            ],
        );

        // Idempotent by construction: the action inserts on a unique
        // (subnet_id, address) index and skips what is already there, so
        // re-seeding never duplicates an address or disturbs an allocation.
        app(SeedSubnetAddresses::class)->execute($subnet);

        // IPv6 is delegated as a prefix per service, never enumerated, so the
        // v6 pool carries a subnet and no addresses. RFC 3849 documentation
        // prefix.
        $poolV6 = IpPool::updateOrCreate(
            ['slug' => 'kw-public-v6'],
            [
                'datacenter_id' => $datacenter->getKey(),
                'name' => 'Kuwait public IPv6',
                'ip_version' => IpVersion::V6,
                'scope' => IpPoolScope::Public,
                'is_active' => true,
                'quarantine_days' => 0,
            ],
        );

        Subnet::updateOrCreate(
            ['cidr' => '2001:db8:100::/48'],
            [
                'ip_pool_id' => $poolV6->getKey(),
                'network_id' => $public->getKey(),
                'ip_version' => IpVersion::V6,
                'prefix_length' => 48,
                'gateway' => null,
                'is_active' => true,
            ],
        );
    }

    private function hosting(Datacenter $datacenter): void
    {
        $node = HostingNode::updateOrCreate(
            ['slug' => 'shared-kw-1'],
            [
                'datacenter_id' => $datacenter->getKey(),
                'hostname' => 'shared-kw-1.lynomia.test',
                // Never cpanel or directadmin: both are licensed products and
                // both would be dialled for real by the provider adapter.
                'panel' => HostingPanel::Fake,
                'panel_version' => '0.0.0-fake',
                'api_endpoint' => 'https://shared-kw-1.lynomia.test:2087',
                'credentials_reference' => null,
                'verify_tls' => true,
                'status' => HostingNodeStatus::Active,
                'accepts_new_accounts' => true,
                // The fake panel has no licence to check; recording it as
                // licensed would be a claim about a product that is not there.
                'panel_licensed' => false,
                'licence_status' => 'not_applicable',
                'cloudlinux' => false,
                'litespeed' => false,
                'max_accounts' => 200,
                'account_count' => 0,
                'disk_total_mib' => 2_097_152,
                'disk_used_mib' => 0,
                'load_average' => 0.2,
                'last_synced_at' => now(),
            ],
        );

        // The package is what the panel is told to create; the plan is what the
        // customer bought. They are separate rows on purpose, so renaming a
        // plan in the catalogue never changes the argument passed to createacct.
        foreach (['starter', 'business', 'agency'] as $tier) {
            $plan = Plan::query()->where('slug', 'hosting-'.$tier)->first();

            if ($plan === null) {
                continue;
            }

            $resources = $plan->resources;

            HostingPackage::updateOrCreate(
                ['slug' => 'pkg-'.$tier],
                [
                    'plan_id' => $plan->getKey(),
                    'panel_package_name' => 'lyn_'.$tier,
                    'disk_quota_mib' => $resources['disk_quota_mib'] ?? null,
                    'bandwidth_quota_mib' => $resources['bandwidth_quota_mib'] ?? null,
                    'max_addon_domains' => $resources['max_addon_domains'] ?? null,
                    'max_subdomains' => ($resources['max_addon_domains'] ?? 0) * 5,
                    'max_databases' => $resources['max_databases'] ?? null,
                    'max_email_accounts' => $resources['max_email_accounts'] ?? null,
                    'is_active' => true,
                ],
            );
        }

        unset($node);
    }

    private function dedicated(Datacenter $datacenter): void
    {
        $rack = Rack::updateOrCreate(
            ['datacenter_id' => $datacenter->getKey(), 'name' => 'R01'],
            ['row' => 'A', 'units' => 42],
        );

        /*
         * Three machines of one profile and two of the other. Three matters:
         * with a single free machine per profile, a concurrent reservation test
         * cannot tell "waited correctly for the contended one" apart from
         * "skipped to a spare", and those are different bugs.
         *
         * No BMC endpoint is attached. A BMC row carries a credential reference
         * and an address on a management network; a development database should
         * hold neither, and the Redfish adapter is exercised against its own
         * test double rather than against a fixture that looks like real
         * hardware.
         */
        $machines = [
            ['ded-standard-1', 'HPE', 'ProLiant DL360 Gen10', 3, 10],
            ['ded-storage-1', 'Dell', 'PowerEdge R740xd', 2, 20],
        ];

        foreach ($machines as [$profile, $manufacturer, $model, $count, $baseUnit]) {
            for ($i = 1; $i <= $count; $i++) {
                DedicatedServer::updateOrCreate(
                    ['serial' => sprintf('DEV-%s-%02d', strtoupper(substr($profile, 4, 4)), $i)],
                    [
                        'datacenter_id' => $datacenter->getKey(),
                        'rack_id' => $rack->getKey(),
                        'manufacturer' => $manufacturer,
                        'model' => $model,
                        'asset_tag' => sprintf('AT-%s-%02d', strtoupper(substr($profile, 4, 4)), $i),
                        'rack_unit' => $baseUnit + $i,
                        'height_units' => 1,
                        'hardware_profile' => $profile,
                        'status' => DedicatedServerStatus::Available,
                        'power_state' => PowerState::Off,
                    ],
                );
            }
        }
    }
}
