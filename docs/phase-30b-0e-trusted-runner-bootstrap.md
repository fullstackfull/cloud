# Phase 30B.0-E — trusted runner and private inventory bootstrap

**Status: NOT READY.**

The purpose of this phase is to prepare the environment in which a future
`infra:preflight --mode=read-only-real` produces evidence worth having. Step one
of that preparation is a trusted runner, and step one has not happened: the host
this ran on is the same Claude-hosted sandbox that blocked 30B.0 twice, and
nothing in it can be turned into the runner. Every later step depends on the
first, so the honest report is a prepared plan and a short list of things only a
person with access to the estate can do.

That said, this is not a third copy of the 30B.0 report. What could be built,
derived or proved from here has been, and it is listed in §31 so the reader can
see what is genuinely finished and what is genuinely waiting.

**Zero write operations were performed.** Nothing real was contacted, created,
changed, powered, published, charged or deleted.

**No secret value appears in this document, and none may be added.** Credentials
are reported only as `CONFIGURED` / `MISSING`, and by reference name.

---

## 1. Provenance

| | |
| --- | --- |
| Repository | `fullstackfull/cloud` |
| Branch | `claude/relaxed-turing-nh8ybf` |
| Starting HEAD | `26e53eb75233411c123134eed6767872ba0475b8` |
| Observation window | 2026-09-19 |
| Software baseline | `SOFTWARE_CODE_COMPLETE = YES`, approved five-product scope only |
| Approved scope | VPS, Dedicated, Shared Hosting, DNS, Backups |

Nothing in this phase reopens Gap 1–8, the product scope, the WordPress or
Domains `Prepared` decisions, or the Backups verification semantics.

## 2. What the two previous 30B.0 runs established

Taken as authoritative and not re-litigated. Part I (2026-09-16) and Part II
(2026-09-19) of `docs/phase-30b-0-real-infrastructure-preflight.md` between them
found: an untrusted execution environment whose only routable address is in
TEST-NET-1; no route to any RFC1918 or CGNAT range; transparent interception
that answers on 443 for any off-link address and mints trusted certificates for
names RFC 2606 guarantees cannot exist; no private inventory; zero
`credential_references`; zero `provider_instances`; 25 of 25 credential names
missing; no observable template, chassis or hosting licence.

One check was repeated here, because it is the one this phase turns on, and it
answered the same way: a `.invalid` name presented as SNI to an off-link address
still returns a certificate for that name which this host verifies, return code
0, issued by a CA in its own trust store.

**Correction to Part II.** Part II recorded drift D-1 — the inventories naming
hosts under `*.prod.example` while the monitoring targets used
`*.kw.lynomia.internal` — as unchanged and still open. That is wrong, and it was
wrong when written: it was carried forward from Part I without re-reading the
target files. All eight files in
`infrastructure/monitoring/prometheus/targets/` name hosts under `.example`,
matching the inventories, and each carries a header explaining that the real
list is operator-supplied and that `.internal` is refused by this platform's own
endpoint policy. No non-comment `.internal` hostname exists anywhere in the
repository, and `tests/Architecture/OneNamingAuthorityTest` refuses
`lynomia.internal`, `prod.example`, `dev.example` and `lynomia.com` as naming
authorities. **D-1 is closed.** It changes the operator's work: there are not
two naming schemes to reconcile, only one real suffix to choose (§8).

## 3. Trusted runner architecture

**Does not exist. This is the phase's blocker and everything else waits on it.**

The runner is not something this session can produce. It is a host somebody
places inside, or routes into, the management network. What can be specified is
what it must be, and that is below, derived from the adapters rather than from
a general idea of a build host.

### 3a. Where it may live

| Option | Fits when | Note |
| --- | --- | --- |
| A — VM inside the management network | a hypervisor cluster already exists and can host one | shortest path; the runner is then on-link to the things it reads |
| B — hardened jump host / bastion | management access is already brokered through one | the runner is the bastion, not a host behind it |
| C — self-hosted CI runner over a genuine VPN | validation should be repeatable from CI later | the VPN must carry real routes, not a proxy |
| D — operations VM with routed access to management VLANs | there is an existing operations segment | equivalent to A with a firewall hop |

Not an option: this environment, or any environment with application-layer
interception. §5 says how to tell the difference, mechanically.

### 3b. What it must have

Every entry below is required by something in this repository, named beside it.
Nothing is listed because it is generally useful.

| Requirement | Why, specifically |
| --- | --- |
| Linux, PHP 8.4 | `composer.json` platform; the control plane is PHP 8.4 |
| Composer | `composer install` from the committed lockfile is how the app is set up |
| Git | to check out this repository at a known SHA |
| PostgreSQL and Redis | the control plane's own stores. Local to the runner is fine for a read-only preflight; a real deployment's own database is better, because then the provider rows the preflight reads are the ones production uses |
| `openssl` | §5's negative control |
| Python 3 with PyYAML | `infrastructure/scripts/*.py`, including the inventory validator and the runner trust check |
| `ipmitool` | **only if a BMC uses the `ipmi` driver.** `IpmiConnectionTester` shells out to `ipmitool` over the `lanplus` interface. The `redfish` and `ilo` drivers are HTTPS and need nothing extra |
| Node.js | **not required for this phase.** Nothing in a read-only preflight touches the portal build |
| Ansible | **not required for this phase.** The inventory validator reads YAML directly and says so: it "runs in CI without Ansible installed and without contacting a single host" |
| Real DNS, or explicit addresses in the inventory | §7 |
| Genuine trust for the destination | §6 |
| Route to the management network | §4 |

`curl` and `jq` are convenient for corroboration and are not required by any
code path here.

## 4. Network path

**Ports are deliberately not asserted for most families, because the repository
does not fix them.** Each driver fixes a *path*; the port is whatever the
operator records in the provider row's endpoint. Inventing conventional port
numbers here would be exactly the guesswork the brief forbids, so the table says
what the code fixes and marks the rest operator-supplied.

| Family | Driver | Path the adapter fixes | Port | Direction | Read-only phase | Later write phase |
| --- | --- | --- | --- | --- | --- | --- |
| Compute | `proxmox` | `<endpoint>/api2/json/version`, `/nodes`, `/access/permissions` | from the endpoint; the repository's own example tfvars uses **8006** | runner → cluster | yes | yes |
| Backup | `proxmox_backup` | `<endpoint>/api2/json/...` (the PBS tester extends the Proxmox one and then looks for datastores) | from the endpoint, operator-supplied | runner → PBS | yes | yes |
| Hosting | `cpanel` | `<endpoint>/json-api/version` | from the endpoint, operator-supplied | runner → WHM | yes | yes |
| Hosting | `directadmin` | `<endpoint>/CMD_API_SYSTEM_INFO` (commands sit at the root) | from the endpoint, operator-supplied | runner → panel | yes | yes |
| DNS | `cloudflare` | `/user/tokens/verify` against Cloudflare's own address — the catalogue marks this driver as needing no endpoint | 443 | runner → internet | yes | yes |
| Reverse DNS | `cloudflare_rdns` | same tester, different driver name | 443 | runner → internet | yes | yes |
| BMC | `redfish`, `ilo` | `/redfish/v1/` then `/redfish/v1/Systems` | from the endpoint, operator-supplied | runner → BMC | yes | later |
| BMC | `ipmi` | not HTTP — `ipmitool` over `lanplus` | IPMI's own UDP port, operator-supplied | runner → BMC | yes | later |
| Monitoring | Prometheus file_sd | scrape targets are a file, not an API this platform calls | the target file's own ports | runner → monitoring | yes | n/a |

All of it is HTTPS except IPMI, and `EndpointPolicy` refuses any provider
endpoint that is not (`a real provider speaks HTTPS; nothing else is dialled`).

**Management services are not to be exposed publicly to make this easier.** The
runner moves to the infrastructure. Opening a hypervisor API, a backup server, a
panel or a service processor to the internet trades a one-off access problem for
a permanent one, and none of the options in §3a requires it.

## 5. Interception negative control

This is the check that decides whether a candidate runner may be believed, so it
is now a tool rather than a procedure somebody remembers to follow:

```sh
python3 infrastructure/scripts/check-runner-trust.py
python3 infrastructure/scripts/check-runner-trust.py --target pve-1.<real-suffix>:8006
```

Exit 0 means the host does not fabricate destination identity. Exit 1 means it
does, and that every reachability result already taken from it should be
discarded rather than re-examined.

It makes three checks, and the reasoning behind each is in the file:

1. **A name that cannot exist.** A random label under `.invalid` must not
   resolve. If it resolves, the resolver is answering for names that do not
   exist; if it also yields a certificate this host verifies, something is
   minting identities.
2. **Addresses that belong to nobody.** RFC 5737 and RFC 3849 ranges must not
   answer. When one does, the check immediately asks it for a name that cannot
   exist — turning "something answered" into the unambiguous finding without
   needing a real target first.
3. **A deliberately wrong name for a real target**, when `--target` is given.
   The sharpest of the three, and the reason the flag exists.

What it deliberately does not do: it never disables certificate verification —
the failure it exists to catch is verification *succeeding* against the wrong
thing — and it does not fail a host for having a private certificate authority
in its trust store. An internal PKI is normal and correct (§6). The question is
never "is there a CA?" but "does that CA sign names that cannot exist?", which
is check 1.

**Run here, it correctly refuses this host:**

```
[PASS] impossible name
[FAIL] documentation addresses
 ... 3 of the 3 that did will claim any identity asked of it:
 198.51.100.1:443 answered a request for 'runner-trust-….invalid' with a
 certificate for 'runner-trust-….invalid' that THIS HOST VERIFIED; …
NOT TRUSTED — 1 of 2 check(s) failed.
```

Check 1 passes here, which is worth recording rather than smoothing over: this
sandbox's interception is at the transport layer, not the resolver, so a probe
that only tested DNS would have cleared it. That is why check 2 asks the
answering address for an impossible name instead of stopping at the connect.

`infrastructure/scripts/test_check_runner_trust.py` proves the tool tells a
forgery from a refusal, against real TLS servers on loopback: a throwaway
certificate authority is generated, trusted for the length of the test, and used
to issue a certificate for the exact name the probe asks about. Eight
assertions, and both directions are covered — an honest server that refuses an
unknown name must not be called a forgery, and an untrusted certificate for that
same name must not be either. Two deliberate breakages were run to confirm the
proof works: inverting the forgery verdict fails it, and quietly disabling
certificate verification fails it too. CI runs the self-test and never the tool,
because a GitHub runner is not the trusted runner and its verdict would mean
nothing.

## 6. DNS and PKI trust

| Requirement | State |
| --- | --- |
| Runner resolves the names the inventory uses | **PENDING** — depends on the real suffix (§8) and the operator's resolver |
| No interception replacing destination identity | **FAILED here**, and §5 is how the candidate runner is judged |
| Private CA for internal services | **PERMITTED, and the only supported route.** `HttpIdentityTester` has no verification flag: "A cluster with a private certificate authority is onboarded by adding that authority to the controller's trust store, which is a deployment change somebody makes once, rather than by switching verification off, which is a decision that quietly outlives the reason for it." |
| Self-signed certificates on Proxmox or a BMC | trust the issuing authority on the runner, or move those services to managed internal PKI. There is no third option in this codebase: no `verify=false`, no `-k`, no per-row override |
| `/etc/hosts` as the resolution mechanism | acceptable only if that is genuinely the infrastructure design, not as a way to skip DNS |

## 7. Private inventory source

**MISSING**, and it must not be committed here. `infrastructure/README.md` says
why: the real inventory "contains management addresses, BMC endpoints and the
topology of the provider's own network — a map of everything worth attacking".

**It needs no new schema, and it should not get one.** The existing validator
already anticipates it — "the real inventory lives in a private repository" —
and takes the inventory root as an argument. Laid out the same way, the private
inventory is validated by the tool this repository already ships:

```sh
python3 infrastructure/scripts/validate-inventory.py /srv/inventory
```

where `/srv/inventory/ansible/inventories/<environment>/hosts.yml` holds the
real hosts. **This was proved rather than assumed**: a private-shaped tree
outside this repository validated clean, and the same tree with a
`bmc_password:` variable added was refused —

```
FAIL … host 'pve-1' (group proxmox) has a variable named 'bmc_password',
     which reads as a secret
```

— so the mechanism that keeps credentials out of the inventory works on the
private one too, which is where it actually matters.

Required per host: `ansible_host` and `safety_class`, the latter one of
`DISCOVERY_ONLY`, `CONFIGURATION_ALLOWED`, `REIMAGE_ALLOWED`, `DO_NOT_TOUCH`.
`allow_reimage: true` is refused unless the class permits it.
`credentials_available` is the boolean that exists so nobody writes the
credential itself.

The inventory must identify: region, site, datacenter, rack, Proxmox cluster and
nodes, PBS endpoint and datastore, compute storage, bridges, VLANs, subnets,
templates, hosting nodes, BMC endpoints and monitoring endpoints — mapped onto
the concepts the control plane already models (§16, §17).

## 8. Naming decisions

The platform has exactly one naming authority and it is unset by design:

```
config('infrastructure.naming.internal_dns_suffix')   ← INFRASTRUCTURE_INTERNAL_DNS_SUFFIX
```

`DnsSuffix` refuses to compose a name until an operator sets it, and refuses
reference suffixes outright: "A real internal zone is a fact about somebody's
network, their resolver, their certificate authority and their registrar. It is
not a fact about this repository, and a default would become one the moment it
shipped."

Proved by execution against production policy today, `onOurHardware = true`:

| Name shape | Verdict |
| --- | --- |
| an ordinary registrable domain (`pve-1.mgmt.<owned-domain>`) | **ACCEPTED** |
| `*.prod.example`, `*.mgmt.example.com` | REFUSED — reserved for examples |
| `*.mgmt.test`, `*.mgmt.invalid` | REFUSED — reserved |
| `*.kw.lynomia.internal` | REFUSED — `.internal` |
| `*.mgmt.local` | REFUSED — `.local` |

### The `.internal` decision — escalated, not implemented

`.internal` is refused by `EndpointPolicy::FORBIDDEN_SUFFIXES` and this phase
does not change that. D-1 is closed (§2), so nothing in the repository currently
depends on the suffix; the question only becomes live if **the real fleet
already uses it**. If it does, that is an architecture decision for a person,
and the options are:

1. Move platform hosts to a subdomain of a domain the business owns. Costs a DNS
   change; costs nothing in this codebase; leaves the SSRF posture intact.
2. Design a narrowly trusted management-zone exception — a named suffix
   permitted only for rows whose machine is declared on our own hardware, never
   for an external provider endpoint. This is real security surface and needs a
   written decision, not a constant edited in passing.

Option 1 unless somebody with the authority to choose says otherwise. **Do not
weaken the policy to make discovery convenient.**

## 9. Credential architecture

The existing one, and no second store. The contract, read out of
`RecordCredentialReference`:

| Field | Rule |
| --- | --- |
| `backend` | `controller_environment` — the only backend the action accepts |
| `backend_reference` | must match `/^[A-Z][A-Z0-9_]{2,127}$/`. A value that does not look like an environment variable name is refused as `referenceLooksLikeAValue()` |
| `state` | set automatically to `Configured` or `Missing` by asking the resolver whether the name is set — the row records presence, never the value |
| `masked_hint` | at most four characters of a **public** identifier |
| `environment` | a `DeploymentEnvironment`, so a production row cannot be tested from staging |

`ControllerEnvironmentSecretResolver` reads `getenv` deliberately rather than
Laravel's `env()`, so a deployment with a cached config and no `.env` still
resolves. The database holds the reference; the process environment holds the
value.

### Secret injection on the runner

| Acceptable | Not acceptable |
| --- | --- |
| `systemd` `EnvironmentFile` owned by the service account, mode 0600 | a world-readable `.env` |
| a secret manager or Vault injecting into the process environment | secrets in a committed script |
| a self-hosted CI runner's secret store | plaintext in the inventory |
| | pasted into a report, a ticket or a shell history |

## 10–14. Read-only credentials, per family

All five are **MISSING**. What each must be able to do is derived from what the
testers and adapters actually read, so that least privilege is a scope rather
than a wish.

| § | Family | Credential shape the platform accepts | Read-only scope it must cover | Must NOT need |
| --- | --- | --- | --- | --- |
| 10 | Proxmox VE | an API token: `user@realm!tokenname=<secret>`. **Username and password are not supported by any code path** | `/version`, `/nodes`, storage, networks, templates, capacity, and `/access/permissions` so the platform can learn what the token may do without doing it | `VM.Allocate`, `VM.PowerMgmt`, `VM.Config.Disk` — the privileges that map to create, destroy, power and resize |
| 11 | PBS | same Proxmox token shape | identity, datastores, snapshot listing, and the verification **verdict** each archive carries | **not** the ability to start a verification. `ProxmoxBackupProvider::supportsVerification()` is false and the approved contract requires observing the verdict, not triggering it |
| 12 | Hosting — cPanel/WHM | a WHM API token stored as `user:token`. **A root password is not accepted** | product identity, licence status, packages, account and capacity listing | account creation, suspension, termination |
| 12 | Hosting — DirectAdmin | a login key stored as `user:key`. **An account password is not accepted** | the same four | the same three |
| 13 | BMC — Redfish/iLO | a controller account as `user:password`. Anonymous access to a controller with power control is explicitly unsupported | service root, `Systems`, manufacturer, model, serial, power **state** | power control, boot override, firmware, virtual media |
| 13 | BMC — IPMI | the same pair, via `ipmitool` | identity and inventory | chassis control |
| 14 | Cloudflare | an API token on its own, no prefix, no email. **The legacy global API key is not accepted** | `/user/tokens/verify`, zone listing, record read | any DNS write scope |

No full-administrator credential is to be reused because it already exists.

## 15. CredentialReference population

**0 rows, and they cannot be created from here** — a row whose value lives in
the runner's environment must be recorded on the runner, or its state is written
as `Missing` and means nothing.

The supported path exists and is not a database insert:

```
POST /api/admin/credentials
  { name, purpose, environment, backend: "controller_environment",
    backend_reference: "PROXMOX_READONLY_TOKEN", masked_hint?, rotates_at?, notes? }
```

## 16. ProviderInstance population

**0 rows.** The supported path exists — checked, because a missing one would be
the same architecture regression Gap 8 found for `vm_templates`:

```
POST /api/admin/providers
  { name, driver, category, environment, endpoint?, managed_server_id?, notes? }
POST /api/admin/providers/{provider}/credential   { credential_id }
POST /api/admin/providers/{provider}/connection-test
POST /api/admin/providers/{provider}/assess
```

Expected real rows for the approved scope: `proxmox`, `proxmox_backup`, one of
`cpanel` / `directadmin`, `cloudflare`, `cloudflare_rdns`, and one of
`redfish` / `ilo` / `ipmi` per controller family. `stripe` and `smtp` are
required by the readiness engine for every product and are separately
authorised.

**No controlled driver is to be registered**: not `fake`, `fake_compute`,
`fake_backup`, `fake_hosting`, `fake_bmc`, `fake_payment`, `fake_registrar`,
`fake_rdns`, `fake_wordpress`. The production guard in CI already refuses a fake
configured outside the development template.

**No WordPress installer and no registrar row.** Both products are `Prepared`;
`fake_wordpress` is the only `wordpress_installer` in the catalogue, and
`sy_registry` is a real class that declares no capabilities at all. `.sy` remains
`NOT_IMPLEMENTED`.

## 17. Site and provider mappings

**None.** Every mapping table the preflight reads is empty: `datacenters`,
`managed_servers`, `compute_clusters`, `vm_templates`, `hosting_nodes`,
`dns_zones`. The Admin API carries write paths for datacenters, racks and
servers, with classification, discovery and connection-test actions per server.
No business-code value is to be hardcoded for any of it.

## 18. Disposable VPS template

**NOT RECORDED**, and the reason has narrowed since Part I. The write path now
exists — Gap 8 added it — so this no longer requires a code change:

```
POST /api/admin/infrastructure/templates
  { cluster_id, slug, name: {en, ar}, os_family, os_version, architecture,
    provider_reference, cloud_init, guest_agent, requires_licence, licence_note? }
```

Both display names are required, because a catalogue entry in one language is
one a customer in the other reads in a language they did not choose. An entry
with no `provider_reference` is recorded but not installable, and placement
refuses it.

What is still missing is the real cluster to read images from, so
`cluster_id`, `provider_reference`, `os_version` and `architecture` cannot be
filled. The distro is not this document's to choose: it must be something the
real environment already stages. The image must carry no SSH private key, token,
password or customer data, and must never be a customer VM or a snapshot of one.

## 19–21. Storage, network, IPAM

| § | Item | State | What the trusted 30B.0 must read |
| --- | --- | --- | --- |
| 19 | Storage pool for a disposable VM | **NOT IDENTIFIED** | provider id, cluster or node scope, capacity, availability, and content type where the real contract exposes it. Read-only; nothing allocated |
| 20 | Bridge / VLAN | **NOT IDENTIFIED** | bridge, VLAN if applicable, subnet, gateway, DNS behaviour, isolation, whether it reaches the internet. No network configuration changed |
| 21 | Disposable IP path | **NOT IDENTIFIED** | that a pool exists with capacity, and that release on cleanup works. Nothing reserved in this phase |

On §21 there is a useful structural fact: `IpPoolScope` distinguishes `public`,
`private` and `management`, and management addresses may never be assigned to a
customer service — "handing one to a customer workload is a lateral-movement
path into the platform itself". A disposable validation address should come from
a pool that is neither management nor a customer production pool. **If no such
pool exists, creating one is a prerequisite for 30B.2 and is recorded as such
rather than worked around by borrowing a customer subnet.**

## 22. Hosting panel licence

**UNKNOWN** — and unknown is the honest word, not `INVALID` and not `MISSING`.
Nothing was contacted, so no licence was found to be absent or invalid.

The platform reads it rather than assuming it: `NodePreflightFacts::$licence`
carries "the vendor's answer, or null when the check could not run", and
`PreflightHostingNode` refuses when `$panel->requiresLicence()` and the answer is
null or not valid. There is also a licence register with its own write path
(`POST /api/admin/licences`, with product, type, environment, optional
`managed_server_id`, seats, expiry and renewal dates).

To close this, the operator determines from the actual deployment: which panel,
its identity, licence status and scope, and whether CloudLinux or LiteSpeed are
required by the packages Lynomia intends to sell. **No licence is to be
purchased or activated automatically.**

## 22a. Architecture finding — the sellable catalogue has no production write path

Found by doing §36's work: mapping real hosting packages onto Lynomia plans.
Raised rather than fixed, because the fix is application code and this phase is
environment preparation.

**Nothing can create a catalogue row in production.** Checked exhaustively:

| Model | Writers in `src/` or `app/` | Other writers |
| --- | --- | --- |
| `Product` (catalogue) | **none** | `CatalogueSeeder` |
| `Plan` (catalogue) | **none** — `PlanEngine` constructs `Infrastructure\Domain\DTOs\Plan`, a deployment plan, not this | `CatalogueSeeder` |
| `PlanPrice` | **none** | `CatalogueSeeder` |
| `HostingPackage` | **none** | `CatalogueSeeder`, `E2ESeeder`, `LoadReferenceTopologyForSimulation` |

`Catalog`'s controllers are `index` and `show`. There is no `store`, no request
object for one, and no route: a search of every non-GET route mentioning plan,
product or catalogue returns only deployment plans and readiness assessments.

And the one seeder that writes them refuses to run where it would matter:

```php
if (app()->isProduction()) {
    throw new RuntimeException(
        'CatalogueSeeder must never run in production: prices are an operator
         decision, not a fixture.'
    );
}
```

That refusal is correct — a seeder that invents prices will eventually invoice
somebody for them — but nothing was built to replace it.

**What it costs.** `CreateHostingAccountHandler` requires a `hosting_package_id`
and refuses when the package does not exist. The preflight already reports
`mapping.hosting_package` as **FAIL** for Shared Hosting: "No hosting package is
mapped, so an account has no plan to be created under." So a real panel
onboarded through the Control Center would have zero packages, every Shared
Hosting order would be refused, and the only ways forward would be raw SQL or a
code change. One level up, an order is placed against a `Plan`, and a production
deployment can have none of those either.

**This is the same defect class Gap 8 found in `vm_templates`, and by Gap 8's own
test.** That gap was called a blocker rather than a rough edge on the grounds
that "onboarding infrastructure this platform already models must not require a
code change; a product that cannot be delivered without one is not
code-complete". The catalogue meets that description exactly, and it is wider:
`vm_templates` blocked VPS builds, this blocks every sellable product's
existence.

**Not decided here.** Whether it moves `SOFTWARE_CODE_COMPLETE` is a scope
judgement for the owner of that claim, and this document does not flip it.
What is recorded is that the finding meets the standard Gap 8 itself applied,
and that closing it is an application-code task of its own: operator write paths
for product, plan, price and hosting package, with the authorisation, audit,
two-language naming and per-currency price rules the rest of the Admin API
already carries. **It is not closed by hardcoding a value, by re-enabling the
development seeder in production, or by loading the reference topology.**

**It does not block the trusted 30B.0.** A read-only preflight will report the
mapping as failed, which is the correct answer and costs nothing. It blocks
30B.3, the real Shared Hosting account lifecycle, and it blocks `READY_TO_SELL`
independently of everything else in this phase.

## 23. BMC inventory

**NOT BUILT.** `managed_servers` is empty. Per testable machine the private
inventory must carry: identifier, BMC endpoint, driver (`redfish` / `ilo` /
`ipmi`), manufacturer, model, serial, site and rack, `safety_class`, customer
ownership state, and disposable eligibility. **No BMC password goes in the
inventory** — the validator refuses a variable that reads as a secret, which was
demonstrated in §7.

A disposable chassis candidate for 30B.5 is **not chosen here**. Candidates may
be marked; the choice needs operator confirmation, and the safety classification
is what carries it.

## 24. Backup datastore and restore target

**NOT IDENTIFIED.** The trusted 30B.0 should read datastore identity, capacity
and the verification verdict visibility — and nothing else. No backup triggered,
no restore attempted, no prune, no garbage collection.

A future restore drill target must be a disposable VM or an isolated test
storage namespace. Never a customer VM, never a production machine, never an
unknown destination.

## 25. DNS zone

**NOT IDENTIFIED.** For the trusted 30B.0 this is read-only: account identity via
`/user/tokens/verify`, zone visibility, record read. A validation subdomain for
30B.2 is a later, separately authorised step and **is not to be created now**.

## 26. Monitoring endpoints

**NOT IDENTIFIED**, and the mechanism is already decided. Prometheus discovers
targets from `file_sd` files in
`infrastructure/monitoring/prometheus/targets/`, which are reference lists in
the `.example` namespace. Their own headers say the real list is operator-
supplied and lives with the private inventory, and that Prometheus re-reads such
a file within seconds — "replacing this file is the whole deployment step".

So the private inventory supplies the real Prometheus, Alertmanager, Grafana,
Loki and Alloy addresses, and nothing assumes localhost.

This matters for a claim Part II was careful about and this phase should stay
careful about: the preflight's `dependency.backup_metrics` PASS reports the
control plane's own metrics registry producing the series. It is not evidence
that a real Prometheus is scraping a real exporter. The trusted 30B.0 is where
that becomes checkable.

## 27. EndpointPolicy compatibility

**GREEN for the shapes a real estate will use**, established by running the
policy rather than reading it. Production mode:

| Endpoint | `onOurHardware` | Verdict |
| --- | --- | --- |
| `https://10.20.30.11:8006/` | true | **ACCEPTED** |
| `https://10.20.30.11:8006/` | false | REFUSED — private address for an external provider |
| `https://10.20.40.31:2087/` (panel) | true | **ACCEPTED** |
| `https://10.20.50.41/` (Redfish) | true | **ACCEPTED** |
| `https://100.64.0.5:8006/` (CGNAT) | true | **ACCEPTED** |
| `https://[fd00::1]:8006/` (IPv6 ULA) | true | **ACCEPTED** |
| `https://api.cloudflare.com/client/v4` | false | **ACCEPTED** |
| `https://169.254.169.254/` | true | REFUSED — cloud metadata |
| `https://127.0.0.1:8006/` | true | REFUSED — loopback |
| `http://198.51.100.9:8006/` | true | REFUSED — not HTTPS |
| `https://user:…@198.51.100.9:8006/` | true | REFUSED — a credential in an endpoint is a credential in a log line |
| every RFC 5737 address, with or without `onOurHardware` | either | REFUSED — reserved for documentation |

Machine addresses behave the same way: RFC1918, CGNAT and ULA accepted, with or
without a port; metadata refused with and without one; a controlled `fake://`
address refused in production.

**The §48 distinction is intact and is the point**: a privately addressed
machine the business owns is legitimate, and the same address offered as an
external provider's endpoint is an SSRF target. The policy needs no change for a
real estate, and none is proposed. If a legitimate endpoint is ever refused, the
finding to record is the endpoint, its category, the reason, the security
implication and the smallest safe fix — not a loosened constant.

## 28. Security posture of this phase

| | |
| --- | --- |
| Secrets read, printed, logged or committed | **none** |
| Certificate verification disabled anywhere | **never** — and the new tool is tested to fail if it ever is |
| `EndpointPolicy` weakened | **no** |
| Real systems contacted | **none** |
| Write operations | **zero** |
| Application code changed | **none** |

The only repository changes are the two scripts, one CI step that runs the
self-test, the `.gitignore` rule, the untracked bytecode and this document.

## 29. Tracked bytecode

**REMOVED.** `infrastructure/scripts/__pycache__/validate-inventory.cpython-311.pyc`
was tracked, so running the validators CI runs left a modified binary in the
working tree and invited it into the next commit. It is untracked, and
`__pycache__/` and `*.py[cod]` are ignored — in that order, because an ignore
rule added after a file is committed does nothing, which is the lesson the
`dump.rdb` entry above it in `.gitignore` already records.

No CI gate was added for it. The ignore rule is what stops the recurrence, and a
gate for a single file that can no longer be staged accidentally would be a
gate for its own sake.

Verified: all four validators and both self-tests run clean, and the working
tree stays clean afterwards.

## 30. Outstanding blockers

| Id | Blocker | Class | Who can clear it |
| --- | --- | --- | --- |
| **E-1** | **No trusted runner exists.** Nothing else in this phase can start | `BLOCKED_NETWORK` | operator — §3 |
| **E-2** | No route to the management network from any host available to this session | `BLOCKED_NETWORK` | operator |
| **E-3** | No private inventory. The layout and validation are settled (§7); the content is not | `BLOCKED_CREDENTIALS` | operator |
| **E-4** | No read-only credential for Proxmox, PBS, the panel, the BMCs or Cloudflare. 0 of 5 | `BLOCKED_CREDENTIALS` | operator — §10–14 |
| **E-5** | `credential_references` = 0 and `provider_instances` = 0, and both must be created **on the runner** | `BLOCKED_CREDENTIALS` | operator — §15, §16 |
| **E-6** | No disposable template observable. The write path exists; the cluster to read images from does not | `BLOCKED_HARDWARE` | operator — §18 |
| **E-7** | No chassis observable as racked, powered or cabled | `BLOCKED_HARDWARE` | operator — §23 |
| **E-8** | Hosting panel licence position `UNKNOWN` | `BLOCKED_LICENCE` | operator — §22 |
| **E-9** | The real internal DNS suffix is not chosen, and `.internal` would need an architecture decision | configuration, not a blocker label | operator — §8 |
| **E-10** | No non-customer validation subnet or IP pool identified | configuration, not a blocker label | operator — §21 |
| **E-11** | **The sellable catalogue has no production write path** — no product, plan, price or hosting package can be created outside a seeder that refuses to run in production. Blocks 30B.3 and `READY_TO_SELL`, not the trusted 30B.0 | architecture finding, not an environment blocker | **application change, §22a** |

E-9 and E-10 are deliberately not forced into a canonical blocker label. They are
configuration states with precise next actions, and calling them
`BLOCKED_NETWORK` would misdescribe both. E-11 is not an environment blocker at
all — no runner, route or credential closes it — which is why it is named
separately rather than folded into the list a person with estate access can work
through.

## 31. Readiness for the trusted 30B.0

Against the §61 exit criteria:

| Criterion | State |
| --- | --- |
| Trusted runner | **NOT READY** |
| Management route | **NOT READY** |
| No destination-identity interception | **PROVEN ABSENT? NO** — proven *present* here. The means to prove it on a candidate runner now exists (§5) |
| Private inventory | **NOT READY** — layout and validation settled, content missing |
| Real DNS / naming | **NOT READY** — mechanism settled (§8), suffix not chosen |
| Read-only Proxmox / PBS / hosting / BMC / Cloudflare credentials | **NOT READY** — 0 of 5, scopes specified |
| `CredentialReference` rows | **NOT READY** — 0, path known |
| `ProviderInstance` rows | **NOT READY** — 0, path known |
| Site / provider mappings | **NOT READY** — 0 |
| Hosting package mappings | **NOT READY** — 0, and no production write path exists to create one (§22a) |
| Disposable template | **NOT RECORDED** — write path exists |
| Storage target | **NOT IDENTIFIED** |
| Network / bridge | **NOT IDENTIFIED** |
| Disposable IP path | **NOT IDENTIFIED** |
| Hosting panel licence | **UNKNOWN** |
| Backup datastore | **NOT IDENTIFIED** |
| Monitoring endpoints | **NOT IDENTIFIED** — mechanism settled |
| EndpointPolicy compatibility | **GREEN** (§27) |

**Finished in this phase**, so that the next session does not redo it: the
runner requirements (§3b), the network path contract (§4), the negative-control
tool and its proof (§5), the private inventory layout with its validation
demonstrated (§7), the naming rules established by execution (§8), the exact
credential contract and per-family read-only scopes (§9–14), the onboarding call
sequence (§15, §16, §18), the EndpointPolicy compatibility matrix (§27), the
D-1 correction (§2), the catalogue write-path finding (§22a), and the bytecode
cleanup (§29).

**Waiting on a person with access to the estate**: E-1 through E-10.

The ordering is not negotiable. E-1 first; nothing between E-2 and E-8 can be
done from anywhere else, and the preflight is last.

## 32. Write operations

```
Write operations executed ....................... 0
Real systems contacted .......................... 0
VM / storage / network / DNS / hosting / backup .. 0
BMC power, reset, media, firmware ............... 0
IaC plan, IaC apply ............................. 0
Domains registered, payments charged ............ 0
Controlled providers registered ................. 0
Customer resources touched ...................... 0
REAL_* statuses changed ......................... 0
```

```
REAL_INFRA_VERIFIED ......... NONE
REAL_PAYMENT_VERIFIED ....... NONE
REAL_REGISTRAR_VERIFIED ..... NONE
REAL_HOSTING_VERIFIED ....... NONE
READY_TO_SELL ............... NONE
```
