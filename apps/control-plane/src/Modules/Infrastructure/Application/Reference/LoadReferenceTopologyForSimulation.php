<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Reference;

use Illuminate\Contracts\Foundation\Application;
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
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceKind;
use Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceTopology;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Enums\NetworkPurpose;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Providers\Application\Actions\RegisterProvider;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use RuntimeException;

/**
 * Put the reference estate into the database, for simulation only.
 *
 * ---------------------------------------------------------------------------
 * The name is the point
 * ---------------------------------------------------------------------------
 *
 * Not `useTopology()`, not `apply()`, not `sync()`. Loading a model of an
 * estate and activating a real configuration are different acts with different
 * consequences, and a method name that could mean either is how the second one
 * happens by accident. This class only ever does the first, and the four
 * refusals below are what make that structural rather than promised:
 *
 *   1. it refuses to run on a production installation;
 *   2. it refuses a topology whose own marker claims to be production,
 *      deployable or reachable;
 *   3. every row it writes is stamped `development`, so no reference row can
 *      satisfy a production readiness question;
 *   4. every provider it writes uses a controlled driver, which
 *      {@see RegisterProvider}
 *      refuses in production and whose tester refuses to be constructed there.
 *
 * There is no counterpart that promotes what this writes to production. Real
 * values arrive through the Control Center and the private inventory, and that
 * is the whole design: see docs/phase-30b-sim-gap-4-reference-topology.md §23.
 *
 * ---------------------------------------------------------------------------
 * What it deliberately does not write
 * ---------------------------------------------------------------------------
 *
 * No credential, no licence, no capability row and no connection test. A
 * reference provider is therefore blocked on credentials and can never be
 * enabled, which is correct: the estate is a shape, and a shape cannot be
 * proven to answer.
 *
 * Some facts in the definition have no column to go in — a storage pool's role,
 * a template's minimum resources, which node offers a hosting package. Those
 * are carried in the definition because a real onboarding has to supply them,
 * and they are dropped here rather than invented into a table. The report
 * records each one.
 */
final readonly class LoadReferenceTopologyForSimulation
{
    public function __construct(
        private Application $app,
        private SeedSubnetAddresses $addresses,
    ) {}

    /**
     * @return array<string, int> how many rows of each kind were written
     */
    public function execute(?ReferenceTopology $topology = null): array
    {
        if ($this->app->environment('production')) {
            throw new RuntimeException(
                'The reference topology is a model of an estate and must never be loaded into a production installation. Real inventory is declared through the Control Center and the private inventory, not seeded.'
            );
        }

        $topology ??= ReferenceTopology::load();

        if ($topology->isProduction() || $topology->isDeployable() || $topology->isReachable()) {
            throw new RuntimeException(
                'This topology claims to be production, deployable or reachable. Whatever it is, it is not a reference topology, and it is not being loaded.'
            );
        }

        /*
         * Logical id to database key, built as rows are written and threaded
         * through every writer below. Not a property and not a static: a
         * loader that cached keys between runs would resolve a ref to a row
         * from a database that had since been rebuilt, and nothing would say
         * so. Gap 3 made that mistake once and it is not being repeated.
         *
         * @var array<string, string>
         */
        $keys = [];

        $rows = [];

        // Dependency order. A machine names a compute node and a hosting node,
        // so both are written before any machine is.
        $rows['region'] = $this->regions($topology, $keys);
        $rows['datacenter'] = $this->datacenters($topology, $keys);
        $rows['rack'] = $this->racks($topology, $keys);
        $rows['cluster'] = $this->clusters($topology, $keys);
        $rows['node'] = $this->nodes($topology, $keys);
        $rows['storage'] = $this->storage($topology, $keys);
        $rows['template'] = $this->templates($topology, $keys);
        $rows['network'] = $this->networks($topology, $keys);
        $rows['ip_pool'] = $this->pools($topology, $keys);
        $rows['subnet'] = $this->subnets($topology, $keys);
        $rows['hosting_node'] = $this->hostingNodes($topology, $keys);
        $rows['hosting_package'] = $this->hostingPackages($topology);
        $rows['chassis'] = $this->chassis($topology, $keys);
        $rows['bmc'] = $this->bmcs($topology, $keys);
        $rows['machine'] = $this->machines($topology, $keys);
        $rows['provider'] = $this->providers($topology, $keys);

        return $rows;
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function regions(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Region) as $object) {
            $keys[$object->id] = (string) Region::updateOrCreate(
                ['slug' => $object->id],
                [
                    'name' => ['en' => $object->string('name_en'), 'ar' => $object->string('name_ar')],
                    'country' => $object->string('country'),
                    'city' => $object->string('city'),
                    'is_active' => $object->bool('is_active'),
                    'accepts_new_services' => $object->bool('accepts_new_services'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Region));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function datacenters(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Datacenter) as $object) {
            $keys[$object->id] = (string) Datacenter::updateOrCreate(
                ['slug' => $object->id],
                [
                    'region_id' => $this->key($keys, $object->ref('region')),
                    'name' => $object->string('name'),
                    'facility' => $object->string('facility'),
                    'is_active' => $object->bool('is_active'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Datacenter));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function racks(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Rack) as $object) {
            $keys[$object->id] = (string) Rack::updateOrCreate(
                ['datacenter_id' => $this->key($keys, $object->ref('datacenter')), 'name' => $object->string('name')],
                [
                    'row' => $object->stringOrNull('row'),
                    'units' => $object->int('units'),
                    'power_notes' => $object->stringOrNull('power_notes'),
                    'network_notes' => $object->stringOrNull('network_notes'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Rack));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function clusters(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Cluster) as $object) {
            $keys[$object->id] = (string) ComputeCluster::updateOrCreate(
                ['slug' => $object->id],
                [
                    'datacenter_id' => $this->key($keys, $object->ref('datacenter')),
                    'name' => $object->string('name'),
                    // Never `proxmox`. A reference cluster that named the real
                    // driver would be a reference cluster something could dial.
                    'driver' => ComputeDriver::from($object->string('driver')),
                    'api_endpoint' => $object->stringOrNull('endpoint'),
                    'credentials_reference' => null,
                    'verify_tls' => $object->bool('verify_tls'),
                    'status' => ClusterStatus::from($object->string('status')),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Cluster));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function nodes(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Node) as $object) {
            $keys[$object->id] = (string) ComputeNode::updateOrCreate(
                [
                    'cluster_id' => $this->key($keys, $object->ref('cluster')),
                    'provider_name' => $object->string('provider_name'),
                ],
                [
                    'status' => NodeStatus::from($object->string('status')),
                    'cpu_cores' => $object->int('cpu_cores'),
                    'memory_mib' => $object->int('memory_mib'),
                    'storage_gib' => $object->int('storage_gib'),
                    'cpu_overcommit_ratio' => $object->float('cpu_overcommit_ratio'),
                    'memory_headroom_percent' => $object->int('memory_headroom_percent'),
                    'is_healthy' => $object->bool('is_healthy'),
                    'last_seen_at' => now(),
                    'capabilities' => [
                        'architecture' => $object->string('architecture'),
                        'nested_virtualisation' => $object->bool('nested_virtualisation'),
                    ],
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Node));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function storage(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Storage) as $object) {
            $node = $object->ref('node');

            $keys[$object->id] = (string) ComputeStorage::updateOrCreate(
                [
                    'cluster_id' => $this->key($keys, $object->ref('cluster')),
                    'node_id' => $node === null ? null : $this->key($keys, $node),
                    'provider_name' => $object->string('provider_name'),
                ],
                [
                    'storage_class' => StorageClass::from($object->string('storage_class')),
                    'shared' => $object->bool('shared'),
                    'total_gib' => $object->int('total_gib'),
                    'available_gib' => $object->int('available_gib'),
                    'is_active' => $object->bool('is_active'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Storage));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function templates(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Template) as $object) {
            $keys[$object->id] = (string) VmTemplate::updateOrCreate(
                ['cluster_id' => $this->key($keys, $object->ref('cluster')), 'slug' => $object->string('slug')],
                [
                    'name' => ['en' => $object->string('name_en'), 'ar' => $object->string('name_ar')],
                    'os_family' => OsFamily::from($object->string('os_family')),
                    'os_version' => $object->string('os_version'),
                    'architecture' => CpuArchitecture::from($object->string('architecture')),
                    'provider_reference' => $object->stringOrNull('provider_reference'),
                    'cloud_init' => $object->bool('cloud_init'),
                    'guest_agent' => $object->bool('guest_agent'),
                    'requires_licence' => $object->bool('requires_licence'),
                    'licence_note' => $object->stringOrNull('licence_note'),
                    'is_active' => $object->bool('is_active'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Template));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function networks(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Network) as $object) {
            $keys[$object->id] = (string) Network::updateOrCreate(
                ['datacenter_id' => $this->key($keys, $object->ref('datacenter')), 'slug' => $object->string('slug')],
                [
                    'name' => $object->string('name'),
                    'purpose' => NetworkPurpose::from($object->string('purpose')),
                    'vlan_id' => $object->intOrNull('vlan_id'),
                    'bridge' => $object->stringOrNull('bridge'),
                    'is_customer_facing' => $object->bool('is_customer_facing'),
                    'is_active' => $object->bool('is_active'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Network));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function pools(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::IpPool) as $object) {
            $keys[$object->id] = (string) IpPool::updateOrCreate(
                ['slug' => $object->string('slug')],
                [
                    'datacenter_id' => $this->key($keys, $object->ref('datacenter')),
                    'name' => $object->string('name'),
                    'ip_version' => IpVersion::from($object->int('ip_version')),
                    'scope' => IpPoolScope::from($object->string('scope')),
                    'is_active' => $object->bool('is_active'),
                    'quarantine_days' => $object->int('quarantine_days'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::IpPool));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function subnets(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Subnet) as $object) {
            $subnet = Subnet::updateOrCreate(
                ['cidr' => $object->string('cidr')],
                [
                    'ip_pool_id' => $this->key($keys, $object->ref('ip_pool')),
                    'network_id' => $this->key($keys, $object->ref('network')),
                    'ip_version' => IpVersion::from($object->int('ip_version')),
                    'prefix_length' => $object->int('prefix_length'),
                    'gateway' => $object->stringOrNull('gateway'),
                    'is_active' => $object->bool('is_active'),
                ],
            );

            $keys[$object->id] = (string) $subnet->getKey();

            // Idempotent by construction: the action inserts on a unique
            // (subnet_id, address) index and skips what is already there, so a
            // reload never duplicates an address or disturbs an allocation.
            if ($object->has('expand_addresses') && $object->bool('expand_addresses')) {
                $this->addresses->execute($subnet);
            }
        }

        return count($topology->of(ReferenceKind::Subnet));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function hostingNodes(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::HostingNode) as $object) {
            $keys[$object->id] = (string) HostingNode::updateOrCreate(
                ['slug' => $object->string('slug')],
                [
                    'datacenter_id' => $this->key($keys, $object->ref('datacenter')),
                    'hostname' => $object->string('hostname'),
                    // Never cpanel or directadmin: both are licensed and both
                    // would be dialled for real by the adapter.
                    'panel' => HostingPanel::from($object->string('panel')),
                    'panel_version' => $object->stringOrNull('panel_version'),
                    'api_endpoint' => $object->stringOrNull('api_endpoint'),
                    'credentials_reference' => null,
                    'verify_tls' => $object->bool('verify_tls'),
                    'status' => HostingNodeStatus::from($object->string('status')),
                    'accepts_new_accounts' => $object->bool('accepts_new_accounts'),
                    // Recording a fake panel as licensed would be a claim about
                    // a product that is not there.
                    'panel_licensed' => $object->bool('panel_licensed'),
                    'licence_status' => $object->stringOrNull('licence_status'),
                    'cloudlinux' => $object->bool('cloudlinux'),
                    'litespeed' => $object->bool('litespeed'),
                    'max_accounts' => $object->int('max_accounts'),
                    'account_count' => 0,
                    'disk_total_mib' => $object->int('disk_total_mib'),
                    'disk_used_mib' => 0,
                    'load_average' => 0.0,
                    'last_synced_at' => now(),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::HostingNode));
    }

    /**
     * A package is what the panel is told to create; a plan is what the
     * customer bought. They stay separate rows so that renaming a plan never
     * changes the argument passed to createacct.
     *
     * The quotas come from the plan rather than from the topology: they are a
     * product decision and the catalogue already owns them. A package whose
     * plan is not in the catalogue is skipped, so the reference estate loads
     * with or without the catalogue.
     */
    private function hostingPackages(ReferenceTopology $topology): int
    {
        $written = 0;

        foreach ($topology->of(ReferenceKind::HostingPackage) as $object) {
            $plan = Plan::query()->where('slug', $object->string('plan_slug'))->first();

            if ($plan === null) {
                continue;
            }

            $resources = $plan->resources;

            HostingPackage::updateOrCreate(
                ['slug' => $object->string('slug')],
                [
                    'plan_id' => $plan->getKey(),
                    'panel_package_name' => $object->string('panel_package_name'),
                    'disk_quota_mib' => $this->quota($resources, 'disk_quota_mib'),
                    'bandwidth_quota_mib' => $this->quota($resources, 'bandwidth_quota_mib'),
                    'max_addon_domains' => $this->quota($resources, 'max_addon_domains'),
                    'max_subdomains' => ($this->quota($resources, 'max_addon_domains') ?? 0) * 5,
                    'max_databases' => $this->quota($resources, 'max_databases'),
                    'max_email_accounts' => $this->quota($resources, 'max_email_accounts'),
                    'is_active' => $object->bool('is_active'),
                ],
            );

            $written++;
        }

        return $written;
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function chassis(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Chassis) as $object) {
            $rack = $object->ref('rack');

            $keys[$object->id] = (string) DedicatedServer::updateOrCreate(
                ['serial' => $object->string('serial')],
                [
                    'datacenter_id' => $this->key($keys, $object->ref('datacenter')),
                    'rack_id' => $rack === null ? null : $this->key($keys, $rack),
                    'manufacturer' => $object->string('manufacturer'),
                    'model' => $object->string('model'),
                    'asset_tag' => $object->stringOrNull('asset_tag'),
                    'rack_unit' => $object->intOrNull('rack_unit'),
                    'height_units' => $object->int('height_units'),
                    'hardware_profile' => $object->string('hardware_profile'),
                    'status' => DedicatedServerStatus::from($object->string('status')),
                    'power_state' => PowerState::from($object->string('power_state')),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Chassis));
    }

    /**
     * The relationship and nothing else: no username, no credential reference.
     * A reference BMC is a BMC nobody can log into, which is the only safe
     * shape for something committed to git.
     */
    /**
     * @param  array<string, string>  $keys
     */
    private function bmcs(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Bmc) as $object) {
            $keys[$object->id] = (string) BmcEndpoint::updateOrCreate(
                ['dedicated_server_id' => $this->key($keys, $object->ref('chassis'))],
                [
                    'protocol' => BmcProtocol::from($object->string('protocol')),
                    'address' => $object->string('address'),
                    'port' => $object->intOrNull('port'),
                    'username' => null,
                    'credentials_reference' => null,
                    'verify_tls' => $object->bool('verify_tls'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Bmc));
    }

    /**
     * @param  array<string, string>  $keys
     */
    private function machines(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Machine) as $object) {
            $rack = $object->ref('rack');
            $computeNode = $object->ref('compute_node');
            $hostingNode = $object->ref('hosting_node');

            $keys[$object->id] = (string) ManagedServer::updateOrCreate(
                ['name' => $object->string('name')],
                [
                    // Development, always. A reference machine that claimed to
                    // be production could satisfy a production readiness
                    // question, and that is the accident this gap exists to
                    // make impossible.
                    'environment' => DeploymentEnvironment::Development,
                    'state' => ServerState::from($object->string('state')),
                    'datacenter_id' => $this->key($keys, $object->ref('datacenter')),
                    'rack_id' => $rack === null ? null : $this->key($keys, $rack),
                    'rack_unit' => $object->intOrNull('rack_unit'),
                    'height_units' => $object->int('height_units'),
                    'vendor' => $object->stringOrNull('vendor'),
                    'model' => $object->stringOrNull('model'),
                    'serial' => $object->stringOrNull('serial'),
                    'asset_tag' => $object->stringOrNull('asset_tag'),
                    'operating_system' => $object->stringOrNull('operating_system'),
                    'management_address' => $object->stringOrNull('management_address'),
                    'management_port' => $object->intOrNull('management_port'),
                    'bmc_address' => $object->stringOrNull('bmc_address'),
                    'bmc_port' => $object->intOrNull('bmc_port'),
                    'credential_reference_id' => null,
                    'safety_class' => SafetyClass::from($object->string('safety_class')),
                    'allow_reimage' => $object->bool('allow_reimage'),
                    'safety_reason' => $object->stringOrNull('safety_reason'),
                    'connection_state' => ConnectionState::NotTested,
                    'compute_node_id' => $computeNode === null ? null : $this->key($keys, $computeNode),
                    'hosting_node_id' => $hostingNode === null ? null : $this->key($keys, $hostingNode),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Machine));
    }

    /**
     * The reference estate references the provider-instance concept; it does
     * not carry a second copy of provider state.
     *
     * Draft, not-tested, not-ready, with no credential and no licence — which
     * is the honest state of a provider nobody has ever contacted, and which
     * makes enabling one refuse.
     */
    /**
     * @param  array<string, string>  $keys
     */
    private function providers(ReferenceTopology $topology, array &$keys): int
    {
        foreach ($topology->of(ReferenceKind::Provider) as $object) {
            $machine = $object->ref('machine');

            $keys[$object->id] = (string) ProviderInstance::updateOrCreate(
                ['name' => $object->string('name')],
                [
                    'category' => ProviderCategory::from($object->string('category')),
                    'driver' => $object->string('driver'),
                    'environment' => DeploymentEnvironment::from($object->string('environment')),
                    'endpoint' => $object->stringOrNull('endpoint'),
                    'credential_reference_id' => null,
                    'licence_id' => null,
                    'managed_server_id' => $machine === null ? null : $this->key($keys, $machine),
                    'state' => ProviderState::Draft,
                    'connection_state' => ConnectionState::NotTested,
                    'readiness' => ReadinessState::NotReady,
                    'notes' => $object->stringOrNull('notes'),
                ],
            )->getKey();
        }

        return count($topology->of(ReferenceKind::Provider));
    }

    /**
     * One numeric quota out of a plan's resources, or null.
     *
     * A plan's resources are free-form JSON, so a value that should be a count
     * may be absent or may be something else entirely. A package quota column
     * takes an integer or nothing, and silently writing a string into one is
     * how a panel later receives a quota it cannot parse.
     *
     * @param  array<string, mixed>  $resources
     */
    private function quota(array $resources, string $key): ?int
    {
        $value = $resources[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * The database key a logical id was written as.
     *
     * A miss is a programming error and not a data error: the validator has
     * already proved every ref resolves, so the only way to get here is to
     * write the kinds in the wrong order.
     *
     * @param  array<string, string>  $keys
     */
    private function key(array $keys, ?string $id): string
    {
        if ($id === null || ! isset($keys[$id])) {
            throw new RuntimeException(sprintf(
                'The reference loader asked for %s before it had written it. Check the order in execute().',
                $id ?? 'a null id',
            ));
        }

        return $keys[$id];
    }
}
