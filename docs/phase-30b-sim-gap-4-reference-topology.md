# Phase 30B-SIM — Gap 4 of 8: reference production topology

**REFERENCE — NON-PRODUCTION — ZERO REAL CLAIMS**

REAL_INFRA_VERIFIED: **NONE** · REAL_PAYMENT_VERIFIED: **NONE** ·
REAL_REGISTRAR_VERIFIED: **NONE** · REAL_HOSTING_VERIFIED: **NONE** ·
READY_TO_SELL: **NONE**

---

## 1. Provenance

| | |
| --- | --- |
| Repository | `fullstackfull/cloud` |
| Branch | `claude/hv-t6hq1p` |
| Gap | 30B-SIM 4 of 8 — reference production topology |
| Preceding gaps | 1 (backup metrics) CLOSED · 2 (provider identity testers) CLOSED · 3 (unified preflight) CLOSED |

This document describes what was built, what was measured, what was found, and
what is narrower than the brief allows. Every claim in it is either a file path,
a test name, or a number produced by running something.

## 2. Entry CI

The entry gate required GitHub Actions run **169**, id **35130068313**, at SHA
`6e1ed764a9140fb22c7bebd56e1aedaf25ac75ca`, to be `completed` / `success` with
every expected job green.

At first inspection the run was `in_progress`: eight of nine jobs had finished
green and "Browser end-to-end" was still executing its suite. Reporting
NOT STARTED at that point would have been accurate about the API response and
wrong about the state of the branch, so the run was re-checked to completion and
only the read-only audit in §4 was performed meanwhile. No repository file was
changed until the gate was green.

Final state: **completed / success, 9 of 9 jobs.**

| Job | Conclusion |
| --- | --- |
| API description | success |
| Security checks | success |
| Backend (PHP 8.4, PostgreSQL 16) | success |
| Backend (PHP 8.4, PostgreSQL 18) | success |
| Frontend | success |
| Browser end-to-end | success (completed 18:16:17Z) |
| Static analysis | success |
| Production guards | success |
| Infrastructure validation | success |

No documentation-only commit was made to record it.

## 3. Starting and ending SHA

| | |
| --- | --- |
| Starting HEAD | `6e1ed764a9140fb22c7bebd56e1aedaf25ac75ca` |
| Ending HEAD | recorded in the commit that carries this document |

## 4. Current-state topology audit

The audit ran before any code changed. It asked one question: **does this
repository already model the thing, and if so, where does the fact live?**

### 4.1 Source matrix

| Concept | Current source | Structural or operational | Used by | Reference value existed? | Production value hardcoded? | Action |
| --- | --- | --- | --- | --- | --- | --- |
| Region | `Region` model (`regions`) | operational | catalogue, placement | yes, in the dev seeder | no | **reuse**, derive from the definition |
| Datacenter / site | `Datacenter` (`datacenters`) | operational | preflight `site` scope, IPAM, hosting | yes, in the dev seeder | no | **reuse** |
| Rack | `Rack` (`racks`) | operational | dedicated inventory | yes, in the dev seeder | no | **reuse** |
| Compute cluster | `ComputeCluster` (`compute_clusters`) | operational | VPS provisioning | yes, driver `fake` | no | **reuse** |
| Compute node | `ComputeNode` (`compute_nodes`) | operational | placement, capacity | yes, 2 nodes | no | **reuse**, extend to 3 |
| Storage | `ComputeStorage` (`compute_storages`) | operational | placement | yes | no | **reuse** |
| Network / bridge / VLAN | `Network` (`networks`) | operational | VM attachment | yes, one public network | no | **reuse**, extend to 4 |
| IP pool / subnet | `IpPool`, `Subnet`, `IpAddress` | operational | allocation | yes, 198.51.100.0/26 + 2001:db8:100::/48 | no | **reuse** |
| VM template | `VmTemplate` (`vm_templates`) | operational | VPS create, reinstall | yes, 4 templates | no | **reuse** |
| Managed machine | `ManagedServer` (`managed_servers`) | operational | safety class, discovery, deployment | **no** | no | **reuse the model, add reference rows** |
| Dedicated chassis | `DedicatedServer` (`dedicated_servers`) | operational | dedicated sales | yes, 5 chassis | no | **reuse** |
| BMC | `BmcEndpoint` (`bmc_endpoints`) | operational | power, reinstall | **no** (deliberately absent) | no | **reuse the model, add one reference row without credentials** |
| Hosting node | `HostingNode` (`hosting_nodes`) | operational | shared hosting | yes, panel `fake` | no | **reuse** |
| Hosting package | `HostingPackage` (`hosting_packages`) | operational | account creation | yes, 3 packages | no | **reuse** |
| Provider instance | `ProviderInstance` (`provider_instances`) | operational | the whole 30B-P estate model | **no** | no | **reuse the model, add reference rows** |
| Provider capability | `ProviderCapability` | operational | readiness | no | no | **not written** — a capability is discovered, never declared |
| Credential reference | `CredentialReference` | operational | readiness, connection tests | no | no | **never written by reference tooling** |
| Licence | `Licence` | operational | readiness | no | no | **never written by reference tooling** |
| Desired state / plan / approval | `DesiredState`, `DeploymentPlan`, `DeploymentApproval` | operational | execution chain | no | no | **out of scope**: a reference estate is not a change to apply |
| Product readiness | `ProductReadiness`, `ProductRequirements` | operational | sellability | n/a | no | **reuse, read only** |
| Monitoring targets | `infrastructure/monitoring/prometheus/targets/*.yml` + 16 collectors | structural + operational | scraping, alerting | yes, in the Prometheus files | no | **reuse both**, reference the collector names |
| Ansible inventories | `infrastructure/ansible/inventories/{development,staging,production}/hosts.yml` | structural | playbooks | yes, `.prod.example` + 203.0.113/24 | no | **left alone** (Gap 5 owns the naming) |
| Safety classification | `safety_class` on hosts and machines, `validate-inventory.py` | structural + operational | every destructive path | yes | no | **reuse** |

### 4.2 What the audit concluded

**The development seeder was the de facto reference topology.** All 403 lines of
`database/seeders/InfrastructureSeeder.php` described an estate — one region, one
datacenter, a two-node cluster, storage, a subnet, a hosting node, a rack of five
chassis — with the facts inline across eight private methods. It was careful and
correct: driver `fake`, panel `fake`, RFC 5737 addresses, `.test` hostnames, no
credentials, and a docblock explaining each choice.

It was also authoritative **by accident**, and a seeder is the wrong place for
something authoritative. It cannot be read by a test, validated in CI, shown to
somebody onboarding a real estate, or scanned for secrets. That is the gap Gap 4
closes, and the answer is not a new domain: it is to move the facts one level
out, into a declarative definition the seeder consumes.

**Nothing needed a new model.** Every dimension §8 asks for already had one,
except three that have no column anywhere and are recorded in §24 rather than
invented into a table.

**Zero production values were hardcoded** anywhere in application source. The
grep matrix from §4 of the brief found `example.com` in 72 files, 203.0.113 in
57, 198.51.100 in 55 and 192.0.2 in 40 — all in tests, fixtures, example
inventories and documentation, which is what those ranges exist for.

## 5. Canonical reference source

**`apps/control-plane/resources/reference-topology/topology.php`** — one
declarative PHP file, 1,096 lines, returning a nested array. Beside it,
`README.md` restates the rules for a reader who arrives at the directory first.

PHP rather than YAML because the control plane has **no YAML parser**:
`symfony/yaml` is not in `composer.json` and not in `vendor/`, and §25 says not
to add a parser dependency without necessity. A declarative PHP array is
human-reviewable, needs no parser, is checked by `php -l`, and matches the
repository's existing convention for declarative data (`resources/openapi/*.php`
is the same shape). The Python-based infrastructure validators keep reading YAML,
because that is what Ansible speaks.

Consumers, all of which derive rather than restate:

| Consumer | How it reads the definition |
| --- | --- |
| `InfrastructureSeeder` | calls the loader; the seeder now holds no estate facts at all |
| `LoadReferenceTopologyForSimulation` | asks for objects **by kind** and reads their facts; contains no reference identifier |
| `ReferenceTopologyValidator` | validates the raw array |
| `TheReferenceTopologyIsAModelAndNotAnInventoryTest` | loads it and asserts against it |
| `NoReferenceIdentifierIsRequiredByBusinessLogicTest` | takes the id list from it and greps source for each |
| this document | quotes it |

### 5.1 Shape

Each object is `facts` (what it is) and `refs` (what it hangs off), grouped by
kind:

```php
'node' => [
    'ref-node-alpha-1-a' => [
        'facts' => ['provider_name' => 'ref-node-alpha-1-a', 'status' => 'active', 'cpu_cores' => 32, ...],
        'refs'  => ['cluster' => 'ref-cluster-alpha-1', 'rack' => 'ref-rack-alpha-1-a'],
    ],
],
```

The split is the whole schema, and it is what lets **one** validator check
**every** kind without a class per kind: every value in `refs` must name exactly
one object, of the kind `ReferenceKind::refSlots()` expects for that slot. Ids
are unique across kinds, not merely within one, because a ref names an id and
nothing else.

`ReferenceKind` carries the schema — `requiredFacts()`, `refSlots()`,
`carriesAddresses()` — beside the kind's own name, so the shape of the data and
the name of the data cannot drift.

### 5.2 Scale

52 objects across 19 kinds. Production-shaped, and small enough to read in one
sitting:

| Kind | Count | | Kind | Count |
| --- | --- | --- | --- | --- |
| region | 1 | | machine | 4 |
| datacenter | 1 | | chassis | 5 |
| rack | 1 | | bmc | 1 |
| cluster | 1 | | hosting_node | 1 |
| node | 3 | | hosting_package | 3 |
| storage | 5 | | provider | 2 |
| network | 4 | | backup_target | 1 |
| ip_pool | 3 | | monitoring_target | 4 |
| subnet | 3 | | dependency | 5 |
| template | 4 | | | |

## 6. Reference and non-production markers

Machine-readable, at the root of the file, and read by three different pieces of
code rather than by a reader:

```php
'kind' => 'lynomia-reference-topology',
'schema_version' => 1,
'environment' => 'reference',
'production' => false,
'deployable' => false,
'reachable' => false,
```

| Field | Read by | What happens when it is wrong |
| --- | --- | --- |
| `kind` | the validator | the whole file is rejected |
| `environment` | the validator | rejected |
| `production` | validator + loader | rejected, and the loader refuses even if handed the array directly |
| `deployable` | validator + loader | rejected |
| `reachable` | validator + loader | rejected |

Prose markers are present too — the file opens with
`REFERENCE — NON-PRODUCTION — DO NOT DEPLOY — DO NOT USE AS REAL INVENTORY`, and
`ReferenceTopology::label()` returns `REFERENCE — NON-PRODUCTION` for anything
that displays the estate. But prose does not stop a deployment, which is why the
three flags are values the software refuses to act against.

## 7. Region, site and rack

| Object | Value | Why |
| --- | --- | --- |
| `ref-region-alpha` | Reference Region Alpha, country **`ZZ`**, city "Reference City" | `ZZ` is ISO 3166-1 user-assigned and belongs to no country, ever. The estate therefore cannot be mistaken for a facility in Kuwait, Syria or anywhere else. |
| `ref-dc-alpha-1` | Reference Datacenter Alpha 1, facility "Reference facility. No such building exists." | No address, no real facility name. |
| `ref-rack-alpha-1-a` | RA1, row A, 42U, with power and network notes saying a real rack records its actual circuits here | The notes are the onboarding hint, not an assertion. |

The previous development estate named a **Kuwait Central** region in **Kuwait
City** with country `KW`. That was a development fixture and not a claim, but it
is precisely the shape §9 forbids, and it is gone.

## 8. Compute

One cluster, `ref-cluster-alpha-1`, driver `fake`, endpoint
`fake://ref-cluster-alpha-1`, `verify_tls: true`, no credential reference.

Three nodes, and each earns its place — a three-node estate is the smallest one
in which the interesting failures are distinguishable:

| Node | Status | CPU | RAM | Disk | Why it is there |
| --- | --- | --- | --- | --- | --- |
| `ref-node-alpha-1-a` | active, healthy | 32 | 256 GiB | 4 TiB | the large healthy node |
| `ref-node-alpha-1-b` | active, healthy | 16 | 128 GiB | 2 TiB | **deliberately smaller**, so "chose the node with room" is distinguishable from "chose the first node" |
| `ref-node-alpha-1-c` | maintenance, unhealthy | 32 | 256 GiB | 4 TiB | an ineligible node that is part of the estate rather than something a test arranges |

Not overbuilt: three nodes, one cluster, one site. §29 asks for rich enough to
expose bugs and small enough to understand, and the second constraint is the one
that is easy to lose.

## 9. Storage

Five pools across three roles:

| Object | Role | Class | Shared | Node | Capacity |
| --- | --- | --- | --- | --- | --- |
| `ref-storage-alpha-1-a-nvme` | primary_vm | nvme | no | node A | 4 TiB |
| `ref-storage-alpha-1-b-nvme` | primary_vm | nvme | no | node B | 2 TiB |
| `ref-storage-alpha-1-c-nvme` | primary_vm | nvme | no | node C | 4 TiB |
| `ref-storage-alpha-1-shared` | primary_vm | ceph | yes | — | 16 TiB |
| `ref-storage-alpha-1-images` | template_image | ssd | yes | — | 2 TiB |

`nvme`, `ssd` and `ceph` are all values of the existing `StorageClass` enum, so
nothing is invented. The shared pool has no node reference, which is how the
schema says "the whole cluster" — having one of each in the estate is what keeps
the per-node/per-cluster capacity distinction exercised.

`role` has **no column**: `compute_storages` models class, sharing and capacity
and not content or role. It is carried in the definition because a real
onboarding has to state it, and it is dropped by the loader rather than invented
into a table. Recorded in §24.

## 10. Network

Four networks, and the fourth is the interesting one:

| Object | Purpose | VLAN | Bridge | Customer facing |
| --- | --- | --- | --- | --- |
| `ref-net-alpha-1-public` | public | 100 | vmbr0 | yes |
| `ref-net-alpha-1-mgmt` | management | 10 | vmbr1 | no |
| `ref-net-alpha-1-storage` | storage | 20 | vmbr2 | no |
| `ref-net-alpha-1-unmapped` | private | 30 | **null** | no |

`ref-net-alpha-1-unmapped` deliberately has no bridge, so "this network has no
bridge to attach to" is a state of the estate rather than a fixture a test has to
build.

Address pools, all in documentation space, all stating `reference_only: true`:

| Object | CIDR | Gateway | Addresses |
| --- | --- | --- | --- |
| `ref-subnet-alpha-1-public-v4` | 198.51.100.0/26 (RFC 5737 TEST-NET-2) | 198.51.100.1 | expanded — 64 rows, 61 allocatable |
| `ref-subnet-alpha-1-public-v6` | 2001:db8:100::/48 (RFC 3849) | — | not enumerated; v6 is delegated as a prefix per service |
| `ref-subnet-alpha-1-mgmt-v4` | 192.0.2.0/27 (RFC 5737 TEST-NET-1) | 192.0.2.1 | not expanded |

No firewall rule, routing table or switch configuration appears anywhere. That
belongs to the private inventory and to `infrastructure/ansible`, and §14 says so.

## 11. Templates

The logical key and the provider's own identifier are separate fields, which is
the whole point of §17: business logic asks for `ubuntu-lts`, and what a real
Proxmox cluster calls that image is a mapping an operator supplies.

| Object | Logical key | Provider ref | OS | Arch | Active | Why |
| --- | --- | --- | --- | --- | --- | --- |
| `ref-template-ubuntu-lts` | `ubuntu-lts` | `ref-image-9001` | Ubuntu 24.04 | x86_64 | yes | installable |
| `ref-template-debian-stable` | `debian-stable` | `ref-image-9002` | Debian 13 | x86_64 | yes | a second installable |
| `ref-template-ubuntu-lts-arm` | `ubuntu-lts-arm64` | `ref-image-9003` | Ubuntu 24.04 | **aarch64** | yes | architecture mismatch as a state of the estate |
| `ref-template-windows-unlicensed` | `windows-server` | **null** | Windows 2022 | x86_64 | **no** | licensed, inactive, no provider reference: a template somebody catalogued and cannot install |

The logical keys are **not** reference identifiers — a real estate keeps
`ubuntu-lts` and changes only `provider_reference`. That is why the architecture
gate scans for `ref-…` ids and not for template slugs.

No image is claimed to have been tested on real Proxmox, here or anywhere.

`minimum_cpu_cores`, `minimum_memory_mib` and `minimum_disk_gib` are carried and
have **no column**: `vm_templates` does not model minimum resources. Recorded in
§24.

## 12. Backup

Repository truth, not the original brief's model. Gap 2 established that this
platform's backup driver speaks the Proxmox VE contract and that there is no
separate PBS abstraction to force one into, so the backup relationship is
expressed as the datastore reference and verification state a real deployment
supplies, hung off the cluster whose VMs are archived:

```php
'ref-backup-alpha-1' => [
    'facts' => ['datastore' => 'ref-datastore-alpha-1', 'retention_days' => 14,
                'verification' => 'unknown', 'reference_only' => true],
    'refs'  => ['cluster' => 'ref-cluster-alpha-1'],
],
```

`verification` is `unknown` and a test asserts it stays that way. A restore that
has not been attempted is not a restore that works, and the reference estate is
not permitted to claim otherwise.

The monitoring side of the relationship is wired: `ref-monitor-alpha-1-backups`
observes this target and names `BackupCollector`, the collector Gap 1 wrote and
Gap 3 found was never loaded.

`datastore` has **no column** — `backups` records archives, not the store they
sit in. Recorded in §24.

## 13. Dedicated and BMC

Five chassis, two hardware profiles, one in maintenance:

| Object | Serial | Profile | Status |
| --- | --- | --- | --- |
| `ref-chassis-alpha-1-01` … `-03` | REF-STD-01..03 | `ded-standard-1` | available, available, **maintenance** |
| `ref-chassis-alpha-1-04`, `-05` | REF-STO-01..02 | `ded-storage-1` | available |

Three of one profile matters: with a single free machine per profile, a
concurrent reservation test cannot tell "waited correctly for the contended one"
from "skipped to a spare", and those are different bugs.

`hardware_profile` is **catalogue** vocabulary, not reference vocabulary — a
dedicated plan names the profile it sells and a chassis says which profile it
satisfies. Renaming these to `ref-` would break that match and model something
the real estate will not do.

One BMC, `ref-bmc-alpha-1-04`: protocol `redfish`, address `192.0.2.130` (RFC
5737), port 443, `verify_tls: true`, vendor class "Reference Systems BMC",
`reference_only: true`.

**No username. No password. No token. No credential reference.** The loader
writes `username => null` and `credentials_reference => null` explicitly, and the
validator refuses the file outright if a field named `username`, `password`,
`token`, `credential` or a dozen others appears anywhere in it. A reference BMC
is a BMC nobody can log into, which is the only safe shape for something
committed to git.

Four managed machines carry three different safety classifications, because an
estate where everything is touchable cannot show what the classification is for:

| Machine | State | Safety class | Bound to |
| --- | --- | --- | --- |
| `ref-machine-alpha-1-hv-a` | managed | discovery_only | compute node A |
| `ref-machine-alpha-1-hv-b` | discovered | discovery_only | compute node B |
| `ref-machine-alpha-1-hosting-1` | managed | configuration_allowed | hosting node |
| `ref-machine-alpha-1-locked` | registered | **do_not_touch** | — |

`allow_reimage` is false on all four and a test asserts no reference machine is
ever `reimage_allowed`. A reimage flag that lives permanently in an inventory is
not a guard; it is a default with extra steps.

## 14. Hosting

One node, `ref-hosting-alpha-1`: panel `fake` (never cPanel or DirectAdmin —
both are licensed and both would be dialled for real by the adapter), hostname
`ref-hosting-alpha-1.reference.example`, endpoint `fake://ref-hosting-alpha-1`,
`panel_licensed: false`, `licence_status: not_applicable`.

Recording a fake panel as licensed would be a claim about a product that is not
there. Recording it as `not_applicable` is the true statement, and it is what
makes the licence dependency visible rather than satisfied.

Three packages — `ref-pkg-starter`, `ref-pkg-business`, `ref-pkg-agency` — each
naming the catalogue plan it serves by **plan slug** rather than by reference id.
Quotas come from the plan, because they are a product decision the catalogue
already owns. A package whose plan is absent is skipped, so the estate loads with
or without the catalogue: loading it alone writes 39 rows, loading it after the
catalogue writes 42. The three packages load **withdrawn** (`is_active: false`):
the catalogue seeder already puts a package on sale behind each of those plans,
and the platform refuses to choose between two packages on sale for one plan
(F-32), so on-sale reference packages would leave the development catalogue
unable to sell hosting at all.

The definition states which node offers which package. `hosting_packages` has no
node column — packages are global — so the loader writes the package and drops
that relationship. With one reference node it is immaterial; it is recorded in
§24 because a two-node hosting estate would need it.

## 15. Monitoring

Four targets, each naming the Prometheus job that scrapes it and the collectors
of this application that carry the series:

| Target | Observes | Prometheus job | Collectors |
| --- | --- | --- | --- |
| `ref-monitor-alpha-1-cluster` | the cluster | `proxmox` | `CapacityCollector` |
| `ref-monitor-alpha-1-nodes` | the rack | `node` | `CapacityCollector`, `ServicesCollector` |
| `ref-monitor-alpha-1-backups` | the backup target | `control-plane` | `BackupCollector` |
| `ref-monitor-alpha-1-hosting` | the hosting node | `blackbox-http` | `ServicesCollector` |

So the software can answer §22's three questions — which things are meant to be
observed, by which job, and which collectors apply — from the definition rather
than from somebody's memory.

**The validator refuses a target naming a collector this application does not
have.** That check exists because of Gap 3's finding: alert rules were reading
series that nothing exported, and a rule over an absent series does not fail — it
evaluates to an empty vector, which is indistinguishable from passing. The
reference estate is not allowed to make the same claim.

No metric values appear anywhere. Every target states `simulation_only: true`.

## 16. Provider relationships

The reference estate carries **no provider state of its own**. It references the
existing `ProviderInstance` concept and nothing more, and there are exactly two
rows:

| Object | Category | Driver | Environment | Endpoint | Machine |
| --- | --- | --- | --- | --- | --- |
| `ref-provider-alpha-dns` | dns | `fake` | development | `fake://ref-dns-alpha` | — |
| `ref-provider-alpha-bmc` | bmc | `fake_bmc` | development | `fake://ref-bmc-alpha-1` | `ref-machine-alpha-1-hv-a` |

Two, and the reason is worth reading rather than working around.
`ProviderCatalogue::controlledDrivers()` returns exactly `['fake', 'fake_bmc']`,
and the catalogue has the first catalogued as **DNS** and the second as **BMC**.
So a reference estate can rehearse a complete provider lifecycle for DNS and for
BMC and **for nothing else** — because §16 forbids pointing simulation at
documentation endpoints, and the only alternative would be to name a real driver.

Compute and hosting carry their provider relationship on the cluster's `driver`
and the hosting node's `panel` instead. That is this repository's older
per-module provider model and it is equally real; both models exist and both are
reused rather than merged.

Backup, registrar and payment have no controlled driver at all, so they appear as
**declared dependencies** rather than as rows — which is exactly what §23 asks
for, and which says "this dependency is real and cannot be rehearsed here"
instead of quietly looking complete:

| Dependency | Requires | Satisfied by |
| --- | --- | --- |
| `ref-dep-alpha-dns` | dns | `ref-provider-alpha-dns` |
| `ref-dep-alpha-bmc` | bmc | `ref-provider-alpha-bmc` |
| `ref-dep-alpha-backup` | backup | **nothing — no controlled backup driver exists** |
| `ref-dep-alpha-registrar` | registrar | **nothing** |
| `ref-dep-alpha-payment` | payment | **nothing** |

`satisfied_by` is a **ref**, not a fact, so a dependency claiming to be satisfied
by a provider that is not in the estate is a graph error rather than a string
nobody checked. `requires` is validated against `ProviderCategory`, and a
dependency whose satisfier is the wrong category is refused.

Which categories a **product** needs remains `ProductRequirements`' answer and is
not restated here. These objects say only which of them this estate can stand in
for. That is orchestration, not a second requirement model.

**This is a simulator limitation, not a limitation of the definition.** It is
recorded in §28 as a Gap 6 finding and is not fixed here, per §58.

## 17. Schema validation

`ReferenceTopologyValidator` — one validator, used by the tests and through them
by CI. It returns a list of sentences rather than throwing, so one run reports
every problem; `ReferenceTopology::load()` is the throwing caller.

What it refuses:

1. a missing or dishonest marker (`kind`, `environment`, `schema_version`, and
   any of the three flags being true);
2. an unknown kind, a malformed id, a duplicate id (ids are unique across kinds);
3. a missing required fact, per `ReferenceKind::requiredFacts()`;
4. a ref naming nothing, naming the wrong kind, or in a slot this kind does not
   have; a required slot left empty;
5. an orphan — anything not reachable from a region by following refs;
6. an address that is **not** documentation, or a hostname not under a reserved
   domain (the polarity is inverted from everywhere else in the platform, on
   purpose);
7. an address-carrying object that does not state `reference_only: true`;
8. a field whose name suggests a credential, or a value shaped like a key or a
   token whatever the field is called;
9. a provider naming a driver that is not in the catalogue, or is not controlled,
   or whose category does not match, or that is declared for production;
10. a dependency requiring a category that does not exist, or satisfied by a
    provider of the wrong category;
11. a monitoring target naming a collector this application does not have.

No new command was created. §54's own caveat — "do not create a command merely
for aesthetics if CI can call a domain validator directly" — applies: the
validator is invoked by `TheReferenceTopologyIsAModelAndNotAnInventoryTest`,
which runs in the existing Backend job, so the validation is gated without a new
CI job (§55: "do not create a huge slow CI job"). A real onboarding supplies its
values through the Control Center, not by editing this file, so a validate
command would have no second user.

## 18. Graph integrity

Every reference resolves, exactly once, to an object of the kind the slot
expects. **73 references across 52 objects**, asserted individually by
`every_reference_resolves_exactly_once` and refused in aggregate by the
validator.

Reachability is computed from every region by following refs in **both**
directions: an object is connected if it points at a connected object or a
connected object points at it. A machine hanging off a datacenter is connected
downward; a monitoring target hanging off a cluster is connected upward.

Two gates in this area were initially asserted by tests that **passed for the
wrong reason**, and both are recorded because that is the failure mode Gap 3's
breakage B taught:

- the obvious orphan case — add an object with no refs — was refused for a
  *missing required ref*, not for floating. Every kind but `provider` has a
  required upward reference, so a provider row is the only object that can
  actually be an orphan, and that is what the test now adds.
- the obvious dependency-category case — retarget `satisfied_by` away from the
  DNS provider — made that provider an orphan, so the orphan gate fired first and
  the category check never ran. The test now *retargets* the BMC dependency at
  the DNS provider, leaving both reachable, so only the category rule can fire.

Both tests now assert `count($violations) === 1`, so a refusal for a different
reason fails them.

## 19. Secret protection

Zero secrets, enforced three ways:

1. **the validator** refuses any field named `password`, `passwd`, `secret`,
   `token`, `api_key`, `apikey`, `api_secret`, `private_key`, `ssh_key`,
   `authorization`, `auth_code`, `credential`, `credentials`,
   `credential_reference`, `credentials_reference`, `username`, `user`,
   `passphrase`, `bearer`, `session`, `cookie` or `signature` — `username`
   included, because half a credential is still half a credential;
2. **the validator** refuses any value starting `-----BEGIN`, `ssh-rsa `,
   `ssh-ed25519 `, `sk_live_`, `sk_test_` or `Bearer `, whatever the field is
   called;
3. **the loader** writes `null` to `username`, `credentials_reference` and
   `credential_reference_id` explicitly rather than omitting them, so a reference
   row cannot inherit one from a column default.

There is no credential reference in the definition at all. §28 of the brief
prefers none unless the architecture needs one, and it does not: a credential
lives in the credential centre and is attached to a provider by an operator.

The existing CI gates continue to apply — "Fail if a secret or environment file
was committed" and "Fail if a credential was committed under infrastructure/" —
and both were green on the entry run and are unaffected by this change.

## 20. Production guards

### 20.1 The defect this section exists because of

`EndpointPolicy`'s docblock said that the reserved ranges it refuses include
"documentation". **It did not.** The flag it relies on,
`FILTER_FLAG_NO_RES_RANGE`, covers loopback, link-local, the unspecified address
and 240/4. Measured on PHP 8.4:

| Address | `NO_RES_RANGE` | `NO_RES_RANGE|NO_PRIV_RANGE` |
| --- | --- | --- |
| 192.0.2.10 | **accepted** | **accepted** |
| 198.51.100.10 | **accepted** | **accepted** |
| 203.0.113.10 | **accepted** | **accepted** |
| 2001:db8::1 | **accepted** | **accepted** |
| 127.0.0.1 | refused | refused |
| 169.254.169.254 | refused | refused |

So before Gap 4, **every documentation address in this repository's own examples
was an acceptable production provider endpoint** — including the entire Ansible
production inventory, which is 203.0.113/24 from top to bottom — and a comment
in the one file that is supposed to say no asserted the opposite. A guard that is
believed to exist is the worst shape a defect can have.

This was found by running `filter_var` against the ranges, not by reading the
file. Reading it produced the opposite conclusion, because the comment was
persuasive.

### 20.2 The classifier

`ReferenceValues` (`src/Modules/Shared/Domain/Services/ReferenceValues.php`) —
one classifier, no substring matching anywhere:

- `isDocumentationAddress()` compares **packed binary** against a network and a
  prefix length: 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24 (RFC 5737),
  2001:db8::/32 (RFC 3849), 3fff::/20 (RFC 9637). Whole bytes with a substring,
  the partial byte under a mask — a /20 is two and a half bytes, and a whole-byte
  comparison would accept `3f00::` as inside `3fff::/20`.
- `isDocumentationHostname()` reduces the name to labels and compares the last
  label against `example`, `invalid`, `test`, `localhost` (RFC 2606, RFC 6761),
  and the registrable pair against `example.com`, `.net`, `.org`, `.edu` (RFC
  2606 reserves those at the second level). `example-hosting.net`,
  `a.example.community` and `notexample.com` are names somebody could own, and a
  substring check gets all three wrong.
- `isReferenceIdentifier()` — case-**in**sensitive, the right strictness for a
  guard: the question is "might this be a reference value", and
  `REF-NODE-ALPHA-1-A` is one whatever the shift key was doing.
- `isCanonicalReferenceIdentifier()` — case-**sensitive**, the right strictness
  for the validator: the question is "is this id written the way the scheme
  says", and `Ref-Node` is not.

Those last two started as one method, and the split came out of a test failure
rather than a design review: the single method had to pick a strictness, and it
was wrong for one of its two callers.

Benchmarking ranges (RFC 2544 198.18/15, RFC 5180 2001:2::/48) are deliberately
**absent**. They are reserved but they are not documentation, and 198.18 appears
once in this repository as a real network-test address rather than as an example.

### 20.3 Where the guard is applied

| Guard | Where | Scope of "production" |
| --- | --- | --- |
| documentation address / reserved hostname refused | `EndpointPolicy::assertProviderEndpoint()`, `assertMachineAddress()` | the **installation** — `app()->environment('production')`, as every other call in that file already used |
| the same, on a resolved address | `EndpointPolicy::assertAddress()`, per resolved IP | the installation |
| a row **declared** for production carrying a reference value | `ProviderReadiness::assess()` | the **row's own** `environment` |
| the reference topology cannot load at all | `LoadReferenceTopologyForSimulation` | the installation |
| a controlled driver cannot be registered | `RegisterProvider` (pre-existing) | the installation |
| a controlled driver cannot answer for a production row | `ProbeProvider` (Gap 3) | the row |

The two notions of production are kept apart on purpose, and the choice differs
between the two new call sites:

- **registration and dialling** ask "may this installation hold or dial such a
  value at all", where the installation is a fact and the row is data somebody
  typed. That is the existing convention in `RegisterProvider` and it is
  preserved.
- **readiness** asks "may this row be given real work", and there the row's
  declared environment is exactly the claim being tested. An operator who wrote
  `production` on a row pointed at `203.0.113.11` has stated an intention, and
  the endpoint contradicts it.

Putting the readiness check in `ProviderReadiness` rather than in
`EnableProvider` means **one check covers four surfaces**: enabling refuses
because readiness is not ready, the readiness screen says why, the preflight
provider chain reports it as a configuration blocker, and the stored readiness
column records it.

Two guards cover the same fact deliberately. Readiness is what an operator
reads; the endpoint policy is what stops a socket opening. Either alone would be
a single point of failure for the thing §7 says must never happen, and
`the_endpoint_policy_and_the_readiness_engine_both_refuse_it` asserts both.

### 20.4 Not a global ban

Documentation values are **correct** in the reference topology, in tests, in
fixtures, in the Ansible example inventories and in every file under `docs/`.
Every refusal above is conditioned on production, and
`the_same_address_is_accepted_outside_production` asserts the whole family is
accepted when it is not.

## 21. Simulation and preflight integration

### 21.1 The loading seam

`LoadReferenceTopologyForSimulation` — the smallest seam §30 permits. No
simulator was rewritten. Four refusals make "load a model" and "activate a
configuration" different acts structurally rather than by naming convention:

1. it refuses to run on a production installation;
2. it refuses a topology whose marker claims to be production, deployable or
   reachable — even when the array is handed to it directly;
3. every row it writes is stamped `development`, so no reference row can satisfy
   a production readiness question;
4. every provider it writes uses a controlled driver, which `RegisterProvider`
   refuses in production and whose tester refuses to be constructed there.

There is no counterpart that promotes what it writes to production. The method is
not called `useTopology()` and there is no `apply()`, `sync()` or `activate()`
anywhere near it.

The loader threads a per-run logical-id → database-key map through every writer
rather than holding one as a property or a static. A loader that cached keys
between runs would resolve a ref to a row from a database that had since been
rebuilt, and nothing would say so — Gap 3 made that mistake once.

Loading is idempotent: every writer is an `updateOrCreate` on a natural key, and
`loading_is_idempotent` asserts the row counts do not move on a second run.

### 21.2 Preflight

`PreflightReport` now carries `referenceEstate`, and `estateLabel()` returns
`REFERENCE TOPOLOGY` or `CONFIGURED ESTATE`. It is carried **separately from the
mode** because the two say different things: SIMULATION says how the checks ran,
REFERENCE TOPOLOGY says what they ran against. A complete green against a model
of an estate is a fact about this codebase and about nothing else, and a report
showing only the first word would let somebody read it as a fact about an estate.

It is computed from the rows the run actually loaded — provider names, endpoints
and the machines behind them — rather than by asking whether a reference topology
exists on disk. The question the report has to answer is what these checks were
run against.

Measured output of `php artisan infra:preflight --mode=simulation` against the
loaded reference estate:

```
 SIMULATION — the whole estate
 REFERENCE TOPOLOGY — a model of an estate. Nothing in it exists and nothing in it is reachable.

 ...
 Mode:     SIMULATION
 Topology: REFERENCE TOPOLOGY
 Checks:   40
 Passed:   5
 Failed:   1
 Blocked:  25
 Warnings: 0
 Not established: 8
 Verification: CODE_COMPLETE, TESTED, RUNTIME_VERIFIED
 Real infrastructure verified: NONE
 Ready to sell: NONE — a preflight observes; it does not declare a product sellable.
```

Exit code 1. Every product family — vps, dedicated, shared_hosting, wordpress,
domains — is blocked, and the reason is the one §16 predicts: no controlled
driver exists for compute, hosting, backup, registrar or payment, so a complete
reference estate cannot make a product sellable. **A complete reference topology
removes no real-validation requirement**, which is §31's requirement, and here it
is not merely preserved by policy but produced by the measurement.

`Ready to sell: NONE` is printed unconditionally and always NONE, because this
command cannot establish it. Sellability is a decision an operator records
against a product once its real requirements are met.

### 21.3 Admin

No new Admin page and no topology editor. The existing **Readiness** page's
preflight panel shows one extra prominent alert when the run touched the
reference estate, in English and Arabic:

> These checks ran against the REFERENCE TOPOLOGY, which is a model of an estate
> rather than an estate. Nothing in it exists and nothing in it is reachable, so
> nothing here is evidence about real infrastructure.

The reference topology is not editable through Admin and cannot become active
production configuration by one Save: Admin writes rows, the definition is a file
in git, and nothing reads the file at request time.

## 22. IaC mapping

`infrastructure/ansible/inventories/{development,staging,production}/hosts.yml`
already exist, are already example-only, are already validated by
`infrastructure/scripts/validate-inventory.py`, and already carry the
`safety_class` classification Phase 30B added. They were **not** changed.

The alignment an engineer needs is the correspondence between a software object
and an inventory fact:

| Reference software object | Ansible / inventory counterpart |
| --- | --- |
| `machine.name` | inventory host name |
| `machine.management_address` | `ansible_host` |
| `machine.safety_class` | `safety_class` group or host var |
| `machine.allow_reimage` | `allow_reimage` (absent by default, added for one scheduled job) |
| `machine.bmc_address` | private inventory only; never committed |
| `rack`, `rack_unit`, `height_units` | private inventory (physical placement) |
| `network.bridge`, `network.vlan_id` | `group_vars` for the hypervisor role |
| `cluster` | the `proxmox` group |
| `hosting_node` | the `hosting` group |
| `backup_target.datastore` | the `pbs` group's own configuration |
| `monitoring_target.prometheus_job` | `infrastructure/monitoring/prometheus/targets/*.yml` |

Nothing was applied and nothing was planned. No workflow can apply
infrastructure — `infrastructure/scripts/check-ci-cannot-apply.py` asserts that,
and it was green on the entry run.

## 23. Onboarding mapping — how each reference field becomes real

**No row in this table is a source-code edit.**

| Reference | Becomes real through |
| --- | --- |
| `ref-region-alpha` | Admin → a Region row with the real name, country and city |
| `ref-dc-alpha-1` | Admin → a Datacenter row with the real facility |
| `ref-rack-alpha-1-a` | Admin → a Rack row; physical placement in the private inventory |
| `ref-cluster-alpha-1`, driver `fake` | Admin → a ComputeCluster with driver `proxmox` and the real API endpoint, plus a CredentialReference |
| `ref-node-alpha-1-*` | **discovery**, not declaration: the real cluster is asked what nodes it has |
| `ref-storage-alpha-1-*` | discovery: the real cluster is asked what storage it has; the logical role is chosen by the operator |
| `ref-net-alpha-1-*`, bridge, VLAN | Admin → Network rows with the real bridge and VLAN; the VLAN itself is built by the private IaC |
| `ref-pool-*`, `ref-subnet-*` | Admin → IpPool and Subnet rows with the real allocations |
| `ubuntu-lts` → `ref-image-9001` | Admin → the same logical key mapped to the real Proxmox template id. **The logical key does not change.** |
| `ref-machine-alpha-1-*` | Admin → ManagedServer rows; management and BMC addresses from the private inventory |
| `ref-machine-*.safety_class` | Admin → an explicit classification decision, audited |
| `ref-bmc-alpha-1-04` | Admin → a BmcEndpoint with the real protocol and address, and a CredentialReference — **never a value** |
| `ref-hosting-alpha-1`, panel `fake` | Admin → a HostingNode with panel `cpanel` or `directadmin`, a real endpoint, a CredentialReference and a Licence |
| `ref-pkg-*` | Admin → HostingPackage rows mapped to real catalogue plans |
| `ref-provider-alpha-dns`, driver `fake` | Admin → a ProviderInstance with driver `cloudflare` and a CredentialReference |
| `ref-provider-alpha-bmc`, driver `fake_bmc` | Admin → driver `redfish`, `ilo` or `ipmi` |
| `ref-dep-alpha-backup` (unsatisfied) | Admin → a real `proxmox_backup` ProviderInstance |
| `ref-dep-alpha-registrar` (unsatisfied) | Admin → a real `sy_registry` ProviderInstance |
| `ref-dep-alpha-payment` (unsatisfied) | Admin → a real `stripe` ProviderInstance |
| `ref-backup-alpha-1.datastore` | the real datastore name, from the backup provider's own discovery |
| `ref-monitor-alpha-1-*` | `infrastructure/monitoring/prometheus/targets/*.yml`, in the private deployment |
| every credential, anywhere | a `CredentialReference` naming a secret-store variable. A secret never enters the database and never enters git. |

The architecture assertion that makes this table trustworthy rather than
aspirational is `NoReferenceIdentifierIsRequiredByBusinessLogicTest`: see §24.

## 24. Hardcoded-value audit

### 24.1 Reference literals in business logic: ZERO

`NoReferenceIdentifierIsRequiredByBusinessLogicTest` takes the id list **from the
definition** and greps `src/`, `app/` and `apps/web/src/` for each one, excluding
only the four places §35 permits: the definition, tests, the seeder, and docs.
Three assertions: no logical id, no reference address or hostname, and the loader
names kinds rather than objects.

Two corrections were needed to make it measure the right thing, and both are
worth recording:

- **it flagged seven documentation comments.** `CloudInitConfig`'s docblock
  explains Proxmox's ipconfig syntax with `e.g. "ip=192.0.2.10/24,gw=192.0.2.1"`;
  `Hostname` uses `192.0.2.10` to show what is *not* a valid name;
  `ReferenceValues` and `EndpointPolicy` name the ranges they refuse. All are
  documentation, which is exactly what an RFC documentation range exists for. The
  gate now strips comments before searching — PHP via `token_get_all`, because a
  `//` inside a string is not a comment.
- **it matched substrings.** `192.0.2.1` is a substring of `192.0.2.10`, so the
  gateway was reported because a host was present. Addresses are now matched with
  boundaries — the same discipline §37 and §38 demand of the guard itself.

### 24.2 Facts the definition carries that no column models

Carried because a real onboarding has to supply them; dropped by the loader
rather than invented into a table. Each is a finding for software closure, not a
defect:

| Fact | Where | Why there is no column |
| --- | --- | --- |
| `storage.role` (primary_vm / template_image) | 5 objects | `compute_storages` models class, sharing and capacity, not content or role |
| `template.minimum_cpu_cores` / `minimum_memory_mib` / `minimum_disk_gib` | 4 objects | `vm_templates` models capability, not minimum resources |
| `backup_target.*` (datastore, retention, verification) | 1 object | `backups` records archives, not the store they sit in |
| `monitoring_target.*` | 4 objects | monitoring targets live in Prometheus files, not in the database |
| `dependency.*` | 5 objects | provider requirements live in `ProductRequirements`, per product rather than per site |
| which node offers which hosting package | 3 objects | `hosting_packages` has no node column; packages are global |
| `bmc.vendor_class` | 1 object | `bmc_endpoints` records protocol, address and firmware, not a vendor class |

Ten of the 52 objects are therefore definition-only: the loader writes 42 rows
(39 without the catalogue), and a test asserts the counts.

### 24.3 `.env`-only operational values

Audited per §51. The topology dimensions this gap models are all
database-and-Admin driven; none requires a `.env` edit. `.env` remains bootstrap
infrastructure — database DSN, Redis, mail transport, queue — which §51 permits.

One pre-existing item is recorded rather than migrated, because §51 says not to
perform broad config migrations here: the **SMTP relay** is `MAIL_HOST`, a
deployment setting rather than an address the Control Center dials.
`ProviderCatalogue` already documents this (`needsEndpoint: false`, "the relay is
a deployment setting, not an address the Control Center dials"), and the `smtp`
driver is recorded as untestable for the same reason. It is a finding for final
software closure, not a Gap 4 change.

## 25. Deliberate breakage

Every gate was broken on purpose and the break was reverted. A gate that has
never failed is a gate nobody has tested.

Two styles of break were used, because they prove different things. Corrupting
the **data** shows the guard bites; deleting the **guard** shows the test that
asserts the guard is not passing for some other reason. Gap 3's breakage B
passed because only the first style had been tried, so both are here.

| | Break | Gate | Expected | Result |
| --- | --- | --- | --- | --- |
| A | `production: true` in the topology marker | reference topology suite | RED | **RED — 29 of 34 failing** |
| A2 | delete the marker-flag check from the validator | reference topology suite | RED | **RED — 2 of 34 failing** |
| B | delete the three RFC 5737 ranges from `ReferenceValues` | production guard suite | RED | **RED — 10 of 50 failing** |
| C | empty `RESERVED_TLDS` in `ReferenceValues` | production guard suite | RED | **RED — 6 of 50 failing** |
| D | repoint a node's `cluster` ref at an object that does not exist | reference topology suite | RED | **RED — 26 of 34 failing** |
| E | add `'password' => 'calvin'` to the reference BMC | reference topology suite | RED | **RED — 23 of 34 failing** |
| F | add `const DEFAULT_NODE = 'ref-node-alpha-1-a'` to `CloudInitConfig` | reference-literal gate | RED | **RED — 1 of 3 failing** |
| G | short-circuit the production-row check in `ProviderReadiness` | production guard suite | RED | **RED — 3 of 50 failing** |
| H | short-circuit the loader's production refusal | reference topology suite | RED | **RED — 1 of 34 failing** |
| I | hardcode `referenceTopology: false` in the preflight service | reference topology suite | RED | **RED — 1 of 34 failing** |

Nine breaks, nine reds, all reverted. `git diff` was re-read afterwards and no
residue remains: no `if (false`, no `ref-cluster-gone`, no `'password' =>
'calvin'`, no `'production' => true`, no `DEFAULT_NODE`, and both
`ReferenceValues` constant lists are back to one occurrence each.

**One thing went wrong during the exercise and is worth recording.** Reverting
break I with `git checkout <path>` discarded the file's Gap 4 changes as well as
the break, because the file was already modified relative to HEAD. It was
detected immediately (`grep` for `referenceTopology` in that file returned
nothing), the edit was re-applied, and the focused suites were re-run green. The
other eight breaks were reverted from copies taken before the break, which is
what `git checkout` should not have been used instead of.

## 26. Positive twins

Every rejection has one. A suite of refusals can all pass on a system where
nothing succeeds.

| Rejection | Its twin |
| --- | --- |
| documentation address refused in production | the identical address accepted outside production (8 cases) |
| documentation address refused | 8 real addresses accepted in production, including one octet either side of each TEST-NET |
| `.example` refused in production | `cp-1.prod.example` accepted outside production |
| reserved domain refused | 5 real names not mistaken for reserved ones, including `example-hosting.net`, `a.example.community`, `notexample.com` |
| a production row with a reference endpoint is not ready | the identical row with `https://api.cloudflare.com` is not blocked on the reference rule |
| a production row with a reference endpoint is not ready | a development row with the same endpoint is not blocked on the reference rule |
| broken graph refused | the shipped topology is valid, and 46 refs resolve individually |
| orphan refused | every other object is reachable |
| secret field refused | the shipped topology has no credential field at all |
| real driver refused | both reference providers name a controlled driver |
| absent collector refused | all 5 named collectors exist |
| reference mode refused for production | simulation accepts the reference topology and loads 42 rows |
| preflight says REFERENCE TOPOLOGY over the reference estate | preflight says CONFIGURED ESTATE over an estate that is not it |
| the loader refuses a production installation | it loads on a development one, idempotently |

## 27. Tests

### 27.1 New

| File | Tests | What it covers |
| --- | --- | --- |
| `tests/Feature/Infrastructure/TheReferenceTopologyIsAModelAndNotAnInventoryTest.php` | 34 | markers, graph integrity, orphans, secrets, documentation-only addresses, controlled drivers, dependency categories, monitoring collectors, loading, idempotency, the production refusals, §43–§47 representability, preflight integration |
| `tests/Feature/Security/ProductionRefusesTheReferenceEstateTest.php` | 50 | documentation addresses and reserved domains refused in production and accepted outside it, the activation path, the classifier's own edges — with a positive twin for every refusal |
| `tests/Architecture/NoReferenceIdentifierIsRequiredByBusinessLogicTest.php` | 3 | no reference id, address or hostname in application source; the loader names kinds rather than objects |

87 new tests, and a table of what the gap added:

| File | Lines |
| --- | --- |
| `resources/reference-topology/topology.php` | 1,096 |
| `resources/reference-topology/README.md` | 69 |
| `Domain/Reference/ReferenceKind.php` | 159 |
| `Domain/Reference/ReferenceObject.php` | 166 |
| `Domain/Reference/ReferenceTopology.php` | 200 |
| `Domain/Reference/ReferenceTopologyInvalid.php` | 38 |
| `Domain/Reference/ReferenceTopologyValidator.php` | 656 |
| `Shared/Domain/Services/ReferenceValues.php` | 283 |
| `Application/Reference/LoadReferenceTopologyForSimulation.php` | 657 |
| the three test files | 1,326 |

`database/seeders/InfrastructureSeeder.php` went from 403 lines to 80: it
gained 35 and lost 358, because it no longer describes an estate.

### 27.2 Changed

| File | Change | Why |
| --- | --- | --- |
| `tests/Feature/Seeders/DevelopmentFixturesTest.php` | cluster endpoint: null → null-or-`fake://` marker; BMC rows: count-of-zero → no credential, no username, documentation address only | both assertions were narrower than their own stated reasons, and the new ones assert the danger rather than the shape (§20.1, §24) |
| `tests/Unit/Shared/AnEndpointIsNotAWayIntoTheNetworkTest.php` | 4 sample hostnames moved off `.example` / `.test` | three of its lines assert acceptance **in production**, and Gap 4 makes a reserved domain unacceptable there. The subject of each test — private-versus-public addressing, port parsing — is unchanged. |
| `tests/Feature/Infrastructure/*Preflight*` | unchanged | the CLI/API field-for-field comparison picked up the two new fields without modification |

### 27.3 Measured

| Gate | Result |
| --- | --- |
| Backend suite | **3,330 tests, 3,330 passed, 140,761 assertions, 0 failures** |
| Pint | clean |
| PHPStan | 0 errors |
| OpenAPI | 249 operations, valid (6 pre-existing warnings) |
| Frontend unit | 443 tests, 81 files, all passing |
| `tsc -b` | clean |
| `eslint . --max-warnings 0` | clean |
| Translation parity | no English-only keys; the Arabic-only keys are its extra plural categories, as before |
| CI committed-secret gate, run locally | passes |
| CI at the new SHA | not yet run |

### 27.4 One thing the suite taught me about my own method

The first full run after the changes reported two failures in
`PurchaseToActiveServiceTest` and `RegistrationDecidesTheCurrencyOutLoudTest`,
both `Customer::query()->sole()` finding two customers. Both classes pass in
isolation, pass together, and pass alongside the seeder test.

The cause was mine: I had force-killed test processes mid-transaction while
diagnosing something else, and started a new run immediately, so two suites
were sharing one test database. The same mistake produced a run of PostgreSQL
deadlocks earlier. After resetting the test database and running once, with
nothing else touching it, the suite is green.

It is recorded because "it passed the second time" is exactly the sentence a
real flake hides behind, and the distinction has to be stated rather than
assumed: this was not re-run until it passed. The failing assertions were
reproduced against a clean database three ways and did not recur, and the
mechanism — a committed row surviving in a database shared by two concurrent
runs — explains both the failures and the deadlocks that preceded them.

## 28. Exact remaining gaps

Not started, and not to be started without review:

- **Gap 5 — Naming standard.** No repository-wide rename was performed. The
  `*.prod.example` and `*.kw.lynomia.internal` split is untouched, and neither
  was silently chosen as production truth. The reference estate uses its own
  documented, deterministic, local scheme (`ref-<what>-<where>`, hostnames under
  `.reference.example`) which §57 permits, and that scheme is local to the
  reference estate and implies nothing about the repository convention. **The
  split did not block Gap 4.**
- **Gap 6 — Simulator contract-gap audit.** One limitation was found by building
  the reference estate and is recorded rather than fixed: the provider catalogue
  has exactly two controlled drivers, `fake` (DNS) and `fake_bmc` (BMC), so no
  reference or simulated estate can rehearse a compute, hosting, backup,
  registrar or payment provider through the `ProviderInstance` model. Compute and
  hosting are covered by the older per-module `driver`/`panel` fakes; backup,
  registrar and payment are not covered at all. A test
  (`a_dependency_no_reference_provider_can_satisfy_says_so_rather_than_looking_complete`)
  pins the list to `['backup', 'registrar', 'payment']`, so closing one of them
  is a deliberate change to that list rather than a silent improvement in what a
  green report means.
- **Gap 7 — Golden paths / failure matrix.**
- **Gap 8 — Final software closure.**

Also not started: repository-wide hostname rename, real infrastructure
validation, real VPS provisioning, IaC apply.

## 29. Real-validation boundary

Nothing in this gap moved it.

- The reference estate is **not reachable**. `reachable: false` is a field, and
  no TCP probe, TLS probe or provider identity call is ever pointed at one of its
  addresses. Simulation uses controlled providers; `fake://` markers never open a
  socket.
- No simulated hardware satisfies REAL mode. Every reference row is stamped
  `development`, and Gap 2's and Gap 3's guards already refuse a controlled
  driver for a production row.
- The reference estate carries no credential, no licence and no capability row,
  so no reference provider can be enabled and none can reach
  `ReadyForProduction`.
- `READY_TO_SELL` remains false. The preflight over the complete reference estate
  blocks every product family, and the command prints
  `Ready to sell: NONE` unconditionally.
- A reference estate that validates, loads and passes every software check has
  established exactly one thing: that this codebase orchestrates an estate of
  that shape correctly. It has established nothing whatever about an estate.

REAL_INFRA_VERIFIED: **NONE** · REAL_PAYMENT_VERIFIED: **NONE** ·
REAL_REGISTRAR_VERIFIED: **NONE** · REAL_HOSTING_VERIFIED: **NONE** ·
READY_TO_SELL: **NONE**
