# Phase 30B-SIM — Gap 5: canonical infrastructure naming standard

**Non-production. Configuration-ready. Zero real claims.**

REAL_INFRA_VERIFIED = NONE · REAL_PAYMENT_VERIFIED = NONE ·
REAL_REGISTRAR_VERIFIED = NONE · REAL_HOSTING_VERIFIED = NONE ·
READY_TO_SELL = NONE

---

## 1. Provenance

| | |
|---|---|
| Repository | `fullstackfull/cloud` |
| Branch | `claude/hv-t6hq1p` |
| Starting HEAD | `bb5d60c3bcf1ab6c7e8c4d67216d0dabe94ed099` |
| Working tree at entry | clean; nothing after the Gap 4 closure commit |

## 2. Gap 4 entry gate

| | |
|---|---|
| SHA | `bb5d60c3bcf1ab6c7e8c4d67216d0dabe94ed099` |
| CI run | 172 (id `35150599714`) |
| Conclusion | **success**, 9 / 9 jobs |

Jobs, enumerated individually rather than taken from the run's rollup: Backend
(PG 16), Backend (PG 18), Static analysis, API description, Security checks,
Browser end-to-end, Infrastructure validation, Frontend, Production guards.

## 3. Naming inventory

Read from the schema, the models and the committed configuration. "Kind" is
this gap's contribution: the columns cannot say it for themselves.

| Concept | Column | Kind | Unique | External contract | Action |
|---|---|---|---|---|---|
| Region | `regions.slug` | logical id | platform | Admin API | keep |
| Region display | `regions.name` (jsonb) | display, localised | — | Admin + customer API | keep |
| Datacenter | `datacenters.slug` | logical id | platform | Admin API | keep |
| Datacenter display | `datacenters.name` | display | not unique | Admin API | keep, labelled |
| Datacenter facility | `datacenters.facility` | free text | — | Admin API | keep |
| Rack | `racks.name` | operator code | per datacenter | Admin API | keep, labelled "Rack code" |
| Cluster | `compute_clusters.slug` | logical id | platform | Admin API | keep |
| Cluster endpoint | `compute_clusters.api_endpoint` | endpoint URL | — | — | EndpointPolicy's, not naming's |
| Node | `compute_nodes.provider_name` | **provider-native** | per cluster | Admin API | **published as `provider_name`, was `name`** |
| Storage | `compute_storages.provider_name` | provider-native | per cluster+node | — | keep |
| Storage role | `compute_storages.storage_class` | logical role | — | — | keep (already a role, not a name) |
| Template | `vm_templates.slug` | logical id | per cluster | Admin API | keep |
| Template image | `vm_templates.provider_reference` | provider-native | not unique | — | keep |
| Template display | `vm_templates.name` (jsonb) | display, localised | — | customer API | keep |
| Hosting node | `hosting_nodes.slug` | logical id | platform | Admin API | keep |
| Hosting node host | `hosting_nodes.hostname` | network name | not unique | Admin API | keep |
| Machine | `managed_servers.name` | operator code | platform | Admin API | keep |
| Machine addresses | `managed_servers.management_address`, `bmc_address` | endpoint/authority | — | — | EndpointPolicy's |
| Provider instance | `provider_instances.name` | operator code | platform | Admin API | keep |
| Provider endpoint | `provider_instances.endpoint` | endpoint URL | — | — | EndpointPolicy's |
| Chassis | `dedicated_servers.serial` | manufacturer asset id | platform | Admin API | keep; never a hostname |
| Customer machine | `virtual_machines.hostname` | **customer data** | — | customer API | out of scope, deliberately |
| Monitoring labels | `role`, `service`, `cluster_name`, `instance` | stable ids and roles | — | Prometheus series | keep |

Everything is `varchar(255)` except `virtual_machines.storage_name`
(`varchar(64)`, and not an infrastructure name) and the two `jsonb` display
names. The policy reads each ceiling from the concept rather than assuming one.

## 4. Conflicting schemes found

Two families, and nobody had chosen between them:

| Family | Where | Count | Classification |
|---|---|---|---|
| `*.prod.example`, `*.dev.example` | `infrastructure/ansible/inventories/*`, one OpenTofu `tfvars.example`, two tests, three reports | 34 | **reference / example** — `.example` is IANA-reserved |
| `*.kw.lynomia.internal` | monitoring target lists, alert annotation URLs, compose external URLs, README | 109 | **operational-looking, and refused by this platform's own code** |
| `*.lynomia.com` | monitoring blackbox target lists | 7 | real public domain, hardcoded in committed configuration |
| `pve-01.example.internal` | `.env.example` comment | 1 | a third spelling, in the file operators copy |

The decisive finding is not that there were two. It is that **the application
could never have talked to the hosts its own monitoring named.**
`EndpointPolicy::FORBIDDEN_SUFFIXES` has refused `.localhost`, `.local`,
`.internal` and `.localdomain` in *every* mode since Gap 2 — "names under
%s are this host, this network or a metadata service". So the repository
asserted two incompatible things about one estate: the monitoring stack watched
`pve-01.kw.lynomia.internal`, and the control plane would have refused to
configure a provider pointing at it.

Resolution, per occurrence:

| Occurrence | Classification | Resolution |
|---|---|---|
| Ansible inventories | reference | unchanged — already the reserved namespace |
| Monitoring target lists | reference lists presented as operational | rewritten to the **example inventory's own host names** under `.prod.example`, with a header saying what they are |
| Monitoring target sets | divergent from the inventory (2 vs 3 app hosts, 3 vs 2 hypervisors) | matched to the inventory, closing the divergence 30B.0 flagged |
| `cluster_name: kw-central-1` | monitoring label asserting a place | the reference cluster's logical id |
| Alert `runbook_url` / `dashboard_url` | operational default | reference namespace |
| Compose `--web.external-url`, `GF_SERVER_ROOT_URL` | operational | `${VAR:-<reference default>}` |
| Compose `LYNOMIA_ENV`, `LYNOMIA_REGION` | operational, defaulted to a real-sounding region | `${VAR:?set VAR}` — required, no default |
| `prometheus.yml` `environment`, `region` | operational, written into the config file | `${LYNOMIA_ENV}`, `${LYNOMIA_REGION}` with `--enable-feature=expand-external-labels` |
| Blackbox public targets (`portal.lynomia.com`) | real public domain | reference namespace; the real list is operator-supplied |
| `.env.example` Proxmox URL comment | third spelling | the example inventory's own host |
| `docs/*` historical reports | historical | unchanged, deliberately |

Exit criterion met is the stated one: **zero ambiguous naming authorities**,
not zero occurrences of a string. The reports that describe the old state go on
describing it, and the gate is written so that this is not a violation.

## 5. Stable identity model

`NameKind::LogicalKey`. Lower-case ASCII segments joined by single dashes,
bounded by the column. Referenced durably by orders, audit entries, desired
state, deployment plans and metric series, so changing one is a migration with
a mapping and never an in-place edit — `InfrastructureNamingPolicy::isImmutable()`
says so and the Admin surfaces offer the display name instead.

## 6. Hostname model

One parser: `Shared\Domain\Naming\DnsName`. RFC 1035 label and length rules,
canonical lower case, one trailing root dot dropped, ASCII only, and an
explicit refusal for each thing that is a *different field*: `host:8443`, a
URL, user information, a path, an address, a bracketed literal. A bare label is
accepted, because that is what an operator types before a zone exists.

The existing `Ipam\Domain\ValueObjects\Hostname` keeps its own PTR-target
policy (fully qualified, valid TLD, never an address) and its own exception
type. Two policies, one syntax, and §33's separation intact: a customer's
hostname is not held to an operator standard.

## 7. Display-name model

`NameKind::DisplayName`. Any script including Arabic, bounded by the column,
refusing only control characters — a name carrying a newline becomes two lines
in a log, a CSV export or an alert annotation. Never normalised, never
translated into an identifier, load-bearing for nothing.

## 8. Provider-native identity model

`NameKind::ProviderNative`. Stored exactly as the provider spells it: no case
rule, no dash rule, no allow-list beyond printable ASCII without whitespace or
control characters. Uniqueness is case-**sensitive**, because `local-lvm` and
`LOCAL-LVM` may be two storages in somebody else's scheme and this platform is
not entitled to decide otherwise. Mappings stay mappings:
`compute_nodes.provider_name`, `compute_storages.provider_name`,
`vm_templates.provider_reference`.

## 9. Canonical naming policy

| File | What it is |
|---|---|
| `Shared/Domain/Naming/DnsName.php` | the DNS syntax, once |
| `Shared/Domain/Naming/LogicalName.php` | logical token syntax, normalisation, collision key |
| `Infrastructure/Domain/Naming/NameKind.php` | the four kinds plus display, and what may change each |
| `Infrastructure/Domain/Naming/NamingConcept.php` | every named field: kind, ceiling, scope, table, column |
| `Infrastructure/Domain/Naming/NamingScope.php` | uniqueness scopes, read off the indexes |
| `Infrastructure/Domain/Naming/DnsSuffix.php` | the operator-owned zone, composition, and the production refusal |
| `Infrastructure/Domain/Naming/InfrastructureNamingPolicy.php` | the one authority every layer asks |
| `Infrastructure/Domain/Naming/ReferenceNamingExamples.php` | examples, read out of the model |
| `Infrastructure/Domain/Naming/NamingFinding.php` | one finding, in the platform's existing status vocabulary |
| `Infrastructure/Application/Naming/AuditInfrastructureNaming.php` | the audit, consumed by the CLI and the preflight |
| `app/Console/Commands/InfrastructureNamingAuditCommand.php` | `infra:naming:audit`, `--json`, exit 0/1/2 |

Not one naive regex: the policy dispatches on kind and hands syntax to the
value object that owns it. A storage key and a hostname do not have the same
constraints, and a single pattern for both would have to be the looser of the
two.

## 10. Uniqueness scopes

Stated in §6 of `docs/infrastructure-naming-standard.md` and **derived from
`pg_indexes`, not asserted**:
`UniquenessIsScopedTheWayTheSchemaSaysTest::every_scope_the_standard_states_is_the_scope_the_database_enforces`
reads the unique indexes out of PostgreSQL and fails if a stated scope has no
index behind it. Both directions are tested: the same rack code in two
datacenters is accepted, a second one in the same datacenter is refused; one
template slug on two clusters is accepted, a duplicated cluster slug is
refused.

## 11. Reference naming

One reserved TLD, two sub-zones with two owners:

| Zone | Owner |
|---|---|
| `reference.example` | the modelled estate in `resources/reference-topology/topology.php` |
| `prod.example`, `dev.example` | the example IaC inventories and the monitoring lists that watch them |

`TheReferenceTopologyObeysTheNamingStandardTest` holds the model to the
standard it is the example of: every logical id is a valid logical identifier
*and* written in the `ref-` scheme, every hostname is a hostname, and every
hostname is **refused as a production zone**. The marker assertions from Gap 4
(`production`, `deployable`, `reachable` all false) are re-asserted here
because this gap touched the topology's consumers.

## 12. Production configuration and DNS suffix ownership

```
config/infrastructure.php
  naming.internal_dns_suffix  ← INFRASTRUCTURE_INTERNAL_DNS_SUFFIX   (unset)
  naming.public_dns_suffix    ← INFRASTRUCTURE_PUBLIC_DNS_SUFFIX     (unset)
```

**No real production suffix is chosen anywhere in this repository.** Unset is a
legitimate, reported state: the platform stores the hostnames it is given and
composes none. Two keys because management and customer-facing names are
usually two zones with two certificate authorities.

`config/*.php` with an env default is this repository's existing operational
configuration architecture — the same shape every provider, timeout and
threshold uses — so this adds the smallest seam rather than a settings table
nothing else would use. `env()` is read in exactly one file and the config key
in exactly one class, both gated.

One constraint an operator must know, recorded rather than smoothed over: an
internal zone under `.internal` cannot be used, because `EndpointPolicy`
refuses that suffix in every mode. ICANN reserved `.internal` for private use
in 2024, so that refusal is arguably stricter than needed; it is left exactly
as Gap 2 wrote it and listed in §25 as an open question.

## 13. Admin integration

- The nodes endpoint published `provider_name` under the key `name`, implying
  it was the platform's own identity for the node. It is now `provider_name`,
  in the controller, in `docs/openapi.yaml` (with a description saying `id` is
  the platform's identity), in the TypeScript type and in the one place the UI
  renders it.
- Site forms now label what each field is, in English and Arabic: **Logical ID**
  with "Stable identifier. Orders, audit history and monitoring reference it;
  renaming the display name does not change it"; **Display name** with "What
  people read on screens, in any language. Rename it freely: nothing references
  it"; **Rack code** with "The code on the cabinet. Unique within this
  datacenter, and A1 and a1 are the same rack."
- No new page, no IA change: the fields are where operators already edit them.

## 14. IaC integration

Ansible inventories were already correct and are unchanged. The OpenTofu
production `tfvars.example` names a reference endpoint and is unchanged. The
monitoring stack is aligned as set out in §4, and its committed target lists
now name the same hosts the example inventory deploys.

`infrastructure/scripts/validate-inventory.py`,
`validate-monitoring.py` and `validate-runbooks.py` all pass unchanged: 16
production hosts ok, 7 rule files / 73 rules with 60 exported by the control
plane, 118 runbook files.

## 15. Preflight integration

`InfrastructurePreflightService` consumes `AuditInfrastructureNaming` and maps
its findings into the report's own vocabulary — `CheckCategory::Configuration`,
and `BlockerReason::Configuration` for a failure. The checks are not
reimplemented: the CLI, the preflight and the tests ask one service, because
three copies of "what is a noncanonical identifier" would eventually be three
answers.

**No new blocker category.** The external vocabulary stays
`BLOCKED_CREDENTIALS`, `BLOCKED_HARDWARE`, `BLOCKED_NETWORK`,
`BLOCKED_LICENSE`, `NOT_IMPLEMENTED`. There is no `BLOCKED_PROVIDER` and no
`BLOCKED_NAMING`.

## 16. Monitoring implications

Label semantics are unchanged: `role`, `service`, `cluster_name` and `instance`
carry stable ids and roles. Two label *values* in the committed example
configuration changed — `cluster_name: kw-central-1` → the reference cluster id,
and `environment`/`region` became `${LYNOMIA_ENV}`/`${LYNOMIA_REGION}` — which
changes series identity **only for a deployment that was running these example
values**, and no deployment exists. An operator moving from a hardcoded region
label to the variable keeps continuity by exporting the same value.

Customer-hostname leakage into infrastructure metrics: **zero**. Gap 1's rule
holds; the audit of naming-related labels found none, and the labels the rules
read are the ones `validate-monitoring.py` and
`EveryBackupAlertMetricHasAProducerTest` hold to the exporters.

## 17. API implications

One field renamed (`AdminNode.name` → `AdminNode.provider_name`) in the
Admin-only infrastructure listing, with both sides of the contract in this
repository and both updated in the same commit. `docs/openapi.yaml` validates.
No customer-facing contract changed.

## 18. Legacy compatibility

New values must comply; existing values are audited and never rewritten:

- `naming.noncanonical` is a **warning** carrying the canonical spelling, and
  `infra:naming:audit` exits 0 on warnings alone.
- The audit is a read. `auditing_a_legacy_row_leaves_it_exactly_as_it_was`
  compares the row before and after two audit runs.
- A legacy row does not stop the audit reaching the rest of the estate, and
  does not stop the application booting.
- Advice differs by kind: a stable identity gets "plan a migration", an
  operator code gets "rename it in the Admin surface".

No compatibility alias layer was added. Nothing outside this repository
references these identifiers yet, so alias machinery would be speculation —
§59's condition for adding it is not met.

## 19. Hardcoded-value audit

| Claim | Gate |
|---|---|
| No reference identifier in application source | `NoReferenceIdentifierIsRequiredByBusinessLogicTest` (Gap 4) |
| No DNS naming authority in application source | `OneNamingAuthorityTest::no_naming_authority_is_hardcoded_in_application_source` |
| One reader of the suffix config, one definition of its env var | `OneNamingAuthorityTest::exactly_one_class_reads_the_configured_dns_suffix_and_exactly_one_file_defines_it` |
| No identity carried by a hostname field | `OneNamingAuthorityTest::no_stable_identity_is_carried_by_a_hostname_field` |

Gap 4's gate earned its keep during this work: the first version of
`NamingConcept` carried its examples as literals (`ref-dc-alpha-1`,
`debian-stable`, `RA1`) and the gate rejected it immediately. The fix is
better than the original: `ReferenceNamingExamples` reads examples out of the
model, so an example cannot drift from the scheme and cannot become a fourth
naming authority in a helper method.

## 20. Mixed-scheme gate

`OneNamingAuthorityTest` reads *values in positions where a hostname is a
hostname* — monitoring target entries, alert annotation URLs, the stack's
external URLs, example inventory host keys, IaC example endpoints — and
requires each to be under a reserved-for-examples domain, asking
`ReferenceValues` rather than keeping a second list of reserved names.

It does not grep the repository for the old strings. `docs/` describes the old
state and must go on describing it; a gate that banned the text would make this
report a violation of its own fix.

## 21. Deliberate breakage

Each break was applied, the expected gate observed failing, and the break
reverted. Every gate was re-run green afterwards.

| # | Break | Expected | Observed |
|---|---|---|---|
| A | `INFRASTRUCTURE_INTERNAL_DNS_SUFFIX=dc1.prod.example`, audited as production | audit FAILS | `FAIL: … under a domain reserved for documents and examples (dc1.prod.example), which cannot resolve in production` |
| B | `kw.lynomia.internal` hardcoded in `InfrastructureNamingPolicy` | authority gate FAILS | `… InfrastructureNamingPolicy.php names lynomia.internal` |
| C | `hosting_node.hostname` declared a logical identifier | naming/architecture gate FAILS | `hosting_node.hostname is a logical identifier carried by the column hostname` |
| D | Racks `Node-01` and `node-01` in one datacenter | collision gate FAILS | `"Node-01" and "node-01" are one identity unique within its datacenter`, CLI exit 1 |
| E | `web-01.reference.example:8443` in a hostname field | validation FAILS | `it carries a port — a hostname and the port it is reached on are separate fields` |
| F | `ref-storage-alpha-1-nvme-a` hardcoded in business logic | architecture gate FAILS | Gap 4's gate named the file and the identifier |
| G | `internal_dns_suffix` defaulting to `dc1.reference.example` | production guard FAILS | audit FAILS in production; the "no zone configured" test also went red |
| H | `pve-01.kw.lynomia.internal` back in a monitoring target list | mixed-scheme gate FAILS | `A host named in a monitoring target list is not a reference host` |

Verified restored: `git status` shows only the intended changes, and the only
remaining occurrence of the old zone in the repository outside `docs/` is the
sentence in the gate's own docblock that explains why it is gone.

## 22. Positive twins

| Refusal | The legitimate thing it must not catch |
|---|---|
| reference zone refused for production | `dc1.operator-chosen-zone.net` accepted for production |
| reference hostname refused as a production suffix | the same hostname accepted inside the reference topology |
| `host:8443` refused in a hostname field | `web-01.reference.example` accepted, with the port in its own field |
| two spellings of one rack code refused | the same code in two different datacenters accepted |
| uppercase logical identifier refused | uppercase **rack code** accepted, because cabinets are stencilled `A1` |
| whitespace in a provider-native id refused | `LOCAL-LVM` and `ceph_prod` accepted unchanged |
| control character in a display name refused | `مركز البيانات المرجعي` accepted |
| a production row with a reference value fails | the same row in `development` does not |
| logical identifier immutable | display-name rename accepted |

## 23. Tests

| Suite | Tests |
|---|---|
| `tests/Unit/Naming/TheStandardSaysWhatEachKindOfNameMayBeTest` | 56 |
| `tests/Feature/Naming/UniquenessIsScopedTheWayTheSchemaSaysTest` | 8 |
| `tests/Feature/Naming/LegacyNamesAreReportedAndNeverQuietlyRewrittenTest` | 14 |
| `tests/Feature/Naming/TheReferenceTopologyObeysTheNamingStandardTest` | 9 |
| `tests/Architecture/OneNamingAuthorityTest` | 8 |
| **New this gap** | **95** |

## 24. Regression

| Suite | Result |
|---|---|
| Backend, full | **3,429 / 3,429 passed**, 141,169 assertions (3,334 before this gap + 95 new) |
| Pint | passed |
| PHPStan | 0 errors |
| OpenAPI | valid (Redocly), and `openapi:generate --check` reports the committed document up to date |
| Frontend unit | 443 / 443 passed, 81 files |
| `tsc -b`, `eslint --max-warnings 0` | clean |
| Browser, affected specs from a clean database | **22 / 22 passed** (`control-center-sites`, `control-center-readiness`, `control-center-machines`, `operations`) |
| `validate-inventory.py` / `validate-monitoring.py` / `validate-runbooks.py` | pass unchanged |

Two things the first full run found, both recorded rather than smoothed over:

1. **`docs/openapi.yaml` is generated**, from `resources/openapi/schemas.php`. The
   field rename was first made by hand in the document, and
   `OpenApiSpecificationTest::the_committed_document_is_what_the_generator_produces`
   caught it within the run — the rename now lives in the declaration and the
   document is regenerated from it.
2. **Eight errors in that same run were mine, not the code's**: per-suite runs
   fired alongside the full run against the shared `lynomia_test` schema, and
   `relation "permissions" does not exist` is what a concurrent
   `migrate:fresh` looks like from the other process. The clean re-run, with
   nothing else touching the database, is the number recorded above.

## 25. Exact unresolved naming items

1. **`.internal` is refused by `EndpointPolicy` in every mode.** ICANN reserved
   it for private use in 2024, so an operator whose estate lives under
   `.internal` cannot configure this platform. Left unchanged here — this gap
   does not weaken an endpoint guard — and recorded as a deliberate decision to
   revisit.
2. **No provider naming constraints are encoded.** Proxmox node and storage
   name limits, WHM and DirectAdmin hostname rules, and registrar account id
   formats are unread contracts. The generic rule applies until a real contract
   is available; inventing them would be asserting a provider contract this
   repository has not read.
3. **Region, Datacenter and Site remain three concepts.** Naming rules were
   written for the rows that exist; merging the models is not this gap's work.
4. **`hosting_nodes.hostname` has no unique index.** Stated as not unique
   rather than assumed unique; whether two rows should ever share a hostname is
   a domain question nobody has answered.
5. **The public DNS suffix has no consumer yet.** The key exists and is
   validated; nothing composes customer-facing names from it today.
6. **Per-site suffixes are not modelled.** One internal and one public zone for
   the platform. A multi-site estate whose sites resolve under different zones
   would need a per-datacenter column, which is a migration and needs a real
   estate to justify it.

## 26. Real-validation boundary

Unmoved. Nothing in this gap contacted a real endpoint, configured a real zone,
or chose a real name. The naming standard makes real values configurable and
checkable; it does not make any of them present.

REAL_INFRA_VERIFIED = NONE · REAL_PAYMENT_VERIFIED = NONE ·
REAL_REGISTRAR_VERIFIED = NONE · REAL_HOSTING_VERIFIED = NONE ·
READY_TO_SELL = NONE
