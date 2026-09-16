<?php

declare(strict_types=1);

/*
 * =============================================================================
 * REFERENCE — NON-PRODUCTION — DO NOT DEPLOY — DO NOT USE AS REAL INVENTORY
 * =============================================================================
 *
 * This file is a MODEL OF WHAT A VALID LYNOMIA CLOUD DEPLOYMENT LOOKS LIKE.
 *
 * It is not the real datacenter inventory, it is not a production inventory, it
 * is not a secret store, and it is not evidence that any of the machines,
 * addresses, clusters or providers named below exist. Nothing here is
 * reachable; nothing here may be dialled. It exists so that the software can be
 * exercised against a production-SHAPED estate, and so that an engineer
 * onboarding a real estate can see, field by field, what Lynomia is going to
 * ask them for.
 *
 * -----------------------------------------------------------------------------
 * The three things this file is deliberately NOT
 * -----------------------------------------------------------------------------
 *
 *   1. REAL OPERATIONAL CONFIGURATION. Sites, provider endpoints, credential
 *      references and mappings that a running installation serves from are
 *      managed in the Control Center and live in the database. They never come
 *      from here. See docs/phase-30b-sim-gap-4-reference-topology.md §23.
 *
 *   2. STRUCTURAL INFRASTRUCTURE. What physically gets built — VLANs on real
 *      switches, firewall rules, PXE, cluster membership — lives in the private
 *      inventory and in infrastructure/ansible. Not here.
 *
 *   3. A SECRET STORE. There is not one password, token, key, username or
 *      credential reference below, and a test refuses this file if one appears.
 *
 * -----------------------------------------------------------------------------
 * Why the marker is data rather than a comment
 * -----------------------------------------------------------------------------
 *
 * Every paragraph above is prose, and prose does not stop a deployment. The
 * `production`, `deployable` and `reachable` flags are read by the validator,
 * by the loader, and by the production guard, so "this is not production" is a
 * value the software can refuse to act against rather than a sentence somebody
 * was supposed to read.
 *
 * -----------------------------------------------------------------------------
 * Addresses
 * -----------------------------------------------------------------------------
 *
 * Every address here is in a range reserved for documentation — RFC 5737 for
 * IPv4, RFC 3849 for IPv6 — and every hostname is under `.example`, reserved by
 * RFC 2606. Those are the only address values this file may carry, and the
 * validator refuses any other, because an address that is not obviously
 * documentation is an address somebody might eventually route. In production
 * the same values are refused from the other direction: see
 * {@see \Lynomia\Modules\Shared\Domain\Services\ReferenceValues}.
 *
 * -----------------------------------------------------------------------------
 * Names
 * -----------------------------------------------------------------------------
 *
 * Every logical id is `ref-<what>-<where>`, lower case, deterministic, and
 * prefixed so that a grep for `ref-` finds every one of them — which is how
 * NoReferenceIdentifierIsRequiredByBusinessLogicTest proves that none of these
 * strings is load-bearing anywhere in the application. The estate is called
 * Alpha and its region has ISO country `ZZ`, which is user-assigned and belongs
 * to no country, so the reference estate cannot be mistaken for a real facility
 * in Kuwait, Syria or anywhere else.
 *
 * The naming convention for the rest of the repository is Gap 5 and is not
 * settled here. This scheme is documented, deterministic and local to the
 * reference estate.
 *
 * -----------------------------------------------------------------------------
 * Shape
 * -----------------------------------------------------------------------------
 *
 * Each object is `facts` — what it is — and `refs` — what it hangs off. The
 * split is what lets one validator check the whole graph without a schema per
 * kind: every value in `refs` must name exactly one object, of the kind
 * {@see \Lynomia\Modules\Infrastructure\Domain\Reference\ReferenceKind} expects
 * for that slot. Ids are unique across every kind, not merely within one.
 */

return [
    'kind' => 'lynomia-reference-topology',
    'schema_version' => 1,
    'environment' => 'reference',
    'name' => 'Reference Estate Alpha',

    // Read by the validator, the loader and the production guard. Flipping any
    // of these to true is a deliberate breakage and three tests fail.
    'production' => false,
    'deployable' => false,
    'reachable' => false,

    'objects' => [

        /*
         * ---------------------------------------------------------------------
         * Region, site, rack
         * ---------------------------------------------------------------------
         */

        'region' => [
            'ref-region-alpha' => [
                'facts' => [
                    'name_en' => 'Reference Region Alpha',
                    'name_ar' => 'المنطقة المرجعية ألفا',
                    // ISO 3166-1 user-assigned: belongs to no country, ever.
                    'country' => 'ZZ',
                    'city' => 'Reference City',
                    'is_active' => true,
                    'accepts_new_services' => true,
                ],
                'refs' => [],
            ],
        ],

        'datacenter' => [
            'ref-dc-alpha-1' => [
                'facts' => [
                    'name' => 'Reference Datacenter Alpha 1',
                    'facility' => 'Reference facility. No such building exists.',
                    'is_active' => true,
                ],
                'refs' => ['region' => 'ref-region-alpha'],
            ],
        ],

        'rack' => [
            'ref-rack-alpha-1-a' => [
                'facts' => [
                    'name' => 'RA1',
                    'row' => 'A',
                    'units' => 42,
                    'power_notes' => 'Two feeds modelled. A real rack records its actual circuits here.',
                    'network_notes' => 'Top-of-rack uplinks modelled. Real port numbers come from the private inventory.',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Compute
         * ---------------------------------------------------------------------
         *
         * Three nodes, and each one earns its place: A is the large healthy
         * node, B is smaller so that "the placement engine chose the node with
         * room" is distinguishable from "the placement engine chose the first
         * node", and C is in maintenance so that an ineligible node is part of
         * the estate rather than something a test has to arrange.
         *
         * The cluster driver is `fake` and its endpoint is a fake:// marker. A
         * reference cluster that named the Proxmox driver would be a reference
         * cluster that could be dialled.
         */

        'cluster' => [
            'ref-cluster-alpha-1' => [
                'facts' => [
                    'name' => 'Reference hypervisor cluster Alpha 1',
                    'driver' => 'fake',
                    'endpoint' => 'fake://ref-cluster-alpha-1',
                    'verify_tls' => true,
                    'status' => 'active',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
        ],

        'node' => [
            'ref-node-alpha-1-a' => [
                'facts' => [
                    'provider_name' => 'ref-node-alpha-1-a',
                    'status' => 'active',
                    'cpu_cores' => 32,
                    'memory_mib' => 262_144,
                    'storage_gib' => 4_096,
                    'cpu_overcommit_ratio' => 4.0,
                    'memory_headroom_percent' => 10,
                    'is_healthy' => true,
                    'architecture' => 'x86_64',
                    'nested_virtualisation' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
            ],

            // Deliberately smaller. Equal nodes hide placement bugs.
            'ref-node-alpha-1-b' => [
                'facts' => [
                    'provider_name' => 'ref-node-alpha-1-b',
                    'status' => 'active',
                    'cpu_cores' => 16,
                    'memory_mib' => 131_072,
                    'storage_gib' => 2_048,
                    'cpu_overcommit_ratio' => 4.0,
                    'memory_headroom_percent' => 10,
                    'is_healthy' => true,
                    'architecture' => 'x86_64',
                    'nested_virtualisation' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
            ],

            // The ineligible one. An estate where every node can take work
            // cannot show what happens when one cannot.
            'ref-node-alpha-1-c' => [
                'facts' => [
                    'provider_name' => 'ref-node-alpha-1-c',
                    'status' => 'maintenance',
                    'cpu_cores' => 32,
                    'memory_mib' => 262_144,
                    'storage_gib' => 4_096,
                    'cpu_overcommit_ratio' => 4.0,
                    'memory_headroom_percent' => 10,
                    'is_healthy' => false,
                    'architecture' => 'x86_64',
                    'nested_virtualisation' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Storage
         * ---------------------------------------------------------------------
         *
         * `role` is carried here and is NOT written to the database, because
         * `compute_storages` has no role or content column. That is recorded as
         * a software-closure finding rather than papered over: the reference
         * definition states the three roles a real estate has, and the loader
         * writes only what the table models. See the report, §24.
         */

        'storage' => [
            'ref-storage-alpha-1-a-nvme' => [
                'facts' => [
                    'provider_name' => 'local-nvme',
                    'role' => 'primary_vm',
                    'storage_class' => 'nvme',
                    'shared' => false,
                    'total_gib' => 4_096,
                    'available_gib' => 4_096,
                    'is_active' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1', 'node' => 'ref-node-alpha-1-a'],
            ],
            'ref-storage-alpha-1-b-nvme' => [
                'facts' => [
                    'provider_name' => 'local-nvme',
                    'role' => 'primary_vm',
                    'storage_class' => 'nvme',
                    'shared' => false,
                    'total_gib' => 2_048,
                    'available_gib' => 2_048,
                    'is_active' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1', 'node' => 'ref-node-alpha-1-b'],
            ],
            'ref-storage-alpha-1-c-nvme' => [
                'facts' => [
                    'provider_name' => 'local-nvme',
                    'role' => 'primary_vm',
                    'storage_class' => 'nvme',
                    'shared' => false,
                    'total_gib' => 4_096,
                    'available_gib' => 4_096,
                    'is_active' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1', 'node' => 'ref-node-alpha-1-c'],
            ],

            // Cluster-wide, so no node ref. Shared capacity is counted once for
            // the cluster and not once per node, and having one of each in the
            // reference estate is what keeps that distinction exercised.
            'ref-storage-alpha-1-shared' => [
                'facts' => [
                    'provider_name' => 'shared-pool',
                    'role' => 'primary_vm',
                    'storage_class' => 'ceph',
                    'shared' => true,
                    'total_gib' => 16_384,
                    'available_gib' => 16_384,
                    'is_active' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1'],
            ],

            'ref-storage-alpha-1-images' => [
                'facts' => [
                    'provider_name' => 'image-store',
                    'role' => 'template_image',
                    'storage_class' => 'ssd',
                    'shared' => true,
                    'total_gib' => 2_048,
                    'available_gib' => 2_048,
                    'is_active' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Networks
         * ---------------------------------------------------------------------
         *
         * References, not configuration. A bridge name and a VLAN id are what
         * Lynomia needs in order to ask a hypervisor for an interface; how that
         * VLAN comes to exist on a switch is the private inventory's business.
         *
         * `ref-net-alpha-1-unmapped` deliberately has no bridge, so that "this
         * network has no bridge to attach to" is a state the estate can show
         * without a test having to build one.
         */

        'network' => [
            'ref-net-alpha-1-public' => [
                'facts' => [
                    'slug' => 'ref-public',
                    'name' => 'Reference customer public',
                    'purpose' => 'public',
                    'vlan_id' => 100,
                    'bridge' => 'vmbr0',
                    'is_customer_facing' => true,
                    'is_active' => true,
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
            'ref-net-alpha-1-mgmt' => [
                'facts' => [
                    'slug' => 'ref-management',
                    'name' => 'Reference management',
                    'purpose' => 'management',
                    'vlan_id' => 10,
                    'bridge' => 'vmbr1',
                    'is_customer_facing' => false,
                    'is_active' => true,
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
            'ref-net-alpha-1-storage' => [
                'facts' => [
                    'slug' => 'ref-storage',
                    'name' => 'Reference storage',
                    'purpose' => 'storage',
                    'vlan_id' => 20,
                    'bridge' => 'vmbr2',
                    'is_customer_facing' => false,
                    'is_active' => true,
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
            'ref-net-alpha-1-unmapped' => [
                'facts' => [
                    'slug' => 'ref-unmapped',
                    'name' => 'Reference private, not yet mapped to a bridge',
                    'purpose' => 'private',
                    'vlan_id' => 30,
                    'bridge' => null,
                    'is_customer_facing' => false,
                    'is_active' => false,
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Address pools
         * ---------------------------------------------------------------------
         *
         * `reference_only` is on every object that carries an address. It is not
         * decoration: the validator requires it wherever an address appears, and
         * it is the field that says out loud that these addresses exist to be
         * parsed and never to be reached.
         */

        'ip_pool' => [
            'ref-pool-alpha-1-public-v4' => [
                'facts' => [
                    'slug' => 'ref-public-v4',
                    'name' => 'Reference public IPv4',
                    'ip_version' => 4,
                    'scope' => 'public',
                    'is_active' => true,
                    // Short enough to watch an address leave quarantine in a
                    // working day, long enough that the quarantine is visible.
                    'quarantine_days' => 1,
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
            'ref-pool-alpha-1-public-v6' => [
                'facts' => [
                    'slug' => 'ref-public-v6',
                    'name' => 'Reference public IPv6',
                    'ip_version' => 6,
                    'scope' => 'public',
                    'is_active' => true,
                    'quarantine_days' => 0,
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
            'ref-pool-alpha-1-mgmt-v4' => [
                'facts' => [
                    'slug' => 'ref-management-v4',
                    'name' => 'Reference management IPv4',
                    'ip_version' => 4,
                    'scope' => 'management',
                    'is_active' => true,
                    'quarantine_days' => 0,
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
        ],

        'subnet' => [
            // RFC 5737 TEST-NET-3. A /26 is 61 usable addresses: enough to
            // allocate against repeatedly, small enough to exhaust on purpose.
            'ref-subnet-alpha-1-public-v4' => [
                'facts' => [
                    'cidr' => '198.51.100.0/26',
                    'ip_version' => 4,
                    'prefix_length' => 26,
                    'gateway' => '198.51.100.1',
                    'is_active' => true,
                    'reference_only' => true,
                    'expand_addresses' => true,
                ],
                'refs' => [
                    'ip_pool' => 'ref-pool-alpha-1-public-v4',
                    'network' => 'ref-net-alpha-1-public',
                ],
            ],

            // RFC 3849. IPv6 is delegated as a prefix per service and never
            // enumerated, so this subnet carries no addresses.
            'ref-subnet-alpha-1-public-v6' => [
                'facts' => [
                    'cidr' => '2001:db8:100::/48',
                    'ip_version' => 6,
                    'prefix_length' => 48,
                    'gateway' => null,
                    'is_active' => true,
                    'reference_only' => true,
                    'expand_addresses' => false,
                ],
                'refs' => [
                    'ip_pool' => 'ref-pool-alpha-1-public-v6',
                    'network' => 'ref-net-alpha-1-public',
                ],
            ],

            // RFC 5737 TEST-NET-1, for the management plane.
            'ref-subnet-alpha-1-mgmt-v4' => [
                'facts' => [
                    'cidr' => '192.0.2.0/27',
                    'ip_version' => 4,
                    'prefix_length' => 27,
                    'gateway' => '192.0.2.1',
                    'is_active' => true,
                    'reference_only' => true,
                    'expand_addresses' => false,
                ],
                'refs' => [
                    'ip_pool' => 'ref-pool-alpha-1-mgmt-v4',
                    'network' => 'ref-net-alpha-1-mgmt',
                ],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Templates
         * ---------------------------------------------------------------------
         *
         * The logical key and the provider's own identifier are separate fields
         * on purpose, and that separation is the whole point of §17: business
         * logic asks for `ubuntu-lts`, and what a real Proxmox cluster calls
         * that image is a mapping an operator supplies. The logical keys here
         * are NOT reference identifiers — a real estate keeps them and changes
         * only `provider_reference`.
         *
         * Four templates, because the interesting template states are absence
         * and mismatch, not presence: one installable, one second installable,
         * one for the other architecture, and one that is licensed, inactive and
         * has no provider reference at all.
         */

        'template' => [
            'ref-template-ubuntu-lts' => [
                'facts' => [
                    'slug' => 'ubuntu-lts',
                    'name_en' => 'Ubuntu 24.04 LTS',
                    'name_ar' => 'أوبونتو 24.04 LTS',
                    'os_family' => 'ubuntu',
                    'os_version' => '24.04',
                    'architecture' => 'x86_64',
                    'provider_reference' => 'ref-image-9001',
                    'cloud_init' => true,
                    'guest_agent' => true,
                    'requires_licence' => false,
                    'is_active' => true,
                    'minimum_cpu_cores' => 1,
                    'minimum_memory_mib' => 1_024,
                    'minimum_disk_gib' => 10,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1'],
            ],
            'ref-template-debian-stable' => [
                'facts' => [
                    'slug' => 'debian-stable',
                    'name_en' => 'Debian 13',
                    'name_ar' => 'دبيان 13',
                    'os_family' => 'debian',
                    'os_version' => '13',
                    'architecture' => 'x86_64',
                    'provider_reference' => 'ref-image-9002',
                    'cloud_init' => true,
                    'guest_agent' => true,
                    'requires_licence' => false,
                    'is_active' => true,
                    'minimum_cpu_cores' => 1,
                    'minimum_memory_mib' => 512,
                    'minimum_disk_gib' => 8,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1'],
            ],

            // The other architecture, so that an architecture mismatch is a
            // state of the estate rather than a fixture.
            'ref-template-ubuntu-lts-arm' => [
                'facts' => [
                    'slug' => 'ubuntu-lts-arm64',
                    'name_en' => 'Ubuntu 24.04 LTS (arm64)',
                    'name_ar' => 'أوبونتو 24.04 LTS (arm64)',
                    'os_family' => 'ubuntu',
                    'os_version' => '24.04',
                    'architecture' => 'aarch64',
                    'provider_reference' => 'ref-image-9003',
                    'cloud_init' => true,
                    'guest_agent' => true,
                    'requires_licence' => false,
                    'is_active' => true,
                    'minimum_cpu_cores' => 1,
                    'minimum_memory_mib' => 1_024,
                    'minimum_disk_gib' => 10,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1'],
            ],

            // Licensed, inactive, and with no provider reference: the shape of a
            // template somebody has catalogued and cannot yet install. No image
            // is claimed to have been tested on real Proxmox, here or anywhere.
            'ref-template-windows-unlicensed' => [
                'facts' => [
                    'slug' => 'windows-server',
                    'name_en' => 'Windows Server (no licence recorded)',
                    'name_ar' => 'ويندوز سيرفر (لا يوجد ترخيص مسجل)',
                    'os_family' => 'windows',
                    'os_version' => '2022',
                    'architecture' => 'x86_64',
                    'provider_reference' => null,
                    'cloud_init' => false,
                    'guest_agent' => false,
                    'requires_licence' => true,
                    'licence_note' => 'A Windows image is licensed. The platform records that rather than shipping one.',
                    'is_active' => false,
                    'minimum_cpu_cores' => 2,
                    'minimum_memory_mib' => 4_096,
                    'minimum_disk_gib' => 40,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Machines
         * ---------------------------------------------------------------------
         *
         * A managed machine is the row that says "this is a physical thing we
         * are allowed to touch, and here is how much". The safety class is the
         * load-bearing field and the reference estate carries three different
         * ones deliberately: an estate where every machine is touchable cannot
         * show what the classification is for.
         *
         * There is no username, no password and no credential reference on any
         * machine below. A management address and a BMC address are facts about
         * where a thing is; a credential is how you get in, and it belongs in
         * the credential centre, attached to a provider row, by an operator.
         */

        'machine' => [
            'ref-machine-alpha-1-hv-a' => [
                'facts' => [
                    'name' => 'ref-machine-alpha-1-hv-a',
                    'state' => 'managed',
                    'rack_unit' => 11,
                    'height_units' => 1,
                    'vendor' => 'Reference Systems',
                    'model' => 'RS-1U-C32',
                    'serial' => 'REF-HV-A-0001',
                    'asset_tag' => 'REF-AT-0001',
                    'operating_system' => 'Reference hypervisor 9.0',
                    'management_address' => '192.0.2.11',
                    'management_port' => 22,
                    'bmc_address' => '192.0.2.111',
                    'bmc_port' => 443,
                    'safety_class' => 'discovery_only',
                    'allow_reimage' => false,
                    'safety_reason' => 'Reference machine. Nothing may be built on it because it does not exist.',
                    'reference_only' => true,
                ],
                'refs' => [
                    'datacenter' => 'ref-dc-alpha-1',
                    'rack' => 'ref-rack-alpha-1-a',
                    'compute_node' => 'ref-node-alpha-1-a',
                ],
            ],

            'ref-machine-alpha-1-hv-b' => [
                'facts' => [
                    'name' => 'ref-machine-alpha-1-hv-b',
                    'state' => 'discovered',
                    'rack_unit' => 12,
                    'height_units' => 1,
                    'vendor' => 'Reference Systems',
                    'model' => 'RS-1U-C16',
                    'serial' => 'REF-HV-B-0002',
                    'asset_tag' => 'REF-AT-0002',
                    'operating_system' => 'Reference hypervisor 9.0',
                    'management_address' => '192.0.2.12',
                    'management_port' => 22,
                    'bmc_address' => '192.0.2.112',
                    'bmc_port' => 443,
                    'safety_class' => 'discovery_only',
                    'allow_reimage' => false,
                    'safety_reason' => 'Reference machine. Nothing may be built on it because it does not exist.',
                    'reference_only' => true,
                ],
                'refs' => [
                    'datacenter' => 'ref-dc-alpha-1',
                    'rack' => 'ref-rack-alpha-1-a',
                    'compute_node' => 'ref-node-alpha-1-b',
                ],
            ],

            'ref-machine-alpha-1-hosting-1' => [
                'facts' => [
                    'name' => 'ref-machine-alpha-1-hosting-1',
                    'state' => 'managed',
                    'rack_unit' => 20,
                    'height_units' => 1,
                    'vendor' => 'Reference Systems',
                    'model' => 'RS-1U-H',
                    'serial' => 'REF-HOST-0003',
                    'asset_tag' => 'REF-AT-0003',
                    'operating_system' => 'Reference Linux 10',
                    'management_address' => '192.0.2.20',
                    'management_port' => 22,
                    'bmc_address' => '192.0.2.120',
                    'bmc_port' => 443,
                    'safety_class' => 'configuration_allowed',
                    'allow_reimage' => false,
                    'safety_reason' => 'Reference machine. Configuration is modelled; nothing is applied.',
                    'reference_only' => true,
                ],
                'refs' => [
                    'datacenter' => 'ref-dc-alpha-1',
                    'rack' => 'ref-rack-alpha-1-a',
                    'hosting_node' => 'ref-hosting-alpha-1',
                ],
            ],

            // Classified do_not_touch on purpose. A provider bound to this
            // machine can never become ready, and the readiness engine says so
            // as a hardware blocker rather than sending somebody to a
            // connection test that will be refused. That path needs a machine
            // in the estate to exercise it.
            'ref-machine-alpha-1-locked' => [
                'facts' => [
                    'name' => 'ref-machine-alpha-1-locked',
                    'state' => 'registered',
                    'rack_unit' => 30,
                    'height_units' => 2,
                    'vendor' => 'Reference Systems',
                    'model' => 'RS-2U-S',
                    'serial' => 'REF-DED-0004',
                    'asset_tag' => 'REF-AT-0004',
                    'operating_system' => null,
                    'management_address' => '192.0.2.30',
                    'management_port' => 22,
                    'bmc_address' => '192.0.2.130',
                    'bmc_port' => 443,
                    'safety_class' => 'do_not_touch',
                    'allow_reimage' => false,
                    'safety_reason' => 'Reference machine held at do_not_touch so that the refusal path is part of the estate.',
                    'reference_only' => true,
                ],
                'refs' => [
                    'datacenter' => 'ref-dc-alpha-1',
                    'rack' => 'ref-rack-alpha-1-a',
                ],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Dedicated chassis
         * ---------------------------------------------------------------------
         *
         * `hardware_profile` is catalogue vocabulary and not reference
         * vocabulary: a dedicated plan names the profile it sells, and a chassis
         * says which profile it satisfies. Renaming these to `ref-` would break
         * that match and would model something the real estate will not do.
         *
         * Three of one profile and two of the other. Three matters: with one
         * free machine per profile, a concurrent reservation test cannot tell
         * "waited correctly for the contended one" from "skipped to a spare",
         * and those are different bugs.
         */

        'chassis' => [
            'ref-chassis-alpha-1-01' => [
                'facts' => [
                    'serial' => 'REF-STD-01',
                    'asset_tag' => 'REF-AT-STD-01',
                    'manufacturer' => 'Reference Systems',
                    'model' => 'RS-1U-C32',
                    'hardware_profile' => 'ded-standard-1',
                    'rack_unit' => 11,
                    'height_units' => 1,
                    'status' => 'available',
                    'power_state' => 'off',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
            ],
            'ref-chassis-alpha-1-02' => [
                'facts' => [
                    'serial' => 'REF-STD-02',
                    'asset_tag' => 'REF-AT-STD-02',
                    'manufacturer' => 'Reference Systems',
                    'model' => 'RS-1U-C32',
                    'hardware_profile' => 'ded-standard-1',
                    'rack_unit' => 12,
                    'height_units' => 1,
                    'status' => 'available',
                    'power_state' => 'off',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
            ],
            'ref-chassis-alpha-1-03' => [
                'facts' => [
                    'serial' => 'REF-STD-03',
                    'asset_tag' => 'REF-AT-STD-03',
                    'manufacturer' => 'Reference Systems',
                    'model' => 'RS-1U-C32',
                    'hardware_profile' => 'ded-standard-1',
                    'rack_unit' => 13,
                    'height_units' => 1,
                    // In maintenance, so the estate has a chassis that cannot
                    // be reserved.
                    'status' => 'maintenance',
                    'power_state' => 'off',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
            ],
            'ref-chassis-alpha-1-04' => [
                'facts' => [
                    'serial' => 'REF-STO-01',
                    'asset_tag' => 'REF-AT-STO-01',
                    'manufacturer' => 'Reference Systems',
                    'model' => 'RS-2U-S',
                    'hardware_profile' => 'ded-storage-1',
                    'rack_unit' => 30,
                    'height_units' => 2,
                    'status' => 'available',
                    'power_state' => 'off',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
            ],
            'ref-chassis-alpha-1-05' => [
                'facts' => [
                    'serial' => 'REF-STO-02',
                    'asset_tag' => 'REF-AT-STO-02',
                    'manufacturer' => 'Reference Systems',
                    'model' => 'RS-2U-S',
                    'hardware_profile' => 'ded-storage-1',
                    'rack_unit' => 32,
                    'height_units' => 2,
                    'status' => 'available',
                    'power_state' => 'off',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * BMC
         * ---------------------------------------------------------------------
         *
         * The relationship, and nothing else. Protocol, address, port and
         * whether TLS is verified are structure. A username is half a
         * credential and does not appear; `credentials_reference` stays null,
         * so a reference BMC is a BMC nobody can log into — which is the only
         * safe shape for a committed file.
         *
         * The address is RFC 5737 documentation space and the machine it hangs
         * off is classified, so the readiness engine reports it blocked on
         * credentials and the endpoint policy would refuse it in production.
         */

        'bmc' => [
            'ref-bmc-alpha-1-04' => [
                'facts' => [
                    'protocol' => 'redfish',
                    'address' => '192.0.2.130',
                    'port' => 443,
                    'verify_tls' => true,
                    'vendor_class' => 'Reference Systems BMC',
                    'reference_only' => true,
                ],
                'refs' => ['chassis' => 'ref-chassis-alpha-1-04'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Hosting
         * ---------------------------------------------------------------------
         *
         * The panel is `fake`, never cPanel or DirectAdmin. Both are licensed
         * products and both would be dialled for real by the adapter, and the
         * licence state is recorded as not_applicable rather than as licensed,
         * because saying a fake panel holds a licence is a claim about a product
         * that is not there.
         *
         * `package` rows name the plan they serve by catalogue slug rather than
         * by reference id. The plan is what a customer bought; the package is
         * what the panel is told to create. They stay separate rows so that
         * renaming a plan never changes the argument passed to createacct.
         */

        'hosting_node' => [
            'ref-hosting-alpha-1' => [
                'facts' => [
                    'slug' => 'ref-hosting-alpha-1',
                    'hostname' => 'ref-hosting-alpha-1.reference.example',
                    'panel' => 'fake',
                    'panel_version' => '0.0.0-reference',
                    'api_endpoint' => 'fake://ref-hosting-alpha-1',
                    'verify_tls' => true,
                    'status' => 'active',
                    'accepts_new_accounts' => true,
                    'panel_licensed' => false,
                    'licence_status' => 'not_applicable',
                    'cloudlinux' => false,
                    'litespeed' => false,
                    'max_accounts' => 200,
                    'disk_total_mib' => 2_097_152,
                    'reference_only' => true,
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
        ],

        'hosting_package' => [
            'ref-package-alpha-starter' => [
                'facts' => ['slug' => 'ref-pkg-starter', 'plan_slug' => 'hosting-starter', 'panel_package_name' => 'ref_starter', 'is_active' => true],
                'refs' => ['hosting_node' => 'ref-hosting-alpha-1'],
            ],
            'ref-package-alpha-business' => [
                'facts' => ['slug' => 'ref-pkg-business', 'plan_slug' => 'hosting-business', 'panel_package_name' => 'ref_business', 'is_active' => true],
                'refs' => ['hosting_node' => 'ref-hosting-alpha-1'],
            ],
            'ref-package-alpha-agency' => [
                'facts' => ['slug' => 'ref-pkg-agency', 'plan_slug' => 'hosting-agency', 'panel_package_name' => 'ref_agency', 'is_active' => true],
                'refs' => ['hosting_node' => 'ref-hosting-alpha-1'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Providers
         * ---------------------------------------------------------------------
         *
         * The reference estate does NOT carry its own provider state. These rows
         * reference the existing provider-instance concept and nothing more, and
         * every one of them names a driver from
         * {@see \Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue::controlledDrivers()}.
         *
         * There are exactly two of them, and the reason is worth reading rather
         * than working around: the catalogue has one controlled driver
         * catalogued as DNS and one catalogued as BMC. So a reference estate can
         * rehearse a complete provider lifecycle for DNS and for BMC and for
         * nothing else. Compute and hosting carry their relationship on the
         * cluster's `driver` and the node's `panel` instead, which is this
         * repository's older per-module provider model and is equally real.
         * Backup, registrar and payment have no controlled driver at all, so
         * they appear below as declared dependencies rather than as rows.
         *
         * That is a limitation of the simulators and not of this file. It is
         * recorded in the report as a Gap 6 finding and is not fixed here.
         *
         * `environment` is `development` on both. A provider row declared for
         * production may not answer with a fake, and the reference estate must
         * never be able to become the production estate by one edit.
         *
         * The BMC provider is bound to the hypervisor machine rather than to
         * the chassis that has the `bmc` object below, and that is deliberate:
         * bound to the do_not_touch machine it would be permanently
         * hardware-blocked, and the readiness engine would report the
         * classification instead of the missing credential. One of each is
         * wanted, and the locked machine already covers the refusal path.
         *
         * The two BMC representations are not duplicates. A hypervisor's BMC is
         * recorded as `bmc_address` on the managed machine — this repository's
         * estate model — and a dedicated chassis's BMC is a `bmc_endpoints` row,
         * which is the Dedicated module's own. Both exist in the code and both
         * are modelled here rather than one being declared the right one.
         */

        'provider' => [
            'ref-provider-alpha-dns' => [
                'facts' => [
                    'name' => 'ref-provider-alpha-dns',
                    'category' => 'dns',
                    'driver' => 'fake',
                    'environment' => 'development',
                    'endpoint' => 'fake://ref-dns-alpha',
                    'notes' => 'Reference DNS provider. Controlled driver; refuses to exist in production.',
                ],
                'refs' => [],
            ],
            'ref-provider-alpha-bmc' => [
                'facts' => [
                    'name' => 'ref-provider-alpha-bmc',
                    'category' => 'bmc',
                    'driver' => 'fake_bmc',
                    'environment' => 'development',
                    'endpoint' => 'fake://ref-bmc-alpha-1',
                    'notes' => 'Reference BMC provider, bound to a classified reference machine.',
                ],
                'refs' => ['machine' => 'ref-machine-alpha-1-hv-a'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Backup
         * ---------------------------------------------------------------------
         *
         * Repository truth, not the original brief's model: this platform's
         * backup driver speaks the Proxmox VE contract, and there is no separate
         * PBS abstraction to force one into. So the backup relationship is
         * expressed as the datastore reference and the verification state that a
         * real deployment supplies, hung off the cluster whose VMs are archived.
         *
         * `datastore` has no column anywhere: `backups` records archives, not
         * the store they sit in. It is carried here because it is one of the
         * exact values a real onboarding has to provide, and the report records
         * the missing model rather than inventing a table for it.
         *
         * `verification` is `unknown` and may never be anything else in this
         * file. A restore that has not been attempted is not a restore that
         * works, and the reference estate is not permitted to claim otherwise.
         */

        'backup_target' => [
            'ref-backup-alpha-1' => [
                'facts' => [
                    'datastore' => 'ref-datastore-alpha-1',
                    'retention_days' => 14,
                    'verification' => 'unknown',
                    'verification_note' => 'No restore has been attempted against this target, because there is nothing to restore from.',
                    'reference_only' => true,
                ],
                'refs' => ['cluster' => 'ref-cluster-alpha-1'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Monitoring
         * ---------------------------------------------------------------------
         *
         * What the software should be able to answer: which things are meant to
         * be observed, by which Prometheus job, and which of this application's
         * own collectors carry the series. `collectors` names classes in
         * {@see \Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry} and
         * the validator fails if one of them does not exist — the same failure
         * mode Gap 3 found, where alert rules read series that nothing exported.
         *
         * `prometheus_job` names a job in infrastructure/monitoring/prometheus,
         * and the validator checks that too. No metric values appear here: a
         * reference estate that shipped numbers would be a reference estate
         * somebody quoted.
         */

        'monitoring_target' => [
            'ref-monitor-alpha-1-cluster' => [
                'facts' => [
                    'prometheus_job' => 'proxmox',
                    'collectors' => ['CapacityCollector'],
                    'expected' => 'Cluster and node capacity, per node and per storage pool.',
                    'simulation_only' => true,
                ],
                'refs' => ['observes' => 'ref-cluster-alpha-1'],
            ],
            'ref-monitor-alpha-1-nodes' => [
                'facts' => [
                    'prometheus_job' => 'node',
                    'collectors' => ['CapacityCollector', 'ServicesCollector'],
                    'expected' => 'Host-level metrics for each managed machine in the rack.',
                    'simulation_only' => true,
                ],
                'refs' => ['observes' => 'ref-rack-alpha-1-a'],
            ],
            'ref-monitor-alpha-1-backups' => [
                'facts' => [
                    'prometheus_job' => 'control-plane',
                    'collectors' => ['BackupCollector'],
                    'expected' => 'Backup age, failure count and verification state. The series Gap 1 added and Gap 3 found unregistered.',
                    'simulation_only' => true,
                ],
                'refs' => ['observes' => 'ref-backup-alpha-1'],
            ],
            'ref-monitor-alpha-1-hosting' => [
                'facts' => [
                    'prometheus_job' => 'blackbox-http',
                    'collectors' => ['ServicesCollector'],
                    'expected' => 'Reachability of the hosting panel from outside.',
                    'simulation_only' => true,
                ],
                'refs' => ['observes' => 'ref-hosting-alpha-1'],
            ],
        ],

        /*
         * ---------------------------------------------------------------------
         * Declared dependencies
         * ---------------------------------------------------------------------
         *
         * DNS, registrar and payment are provider dependencies rather than
         * physical topology, and the estate states that it needs them without
         * fabricating an endpoint or a credential for any of them. `satisfied_by`
         * is a ref to a reference provider row where one can exist and is
         * absent where no controlled driver exists — which is how the reference estate says
         * "this dependency is real and cannot be rehearsed here" rather than
         * quietly looking complete.
         */

        'dependency' => [
            'ref-dep-alpha-dns' => [
                'facts' => [
                    'requires' => 'dns',
                    'why' => 'A VPS or a hosting account needs a name published before a customer can reach it.',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1', 'satisfied_by' => 'ref-provider-alpha-dns'],
            ],
            'ref-dep-alpha-bmc' => [
                'facts' => [
                    'requires' => 'bmc',
                    'why' => 'A dedicated server is sold on the ability to power it and to reinstall it out of band.',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1', 'satisfied_by' => 'ref-provider-alpha-bmc'],
            ],
            'ref-dep-alpha-backup' => [
                'facts' => [
                    'requires' => 'backup',
                    'why' => 'A VPS is sold with backups, and a backup provider that cannot restore is not a backup provider.',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
            'ref-dep-alpha-registrar' => [
                'facts' => [
                    'requires' => 'registrar',
                    'why' => 'A domain cannot be registered, renewed or transferred without one.',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
            'ref-dep-alpha-payment' => [
                'facts' => [
                    'requires' => 'payment',
                    'why' => 'Nothing is sold until money can be taken and given back.',
                ],
                'refs' => ['datacenter' => 'ref-dc-alpha-1'],
            ],
        ],
    ],
];
