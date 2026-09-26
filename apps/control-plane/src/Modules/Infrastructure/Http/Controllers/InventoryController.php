<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Actions\AmendInventory;
use Lynomia\Modules\Infrastructure\Application\Actions\RecordBmcEndpoint;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterComputeCluster;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterDedicatedServer;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterHostingNode;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterIpPool;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterNetwork;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterSubnet;
use Lynomia\Modules\Infrastructure\Http\Requests\RecordBmcEndpointRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterComputeClusterRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterDedicatedServerRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterHostingNodeRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterIpPoolRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterNetworkRequest;
use Lynomia\Modules\Infrastructure\Http\Requests\RegisterSubnetRequest;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Enums\NetworkPurpose;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Symfony\Component\HttpFoundation\Response;

/**
 * The estate, written down by the people who own it.
 *
 * ---------------------------------------------------------------------------
 * What was here before
 * ---------------------------------------------------------------------------
 *
 * Readers. `ip-pools`, `hosting-nodes`, `nodes` and `dedicated` could all be
 * listed and none of them could be created, and the three writers that did
 * exist each began by finding a parent that had no writer of its own. The
 * result was a platform that could describe an estate in detail and could not
 * be given one — except with a SQL client, an edited seeder, or the reference
 * topology, which is a model and says so in its own banner.
 *
 * ---------------------------------------------------------------------------
 * Configured is not verified
 * ---------------------------------------------------------------------------
 *
 * Nothing on this controller contacts anything. A cluster is written with no
 * sync time, a hosting node with no licence state and an account count of
 * zero, a machine with its power state unknown and a BMC with no contact time.
 * Every one of those is a statement only a provider can make, and the
 * reconcilers are what make them. A row is an operator saying "this exists";
 * it is not the platform saying "this answers".
 */
final class InventoryController
{
    public function __construct(
        private readonly EndpointPolicy $endpoints,
    ) {}

    // ---- compute ----------------------------------------------------------

    public function clusters(Request $request): JsonResponse
    {
        $clusters = ComputeCluster::query()
            ->with('datacenter')
            ->when(
                $request->filled('datacenter'),
                static fn ($query) => $query->where('datacenter_id', $request->string('datacenter')->value()),
            )
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $clusters->map(static fn (ComputeCluster $cluster): array => self::describeCluster($cluster))
                ->values()->all(),
        ]);
    }

    public function storeCluster(RegisterComputeClusterRequest $request, RegisterComputeCluster $register): JsonResponse
    {
        $datacenter = Datacenter::query()->findOrFail($request->string('datacenter_id')->value());

        $cluster = $register->execute(
            $datacenter,
            $request->string('slug')->value(),
            $request->string('name')->value(),
            ComputeDriver::from($request->string('driver')->value()),
            $request->input('api_endpoint'),
            $request->boolean('verify_tls', true),
            $request->input('credentials_reference'),
            $this->operator($request),
        );

        return response()->json([
            'data' => [
                'id' => (string) $cluster->getKey(),
                'slug' => $cluster->slug,
                'name' => $cluster->name,
                'driver' => $cluster->driver->value,
                'status' => $cluster->status->value,
                'datacenter_id' => $cluster->datacenter_id,
            ],
        ], Response::HTTP_CREATED);
    }

    // ---- networks and addresses -------------------------------------------

    public function networks(Request $request): JsonResponse
    {
        $networks = Network::query()
            ->with('datacenter')
            ->when(
                $request->filled('datacenter'),
                static fn ($query) => $query->where('datacenter_id', $request->string('datacenter')->value()),
            )
            ->orderBy('slug')
            ->get();

        return response()->json([
            'data' => $networks->map(static fn (Network $network): array => [
                'id' => (string) $network->getKey(),
                'slug' => $network->slug,
                'name' => $network->name,
                'purpose' => $network->purpose->value,
                'vlan_id' => $network->vlan_id,
                'bridge' => $network->bridge,
                'is_customer_facing' => $network->is_customer_facing,
                'is_active' => $network->is_active,
                'datacenter' => $network->datacenter?->slug,
            ])->values()->all(),
        ]);
    }

    public function storeNetwork(RegisterNetworkRequest $request, RegisterNetwork $register): JsonResponse
    {
        $datacenter = Datacenter::query()->findOrFail($request->string('datacenter_id')->value());

        $network = $register->execute(
            $datacenter,
            $request->string('slug')->value(),
            $request->string('name')->value(),
            NetworkPurpose::from($request->string('purpose')->value()),
            $request->filled('vlan_id') ? $request->integer('vlan_id') : null,
            $request->input('bridge'),
            $request->boolean('is_customer_facing'),
            $this->operator($request),
        );

        return response()->json([
            'data' => [
                'id' => (string) $network->getKey(),
                'slug' => $network->slug,
                'purpose' => $network->purpose->value,
                'is_customer_facing' => $network->is_customer_facing,
            ],
        ], Response::HTTP_CREATED);
    }

    public function storeIpPool(RegisterIpPoolRequest $request, RegisterIpPool $register): JsonResponse
    {
        $datacenter = Datacenter::query()->findOrFail($request->string('datacenter_id')->value());

        $pool = $register->execute(
            $datacenter,
            $request->string('slug')->value(),
            $request->string('name')->value(),
            IpVersion::from($request->integer('ip_version')),
            IpPoolScope::from($request->string('scope')->value()),
            $request->integer('quarantine_days', 7),
            $this->operator($request),
        );

        return response()->json([
            'data' => [
                'id' => (string) $pool->getKey(),
                'slug' => $pool->slug,
                'scope' => $pool->scope->value,
                'ip_version' => $pool->ip_version->value,
                // Published because it is the question an operator is actually
                // asking when they look at a pool list.
                'customer_allocatable' => $pool->scope->isCustomerAllocatable(),
            ],
        ], Response::HTTP_CREATED);
    }

    public function storeSubnet(RegisterSubnetRequest $request, RegisterSubnet $register, string $pool): JsonResponse
    {
        $found = IpPool::query()->findOrFail($pool);

        $network = $request->filled('network_id')
            ? Network::query()->findOrFail($request->string('network_id')->value())
            : null;

        $subnet = $register->execute(
            $found,
            $request->string('cidr')->value(),
            $request->input('gateway'),
            $network,
            $this->operator($request),
        );

        return response()->json([
            'data' => [
                'id' => (string) $subnet->getKey(),
                'cidr' => $subnet->cidr,
                'ip_version' => $subnet->ip_version->value,
                'prefix_length' => $subnet->prefix_length,
                'gateway' => $subnet->gateway,
                'network_id' => $subnet->network_id,
            ],
        ], Response::HTTP_CREATED);
    }

    public function subnets(Request $request, string $pool): JsonResponse
    {
        $found = IpPool::query()->findOrFail($pool);

        return response()->json([
            'data' => Subnet::query()
                ->where('ip_pool_id', $found->getKey())
                ->orderBy('cidr')
                ->get()
                ->map(static fn (Subnet $subnet): array => [
                    'id' => (string) $subnet->getKey(),
                    'cidr' => $subnet->cidr,
                    'gateway' => $subnet->gateway,
                    'ip_version' => $subnet->ip_version->value,
                    'prefix_length' => $subnet->prefix_length,
                    'is_active' => $subnet->is_active,
                    'network_id' => $subnet->network_id,
                ])->values()->all(),
        ]);
    }

    // ---- shared hosting ----------------------------------------------------

    public function storeHostingNode(RegisterHostingNodeRequest $request, RegisterHostingNode $register): JsonResponse
    {
        $datacenter = Datacenter::query()->findOrFail($request->string('datacenter_id')->value());

        $node = $register->execute(
            $datacenter,
            $request->string('slug')->value(),
            $request->string('hostname')->value(),
            HostingPanel::from($request->string('panel')->value()),
            $request->input('api_endpoint'),
            $request->boolean('verify_tls', true),
            $request->input('credentials_reference'),
            $request->filled('max_accounts') ? $request->integer('max_accounts') : null,
            $this->operator($request),
        );

        return response()->json([
            'data' => [
                'id' => (string) $node->getKey(),
                'slug' => $node->slug,
                'hostname' => $node->hostname,
                'panel' => $node->panel->value,
                'status' => $node->status->value,
                // False, always, on a row nothing has asked the panel about.
                'panel_licensed' => $node->panel_licensed,
            ],
        ], Response::HTTP_CREATED);
    }

    // ---- dedicated ---------------------------------------------------------

    public function storeDedicatedServer(
        RegisterDedicatedServerRequest $request,
        RegisterDedicatedServer $register,
    ): JsonResponse {
        $datacenter = Datacenter::query()->findOrFail($request->string('datacenter_id')->value());

        $rack = $request->filled('rack_id')
            ? Rack::query()->findOrFail($request->string('rack_id')->value())
            : null;

        $server = $register->execute(
            $datacenter,
            $rack,
            $request->string('manufacturer')->value(),
            $request->string('model')->value(),
            $request->string('serial')->value(),
            $request->input('asset_tag'),
            $request->filled('rack_unit') ? $request->integer('rack_unit') : null,
            $request->integer('height_units', 1),
            $request->input('hardware_profile'),
            $request->input('notes'),
            $this->operator($request),
        );

        return response()->json([
            'data' => [
                'id' => (string) $server->getKey(),
                'serial' => $server->serial,
                'model' => $server->model,
                'status' => $server->status->value,
                'power_state' => $server->power_state->value,
            ],
        ], Response::HTTP_CREATED);
    }

    public function storeBmcEndpoint(
        RecordBmcEndpointRequest $request,
        RecordBmcEndpoint $record,
        string $server,
    ): JsonResponse {
        $found = DedicatedServer::query()->findOrFail($server);

        $endpoint = $record->execute(
            $found,
            BmcProtocol::from($request->string('protocol')->value()),
            $request->string('address')->value(),
            $request->filled('port') ? $request->integer('port') : null,
            $request->input('username'),
            $request->boolean('verify_tls', true),
            $request->input('credentials_reference'),
            $this->operator($request),
        );

        return response()->json([
            'data' => self::describeBmc($endpoint),
        ], Response::HTTP_CREATED);
    }

    public function bmcEndpoint(string $server): JsonResponse
    {
        $found = DedicatedServer::query()->findOrFail($server);

        /** @var BmcEndpoint|null $endpoint */
        $endpoint = BmcEndpoint::query()->where('dedicated_server_id', $found->getKey())->first();

        return response()->json([
            'data' => $endpoint === null ? null : self::describeBmc($endpoint),
        ]);
    }

    /**
     * The fields a cluster form edits, and the fingerprint of them.
     *
     * The `version` is what a second operator's save is checked against, so
     * the list it is computed from has to be exactly the list the form can
     * change — no more, or a reconciler's sync timestamp would invalidate an
     * open form; no less, or a change would slip past unnoticed.
     *
     * @var list<string>
     */
    private const array CLUSTER_EDITABLE = ['name', 'api_endpoint', 'verify_tls', 'credentials_reference', 'status'];

    /** @var list<string> */
    private const array REGION_EDITABLE = ['name', 'city', 'is_active', 'accepts_new_services'];

    /** @var list<string> */
    private const array NETWORK_EDITABLE = ['name', 'vlan_id', 'bridge', 'is_active'];

    /** @var list<string> */
    private const array IP_POOL_EDITABLE = ['name', 'quarantine_days', 'is_active'];

    /** @var list<string> */
    private const array HOSTING_NODE_EDITABLE = [
        'hostname', 'api_endpoint', 'verify_tls', 'credentials_reference',
        'max_accounts', 'accepts_new_accounts', 'status',
    ];

    /**
     * @return array<string, mixed>
     */
    private static function describeCluster(ComputeCluster $cluster): array
    {
        return [
            'id' => (string) $cluster->getKey(),
            'slug' => $cluster->slug,
            'name' => $cluster->name,
            'driver' => $cluster->driver->value,
            'status' => $cluster->status->value,
            'datacenter' => $cluster->datacenter?->slug,
            'datacenter_id' => $cluster->datacenter_id,
            'api_endpoint' => $cluster->api_endpoint,
            'verify_tls' => $cluster->verify_tls,
            // The name of the entry, never what it resolves to.
            'credentials_reference' => $cluster->credentials_reference,
            'accepts_placement' => $cluster->acceptsPlacement(),
            // Null until something actually talked to it: configured is not
            // verified, and this column is where the difference shows.
            'last_synced_at' => $cluster->last_synced_at?->toIso8601String(),
            'nodes' => $cluster->nodes()->count(),
            'templates' => $cluster->templates()->count(),
            'version' => AmendInventory::versionOf($cluster, self::CLUSTER_EDITABLE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeRegion(Region $region): array
    {
        return [
            'id' => (string) $region->getKey(),
            'slug' => $region->slug,
            'name' => $region->name,
            'country' => $region->country,
            'city' => $region->city,
            'is_active' => $region->is_active,
            'accepts_new_services' => $region->accepts_new_services,
            'datacenters' => $region->datacenters()->count(),
            'version' => AmendInventory::versionOf($region, self::REGION_EDITABLE),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeBmc(BmcEndpoint $endpoint): array
    {
        return [
            'id' => (string) $endpoint->getKey(),
            'dedicated_server_id' => $endpoint->dedicated_server_id,
            'protocol' => $endpoint->protocol->value,
            'address' => $endpoint->address,
            'port' => $endpoint->port,
            'username' => $endpoint->username,
            'verify_tls' => $endpoint->verify_tls,
            // The name of the entry, never what it resolves to.
            'credentials_reference' => $endpoint->credentials_reference,
            'last_contacted_at' => $endpoint->last_contacted_at?->toIso8601String(),
        ];
    }

    // ---- corrections -------------------------------------------------------

    /**
     * What may be edited on each object, and what taking it out of service
     * would strand.
     *
     * A map rather than eight methods, because the interesting part is the
     * policy and the policy is short: identity fields (slug, serial, scope,
     * version, datacenter) are absent from every list, because changing one
     * does not correct a row — it makes the row describe a different thing
     * while everything already pointing at it keeps pointing.
     */
    public function updateRegion(Request $request, AmendInventory $amend, string $region): JsonResponse
    {
        $found = Region::query()->findOrFail($region);

        $changes = $this->changesFrom($request, [
            'name' => ['sometimes', 'array'],
            'name.en' => ['required_with:name', 'string', 'max:120'],
            'name.ar' => ['nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'accepts_new_services' => ['sometimes', 'boolean'],
        ]);

        $amend->execute(
            $found,
            $changes,
            AuditAction::RegionUpdated,
            $this->operator($request),
            self::REGION_EDITABLE,
            $request->input('version'),
            /*
             * Switching a region off hides every datacenter in it from every
             * path that reads the estate. Refusing new services is the act
             * that has no dependants and is never blocked.
             */
            $this->takesOutOfService($changes, 'is_active')
                ? static fn (): int => ComputeCluster::query()
                    ->whereIn('datacenter_id', Datacenter::query()->where('region_id', $found->getKey())->select('id'))
                    ->count()
                : null,
            'clusters',
        );

        return response()->json(['data' => self::describeRegion($found->refresh())]);
    }

    public function updateCluster(Request $request, AmendInventory $amend, string $cluster): JsonResponse
    {
        $found = ComputeCluster::query()->findOrFail($cluster);

        $changes = $this->changesFrom($request, [
            'name' => ['sometimes', 'string', 'max:120'],
            'api_endpoint' => ['sometimes', 'nullable', 'string', 'max:255'],
            'verify_tls' => ['sometimes', 'boolean'],
            'credentials_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(ClusterStatus::class)],
        ]);

        if (isset($changes['status'])) {
            $changes['status'] = ClusterStatus::from((string) $changes['status']);
        }

        if (($changes['api_endpoint'] ?? null) !== null) {
            $this->endpoints->assertProviderEndpoint(
                (string) $changes['api_endpoint'],
                controlledDriver: $found->driver === ComputeDriver::Fake,
                onOurHardware: true,
                production: app()->environment('production'),
            );
        }

        $amend->execute(
            $found,
            $changes,
            AuditAction::ComputeClusterUpdated,
            $this->operator($request),
            self::CLUSTER_EDITABLE,
            $request->input('version'),
        );

        return response()->json(['data' => self::describeCluster($found->refresh()->load('datacenter'))]);
    }

    public function updateNetwork(Request $request, AmendInventory $amend, string $network): JsonResponse
    {
        $found = Network::query()->findOrFail($network);

        $changes = $this->changesFrom($request, [
            'name' => ['sometimes', 'string', 'max:120'],
            'vlan_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4094'],
            'bridge' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $amend->execute(
            $found,
            $changes,
            AuditAction::NetworkUpdated,
            $this->operator($request),
            self::NETWORK_EDITABLE,
            $request->input('version'),
            $this->takesOutOfService($changes, 'is_active')
                ? static fn (): int => Subnet::query()
                    ->where('network_id', $found->getKey())
                    ->where('is_active', true)
                    ->count()
                : null,
            'subnets',
        );

        return response()->json([
            'data' => [
                'id' => (string) $found->getKey(),
                'is_active' => $found->refresh()->is_active,
                'version' => AmendInventory::versionOf($found, self::NETWORK_EDITABLE),
            ],
        ]);
    }

    /**
     * The scope is absent from this list on purpose, and the request refuses
     * it rather than ignoring it.
     *
     * Editing a pool from `management` to `public` would move the control
     * plane into the customer estate with a dropdown, and would do it
     * retrospectively: every address already allocated from that pool changes
     * meaning. A pool of the other kind is a new pool.
     */
    public function updateIpPool(Request $request, AmendInventory $amend, string $pool): JsonResponse
    {
        $found = IpPool::query()->findOrFail($pool);

        if ($request->has('scope') || $request->has('ip_version')) {
            throw ValidationException::withMessages([
                'scope' => ['A pool\'s scope and address family are fixed when it is created. Create a new pool instead.'],
            ]);
        }

        $changes = $this->changesFrom($request, [
            'name' => ['sometimes', 'string', 'max:120'],
            'quarantine_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $amend->execute(
            $found,
            $changes,
            AuditAction::IpPoolUpdated,
            $this->operator($request),
            self::IP_POOL_EDITABLE,
            $request->input('version'),
            $this->takesOutOfService($changes, 'is_active')
                ? static fn (): int => IpAddress::query()
                    ->whereIn('subnet_id', Subnet::query()->where('ip_pool_id', $found->getKey())->select('id'))
                    ->whereNotIn('status', [IpAddressStatus::Available->value])
                    ->count()
                : null,
            'addresses',
        );

        return response()->json([
            'data' => [
                'id' => (string) $found->getKey(),
                'scope' => $found->scope->value,
                'is_active' => $found->refresh()->is_active,
            ],
        ]);
    }

    public function updateHostingNode(Request $request, AmendInventory $amend, string $node): JsonResponse
    {
        $found = HostingNode::query()->findOrFail($node);

        $changes = $this->changesFrom($request, [
            'hostname' => ['sometimes', 'string', 'max:255'],
            'api_endpoint' => ['sometimes', 'nullable', 'string', 'max:255'],
            'verify_tls' => ['sometimes', 'boolean'],
            'credentials_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_accounts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'accepts_new_accounts' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(HostingNodeStatus::class)],
        ]);

        if (isset($changes['status'])) {
            $changes['status'] = HostingNodeStatus::from((string) $changes['status']);
        }

        if (($changes['api_endpoint'] ?? null) !== null) {
            $this->endpoints->assertProviderEndpoint(
                (string) $changes['api_endpoint'],
                controlledDriver: $found->panel === HostingPanel::Fake,
                onOurHardware: true,
                production: app()->environment('production'),
            );
        }

        /*
         * The hostname is what gets dialled when the node has no API endpoint,
         * so it is asked about whenever this edit leaves the node in that
         * state and touches either field: a new hostname on a node without an
         * endpoint, or an endpoint cleared on a node whose hostname was never
         * asked about. The create road asks the same question
         * (RegisterHostingNode). A fake panel dials nothing.
         */
        $endpointAfter = array_key_exists('api_endpoint', $changes) ? $changes['api_endpoint'] : $found->api_endpoint;
        $hostnameAfter = (string) ($changes['hostname'] ?? $found->hostname);

        if (
            $found->panel !== HostingPanel::Fake
            && trim((string) $endpointAfter) === ''
            && (array_key_exists('hostname', $changes) || array_key_exists('api_endpoint', $changes))
        ) {
            $this->endpoints->assertMachineAddress($hostnameAfter, production: app()->environment('production'));
        }

        /*
         * Offline means the platform will not reach it. Draining — no new
         * accounts, the existing ones untouched — is the supported way to wind
         * a node down and is never refused.
         */
        $goingOffline = isset($changes['status']) && $changes['status'] === HostingNodeStatus::Offline;

        $amend->execute(
            $found,
            $changes,
            AuditAction::HostingNodeUpdated,
            $this->operator($request),
            self::HOSTING_NODE_EDITABLE,
            $request->input('version'),
            $goingOffline
                ? static fn (): int => HostingAccount::query()->where('hosting_node_id', $found->getKey())->count()
                : null,
            'hosting accounts',
        );

        return response()->json([
            'data' => [
                'id' => (string) $found->getKey(),
                'status' => $found->refresh()->status->value,
                'accepts_new_accounts' => $found->accepts_new_accounts,
            ],
        ]);
    }

    /**
     * Whether a set of changes takes the object out of service.
     *
     * @param  array<string, mixed>  $changes
     */
    private function takesOutOfService(array $changes, string $flag): bool
    {
        return array_key_exists($flag, $changes) && $changes[$flag] === false;
    }

    /**
     * Validates only what was sent, and returns only what was sent.
     *
     * A PUT that filled absent fields with defaults would turn "rename this"
     * into "rename this and reset everything else", which on an estate row is
     * how an endpoint reverts without anybody touching it.
     *
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function changesFrom(Request $request, array $rules): array
    {
        $rules['version'] = ['sometimes', 'string', 'max:64'];

        $validated = $request->validate($rules);

        unset($validated['version']);

        return $validated;
    }

    private function operator(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
