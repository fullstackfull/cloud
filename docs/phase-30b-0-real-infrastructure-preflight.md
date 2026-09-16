# Phase 30B.0 — real-infrastructure preflight and safe discovery

**Status: BLOCKED.**

This document records what was observed on 2026-09-16 from the environment this
phase runs in. It is not a plan, a wish list, or a description of hardware
Lynomia intends to own. Where a system was not reachable, that is recorded as
the fact it is.

**Zero write operations were performed.** No VM was created, cloned, started,
stopped, rebooted, migrated, resized or deleted. No storage, network, cluster,
firewall, DNS record, domain, hosting account, backup, BMC power state or
payment was touched. No customer resource was used. No real transaction
occurred.

**No secret value appears anywhere in this document, and none may be added.**
Credential availability is reported only as `PRESENT` / `MISSING` /
`NOT_CONFIGURED`.

---

## 1. Provenance

| | |
| --- | --- |
| Repository | `fullstackfull/cloud` |
| Branch | `claude/hv-t6hq1p` |
| 30B.0 starting HEAD | `1d2cff8fb90d115d7d91f02d97ebf6145e211f91` |
| Observation window | 2026-09-16, closed 14:13:10 UTC |
| Observer | ephemeral Linux container, kernel 6.18.44 |

### Entry gate — Customer Portal

| | |
| --- | --- |
| Run | **164** |
| Id | **35103419855** |
| Attempt | **1** |
| SHA | `1d2cff8fb90d115d7d91f02d97ebf6145e211f91` |
| Status | completed |
| Conclusion | **success** |
| Jobs | **9 of 9, 0 failed** |

```
CUSTOMER PORTAL: FINALLY CLOSED
```

The gate was polled until it completed, and its failed-job count was queried
directly rather than inferred from the run conclusion. Nothing real was
contacted before it passed; the work done while waiting was local repository
reading only. No documentation commit was made to record run 164 — GitHub's run
is the evidence.

## 2. Starting HEAD

`git status` clean. Branch `claude/hv-t6hq1p`. `HEAD` =
`origin/claude/hv-t6hq1p` = `1d2cff8`. `git diff` empty. Nothing exists newer
than the Customer Portal final closure review. No reset, no force-push, no
history rewrite.

## 3. Environment safety boundary

This is the section that determines everything after it.

| Fact | Observed |
| --- | --- |
| Interfaces | `eth0`, `ifb0`, `ifb1`, `lo` |
| Sole routable address | **`192.0.2.2`** |
| Default route | via `eth0` |
| Resolver | `8.8.8.8`, `8.8.4.4` |
| Outbound | HTTPS only, through a proxy on `127.0.0.1:46349` |
| `no_proxy` | includes `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10` — so traffic to those ranges is *not* proxied, and this host has no route into any of them |

`192.0.2.0/24` is TEST-NET-1, the range RFC 5737 reserves for documentation. **A
host whose only routable address is in TEST-NET-1 is not attached to anyone's
production network.** That single fact settles most of what follows, and it is
the same position recorded on 2026-09-09 in
`docs/phase-30b-real-infrastructure-inventory.md` — re-verified here rather
than cited.

### 3a. The finding that makes probing from this environment untrustworthy

This is the most important observation in the whole preflight, and it would have
produced a false positive if taken at face value.

A TCP connect to `203.0.113.1:443` — the address the production inventory gives
its OPNsense edge firewall — **answered**. A naive probe would have recorded
"edge firewall reachable" and been completely wrong.

What actually answers there is the sandbox's own egress gateway. Its
certificate:

```
subject= CN = *.prod.example
issuer = O = Anthropic, CN = Egress Gateway SDS Issuing CA (production)
Verify return code: 0 (ok)
```

It answers for *any* SNI presented to it, and its CA is in this container's
trust store, so verification **passes**:

| SNI presented | Certificate returned | Verification |
| --- | --- | --- |
| `pve-1.prod.example` | `CN = *.prod.example` | 0 (ok) |
| `api.stripe.com` | `CN = *.stripe.com` | 0 (ok) |
| `this-host-does-not-exist-9f3a2b.invalid` | `CN = this-host-does-not-exist-9f3a2b.invalid` | **0 (ok)** |

The third row is the proof. `.invalid` is reserved by RFC 2606 precisely so that
such a name can never resolve to anything, and the gateway still produced a
trusted certificate for it.

**Consequences, stated plainly:**

1. In this environment a successful **TCP connect** is not evidence that a
   destination exists.
2. A successful **TLS handshake**, *including* `Verify return code: 0`, is not
   evidence that a destination exists.
3. Only the **HTTP layer** discriminates: a permitted host returns a real status
   (`api.github.com` → 200), and everything else is refused
   (`403 x-deny-reason: host_not_allowed`, or no connection at all).

§8 of the brief warns against bypassing certificate verification to make a probe
pass. This is the inverse hazard and it is worse, because it is silent:
verification succeeds against something that is not the target. Any future 30B
discovery run from an environment with an intercepting egress gateway must
validate at the application layer — an authenticated API version endpoint whose
response body identifies the product — and must never accept reachability from
connect-or-handshake alone.

## 4. Systems inventory

### What the repository declares

| Source | What it contains |
| --- | --- |
| `infrastructure/ansible/inventories/development/hosts.yml` | **EXAMPLE.** Header states it "does not describe any machine that exists" |
| `infrastructure/ansible/inventories/staging/hosts.yml` | **EXAMPLE.** Same |
| `infrastructure/ansible/inventories/production/hosts.yml` | **EXAMPLE.** "This file is an EXAMPLE and describes no machine that exists" |
| `infrastructure/tofu/environments/{staging,production}/terraform.tfvars.example` | **EXAMPLES.** `proxmox_endpoint = "https://pve-1.prod.example:8006/"`, `cloudflare_zone_id = "REPLACE-ME-32-hex-zone-id"`, a placeholder SSH key explicitly marked not real |

Every address in all three inventories is from RFC 5737 (`192.0.2.0/24`,
`198.51.100.0/24`, `203.0.113.0/24`) or RFC 3849 (`2001:db8::/32`), and every
hostname ends in `.example`. `infrastructure/README.md` states the reason: the
real inventory "does not live in this repository… it contains management
addresses, BMC endpoints and the topology of the provider's own network — a map
of everything worth attacking — and it belongs in a private inventory
repository with its own access control."

That is a deliberate and correct security posture. It also means **this
repository cannot tell this phase the address of a single real machine.**

### What the platform's own estate records contain

| Table | Rows |
| --- | --- |
| `provider_instances` | **0** |
| `credential_references` | **0** |

Queried against the local development database. No real endpoint is registered
anywhere this environment can see.

### Which providers the runtime selects

| Selector | Value | Real options the code offers |
| --- | --- | --- |
| `COMPUTE_PROVIDER` | `fake` | `proxmox` |
| `DEDICATED_PROVIDER` | `fake` | `redfish`, `ilo`, `ipmi` |
| `HOSTING_PROVIDER` | `fake` | `cpanel`, `directadmin` |
| `DNS_PROVIDER` | `fake` | `cloudflare` |
| `PAYMENT_PROVIDER` | `fake` | `stripe`, `myfatoorah` |
| `BACKUP_PROVIDER` | `fake` | `pbs` |

All six select a controlled fake.

### Trust-boundary classification

| Class | Systems |
| --- | --- |
| **A. LOCAL_CODE_ONLY** | the control plane, its PostgreSQL and Redis, the portal build — all on `127.0.0.1` in this container |
| **B. TEST/FAKE** | every provider family, via the six selectors above |
| **C. STAGING_REAL** | none observable |
| **D. PRODUCTION_REAL** | none observable |
| **E. UNKNOWN** | every real system named in the example inventories: their existence can be neither confirmed nor denied from here |

## 5. Credential availability

Twenty-five variables the real adapters would need were checked. **Every one is
`MISSING`.** No value was read, printed or logged.

| Variable | Status |
| --- | --- |
| `PROXMOX_HOST`, `PROXMOX_API_URL`, `PROXMOX_TOKEN_ID`, `PROXMOX_TOKEN_SECRET` | MISSING |
| `PBS_HOST`, `PBS_TOKEN` | MISSING |
| `CPANEL_HOST`, `CPANEL_TOKEN`, `WHM_TOKEN` | MISSING |
| `DIRECTADMIN_HOST`, `DIRECTADMIN_TOKEN` | MISSING |
| `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID` | MISSING |
| `STRIPE_SECRET`, `STRIPE_KEY`, `MYFATOORAH_TOKEN` | MISSING |
| `REDFISH_HOST`, `REDFISH_USERNAME`, `REDFISH_PASSWORD`, `IPMI_HOST`, `ILO_HOST` | MISSING |
| `REGISTRAR_API_KEY` | MISSING |
| `SSH_PRIVATE_KEY` | MISSING |
| `GRAFANA_TOKEN`, `PROMETHEUS_URL` | MISSING |

A structural note worth recording, because it is a *good* design rather than a
gap: the `config/*.php` files for the real adapters contain no endpoint or
credential keys at all — only timeouts, TLS-verification flags and tuning
ratios. Real endpoints and secrets are resolved at runtime from the estate's
`CredentialReference` rows through
`Providers/Infrastructure/ControllerEnvironmentSecretResolver`, which reads
`getenv` deliberately rather than Laravel's `env()` helper, so that a production
deployment with a cached config and no `.env` still resolves secrets. With zero
`credential_references` rows, there is nothing to resolve.

### Credential classification

| System | Class |
| --- | --- |
| Proxmox | `BLOCKED_CREDENTIALS` |
| PBS | `BLOCKED_CREDENTIALS` |
| SSH to platform hosts | `BLOCKED_CREDENTIALS` |
| BMC / iLO / IPMI | `BLOCKED_CREDENTIALS` |
| Hosting panel (cPanel/WHM, DirectAdmin) | `BLOCKED_CREDENTIALS` |
| DNS (Cloudflare) | `BLOCKED_CREDENTIALS` |
| Registrar | `NOT_IMPLEMENTED` — see section 17 |
| Payment (Stripe) | `BLOCKED_CREDENTIALS` |
| Payment (MyFatoorah) | `NOT_IMPLEMENTED` — no adapter exists |
| Monitoring | `BLOCKED_CREDENTIALS` |
| Object storage | `NOT_IMPLEMENTED` for real use — readiness-only per the ADD phase |
| Mail | `NOT_REQUIRED` for this preflight |

## 6. Network dependency map

Every row is a connection attempt from this container. Nothing was
authenticated against and nothing was changed.

| Source | Destination | Port/proto | Expected | Observed | Status |
| --- | --- | --- | --- | --- | --- |
| container | `203.0.113.51` pve mgmt | 8006/tcp | Proxmox API | unreachable | `BLOCKED_NETWORK` |
| container | `203.0.113.61` pbs | 8007/tcp | PBS API | unreachable | `BLOCKED_NETWORK` |
| container | `203.0.113.71` whm | 2087/tcp | WHM | unreachable | `BLOCKED_NETWORK` |
| container | `203.0.113.81` bmc | 623/tcp | IPMI | unreachable | `BLOCKED_NETWORK` |
| container | `203.0.113.1` opnsense | 443/tcp | edge firewall | **answered — but it is the egress gateway, not a firewall** (§3a) | `BLOCKED_NETWORK` |
| container | `10.0.0.1` | 22/tcp | management | unreachable | `BLOCKED_NETWORK` |
| container | `172.16.0.1` | 22/tcp | management | unreachable | `BLOCKED_NETWORK` |
| container | `192.168.1.1` | 8006/tcp | management | unreachable | `BLOCKED_NETWORK` |
| container | `100.64.0.1` | 22/tcp | CGNAT | unreachable | `BLOCKED_NETWORK` |
| container | `api.cloudflare.com` | 443/https | DNS API | refused by proxy | `BLOCKED_NETWORK` |
| container | `api.stripe.com` | 443/https | payment API | refused by proxy | `BLOCKED_NETWORK` |
| container | `api.myfatoorah.com` | 443/https | payment API | refused by proxy | `BLOCKED_NETWORK` |
| container | `www.proxmox.com` | 443/https | vendor | refused by proxy | `BLOCKED_NETWORK` |
| container | `api.github.com` | 443/https | control (permitted) | **HTTP 200** | reachable |

DNS resolution of the names the monitoring configuration actually scrapes:

| Name | Result |
| --- | --- |
| `pve-01.kw.lynomia.internal` | NXDOMAIN |
| `web-01.kw.lynomia.internal` | NXDOMAIN |
| `pbs-01.kw.lynomia.internal` | NXDOMAIN |

The GitHub row is the control: it proves the probe method works and that the
refusals above are refusals rather than a broken test.

No firewall, VLAN, route, NAT, switch, gateway or DNS configuration was changed.

## 7. Proxmox discovery

**Not performed. `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS`.**

The three preconditions the brief sets — endpoint known, network reachable,
credentials present and read-only-capable — fail at the first. The repository
names no real endpoint; the example endpoint does not resolve to a machine;
`PROXMOX_*` is `MISSING`; and per §3a no probe from here could distinguish a
real Proxmox API from the egress gateway even if an address were supplied.

No API call was attempted. Nothing is claimed.

## 8. Compute-node inventory

**Not observed.** Zero real nodes discovered, so there is nothing to compare
field-by-field against the repository's expectations. Every field the brief
asks for — node name, Proxmox version, kernel, CPU model, sockets/cores/
threads, RAM, local and shared storage, interfaces, bridges, cluster and HA
membership, VM and CT counts — is `NOT_OBSERVED`.

## 9. Cluster status

**Not observed.** No cluster was contacted, so quorum, membership, expected
votes, node state, corosync, HA and replication are all `NOT_OBSERVED`. Nothing
was added, removed, restarted or edited.

## 10. Storage inventory

**Not observed.** `NOT_OBSERVED` for every storage target. No volume was
created or deleted, no configuration altered, no benchmark run.

## 11. Template and image inventory

**Not observed — and this is a blocker for the first write phase.**

No template or image inventory exists because no hypervisor was reachable. The
consequence for planning is specific: **there is no known disposable
provisioning template**, so §30's precondition "disposable template known"
cannot be satisfied, and the first disposable VPS cannot be planned against a
real template id. This is recorded as `30B_BLOCKER-5` rather than papered over
with a guess.

## 12. PBS discovery

**Not performed. `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS`.** No prune, GC,
delete, restore, datastore change or retention change was run — and none would
have been in a read-only phase regardless.

A pre-existing gap deserves repeating here because it bears directly on backup
trust: `infrastructure/monitoring/README.md` declares a textfile-collector
contract for `lynomia_backup_*` series, and nothing implements it. Six alerts in
`monitoring/prometheus/rules/backups.yml` therefore cannot fire, **including the
two that exist to catch an unverified backup — the failure that looks exactly
like success until somebody attempts a restore.** `scripts/validate-monitoring.py`
names the gap on every CI run, so it stays visible. Classified
`30B_RISK`, carried from the earlier 30B work, not introduced here.

## 13. Hosting discovery

**Not performed. `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS`.**

Which panel is deployed was not assumed. The repository's example inventory
names both — `web-1.prod.example` with `hosting_panel: cpanel` and
`web-2.prod.example` with `hosting_panel: directadmin` — and the code implements
adapters for both. Neither was contacted. No account was created, suspended or
repackaged.

```
REAL_HOSTING_VERIFIED: NONE
```

## 14. BMC / iLO discovery

**Not performed. `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS` + `BLOCKED_HARDWARE`.**

`BLOCKED_HARDWARE` is included deliberately: beyond the absent credentials and
route, there is no evidence available to this environment that any chassis is
racked, powered or cabled. No power operation, BMC reset, BIOS change, virtual
media mount, boot-order change, RAID change, firmware action or user
modification was performed or attempted.

```
Dedicated power idempotency: DEFERRED — ARCHITECTURE ITEM
```

Unchanged by this phase, as the brief requires.

## 15. Monitoring discovery

**Not performed against real targets. `BLOCKED_NETWORK`.**

The monitoring *configuration* is present and reviewable in the repository —
Prometheus with eight target files, Alertmanager, Grafana, Loki, Alloy,
blackbox and a PVE exporter. The question the brief asks is whether real
infrastructure targets are actually represented, and the answer is: the target
files name `*.kw.lynomia.internal` hosts, none of which resolve from here. No
target health, scrape status, rule status, alert state or datasource
reachability was read. No alert was silenced, no rule modified, nothing
restarted.

## 16. DNS discovery

**Not performed. `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS`.** `api.cloudflare.com`
is refused by the proxy and `CLOUDFLARE_API_TOKEN` is `MISSING`. No zone or
record was read, and none was modified.

## 17. Registrar discovery

**Not performed. `NOT_IMPLEMENTED`.**

This is the one family where the blocker is in the code rather than the
environment, and the code is honest about it. `DomainRegistrarProvider` has two
implementations: `FakeDomainRegistrarProvider`, and `SyRegistryProvider`, whose
own docblock reads *"The seat `.sy` will occupy, and nothing more"* and explains
that every method refuses because the Syrian registry's technical contract —
protocol, endpoints, authentication, contact requirements, term limits, grace
and redemption rules — is not available to the project. Writing an EPP or REST
client against an unknown contract would be inventing a registry's behaviour and
then testing the invention.

`docs/phase-30b-registrar-selection.md` records that **the selection is not
made**, blocked by `BLOCKED_NETWORK` (no candidate registrar API is reachable to
evaluate) and `BLOCKED_CREDENTIALS`.

```
REAL_REGISTRAR_VERIFIED: NONE
```

No lookup, registration, transfer, renewal or contact update was attempted.

## 18. Payment discovery

**Not performed. `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS`.**

`StripePaymentProvider` exists in code; `api.stripe.com` is refused by the proxy
and no Stripe credential is present. MyFatoorah is offered as a selector value
but **no adapter implements it** — the only matches in the source are an enum
entry and a registry mention, so it is `NOT_IMPLEMENTED`.

Nothing was charged, captured, refunded or voided. No webhook configuration was
read or written.

```
REAL_PAYMENT_VERIFIED: NONE
```

## 19. Git / IaC expected vs observed reality

| System | Expected from Git/IaC | Observed reality | Difference | Risk | Action |
| --- | --- | --- | --- | --- | --- |
| Proxmox nodes | `pve-1/2.prod.example` at `203.0.113.51/52`, mgmt `eno1`, bridges `vmbr0` (VLAN-aware, customer) and `vmbr1` (storage) | none reachable | cannot compare | — | supply a real inventory to a routed environment |
| Storage ids | not declared in the example inventory | none observed | undeclared on both sides | — | declare in the private inventory |
| Bridges / VLANs | `vmbr0` VLAN-aware, `vmbr1` flat | none observed | cannot compare | — | as above |
| PBS datastore | `vm-backups` at `/mnt/datastore/vm-backups`, prune 14d/8w/12m/2y | none observed | cannot compare | — | as above |
| Template ids | **not declared anywhere** | none observed | missing on both sides | **blocks first write** | choose and declare a disposable template |
| Hosting hostname | `web-1` cPanel, `web-2` DirectAdmin | none observed | cannot compare | — | as above |
| BMC endpoints | `dedi-1.prod.example` at `203.0.113.81`, `lynomia_never_configure: true` | none observed | cannot compare | — | as above |
| Monitoring targets | `*.kw.lynomia.internal` | NXDOMAIN | **the two naming schemes disagree**: inventories use `*.prod.example`, monitoring uses `*.kw.lynomia.internal` | `30B_DRIFT` | reconcile in the private inventory; do not "fix" either file here |

Nothing was auto-reconciled. The naming disagreement is recorded as drift and
left alone, per §21 and §10 of the brief.

## 20. Drift findings

| Id | Finding | Class |
| --- | --- | --- |
| D-1 | Example inventories use `*.prod.example`; monitoring target files use `*.kw.lynomia.internal`. Two naming schemes for the same fleet | `30B_DRIFT` — UNKNOWN until a real inventory exists to arbitrate |
| D-2 | No template or image id is declared anywhere in the repository | `30B_BLOCKER` — see blocker 5 |
| D-3 | `lynomia_backup_*` textfile collector declared by `monitoring/README.md`, implemented by nothing; six backup alerts cannot fire | `30B_RISK`, pre-existing and CI-visible |

**IaC drift: UNKNOWN.** No `tofu plan` was run. The brief permits a read-only
plan where safe; it is not safe here, because a plan requires provider
credentials and a reachable endpoint, and with neither it would either fail at
initialisation or — worse — appear to succeed against the egress gateway. No
`apply` was run and none was considered.

## 21. Capability verification matrix

The distinction the brief insists on is kept: `RUNTIME_VERIFIED` means verified
against this platform's own fakes or local runtime. It is **not**
`REAL_INFRA_VERIFIED`.

| Capability | Adapter in code | Status | Blocker |
| --- | --- | --- | --- |
| Proxmox API authentication | `ProxmoxComputeProvider`, `ProxmoxConnection` | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS` |
| Proxmox node discovery | same | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same |
| Proxmox storage discovery | same | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same |
| Proxmox template discovery | same | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same |
| Proxmox VM discovery | same | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same |
| VPS creation | `CreateVpsHandler` → `ComputeProviderFactory` → Proxmox | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same — **NOT YET REAL_INFRA_VERIFIED** |
| VPS deletion | same chain | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same — **NOT YET REAL_INFRA_VERIFIED** |
| PBS discovery | `ProxmoxBackupProvider` | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same |
| PBS restore | `FileLevelBackupProvider`, `ProxmoxBackupProvider` | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same — **NOT YET REAL_INFRA_VERIFIED** |
| Hosting panel discovery | `CpanelHostingProvider` + `WhmConnection`; `DirectAdminHostingProvider` + `DirectAdminConnection` | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS` |
| Hosting account creation | same | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | same — **NOT YET REAL_HOSTING_VERIFIED** |
| BMC / iLO discovery | `RedfishDedicatedProvider`, `IloDedicatedProvider`, `IpmiDedicatedProvider`, `BmcConnection` | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS` + `BLOCKED_HARDWARE` |
| Monitoring discovery | Prometheus/Alertmanager/Grafana/Loki/Alloy configuration | `CODE_COMPLETE` | `BLOCKED_NETWORK` |
| DNS read | `CloudflareDnsProvider` | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS` |
| Registrar read | `SyRegistryProvider` refuses by design | `NOT_IMPLEMENTED` | selection not made |
| Payment read (Stripe) | `StripePaymentProvider` | `CODE_COMPLETE`, `TESTED`, `RUNTIME_VERIFIED` | `BLOCKED_NETWORK` + `BLOCKED_CREDENTIALS` |
| Payment read (MyFatoorah) | none | `NOT_IMPLEMENTED` | — |

**`REAL_INFRA_VERIFIED`: NONE.** Not one capability. Not even "Proxmox API
authentication", because there was no endpoint to authenticate to — and per §3a,
a handshake from this environment would not have proved one existed.

## 22. Blockers

| Id | Blocker | Class |
| --- | --- | --- |
| **30B_BLOCKER-1** | No real infrastructure is reachable. The container's only routable address is in TEST-NET-1; it has no route into any RFC1918 or CGNAT range; and the proxy permits no provider API this platform integrates with | `BLOCKED_NETWORK` |
| **30B_BLOCKER-2** | No credential for any real system exists in this environment. 25 of 25 checked are `MISSING`, and the estate holds 0 `credential_references` | `BLOCKED_CREDENTIALS` |
| **30B_BLOCKER-3** | No real inventory is available to this phase. All three committed inventories are examples using documentation ranges — by deliberate design, with the real one in a private repository | `BLOCKED_CREDENTIALS` (access to the private inventory) |
| **30B_BLOCKER-4** | **Discovery from this environment cannot be trusted even if 1–3 were solved.** The egress gateway returns a valid, trusted certificate for any hostname including a `.invalid` name, so connect and handshake success prove nothing. Any 30B execution environment must be free of application-layer interception, or all reachability must be established through an authenticated endpoint that identifies the product | `BLOCKED_NETWORK` |
| **30B_BLOCKER-5** | No disposable provisioning template is declared anywhere in the repository or observable anywhere in reality, so §30's "disposable template known" precondition cannot be met | `BLOCKED_HARDWARE` pending a real hypervisor |
| **30B_BLOCKER-6** | No chassis is observable as racked, powered or cabled | `BLOCKED_HARDWARE` |
| **30B_BLOCKER-7** | Registrar selection is not made and no functioning registrar adapter exists | `NOT_IMPLEMENTED` |

## 23. Risks

| Id | Risk | Class |
| --- | --- | --- |
| R-1 | The `lynomia_backup_*` collector is unimplemented, so the two alerts that exist to catch an *unverified* backup cannot fire. A backup that silently fails verification looks exactly like a backup that worked, until a restore is attempted | `30B_RISK` |
| R-2 | Two naming schemes for the same fleet (`*.prod.example` vs `*.kw.lynomia.internal`) will make the first real reconciliation ambiguous | `30B_DRIFT` |
| R-3 | A future operator running this preflight in an intercepting environment could record false reachability. Recorded as §3a so it is not rediscovered the hard way | `30B_INFORMATION` |

## 24. Exact read-only operations performed

Every probe, with its classification. No authorization header was sent, no
credential-bearing URL was constructed, and no secret material appears below.

| # | Time (UTC) | System | Operation | R/W | Result |
| --- | --- | --- | --- | --- | --- |
| 1 | 14:05 | GitHub Actions | read run 164 status and failed-job count | READ | success, 9/9, 0 failed |
| 2 | 13:45 | local git | `status`, `rev-parse`, `diff`, `log` | READ | clean, HEAD = `1d2cff8` |
| 3 | 13:46–13:58 | local repository | read inventories, group_vars, tofu vars, monitoring config, provider adapters, prior 30B docs | READ | all examples; adapters enumerated |
| 4 | 13:52 | local PostgreSQL | count `provider_instances`, `credential_references` | READ | 0 and 0 |
| 5 | 13:55 | container env | existence check on 25 credential variables | READ | 25 MISSING |
| 6 | 14:09 | container | interfaces, addresses, routes, resolver, proxy vars | READ | sole address `192.0.2.2` |
| 7 | 14:10 | 5 documented mgmt addresses | TCP connect | READ | 4 unreachable, 1 answered as the gateway |
| 8 | 14:10 | 4 RFC1918/CGNAT gateways | TCP connect | READ | all unreachable |
| 9 | 14:11 | `203.0.113.1:443` | TLS handshake + certificate inspection, HTTP HEAD | READ | egress gateway; `403 host_not_allowed` |
| 10 | 14:12 | `203.0.113.1:443` | TLS handshake with a `.invalid` SNI | READ | trusted cert minted — §3a |
| 11 | 14:12 | 4 provider API hostnames | HTTPS HEAD | READ | all refused |
| 12 | 14:12 | `api.github.com` | HTTPS HEAD (control) | READ | 200 |
| 13 | 14:13 | 3 monitoring target names | DNS resolution | READ | NXDOMAIN |

Certificate verification was never disabled. No `insecure`, `verify=false` or
equivalent was used anywhere.

## 25. Confirmation that zero writes occurred

```
Write operations executed ....................... 0
VM created / cloned / deleted ................... 0
Power state changed ............................. 0
Storage created / modified / deleted ............ 0
Network, VLAN, firewall, routing, NAT changed ... 0
Cluster membership or quorum changed ............ 0
PBS prune / GC / delete / restore ............... 0
DNS records changed ............................. 0
Domains registered / transferred / renewed ...... 0
Payments charged / captured / refunded .......... 0
Hosting accounts created / suspended ............ 0
BMC power / BIOS / media / RAID / firmware ...... 0
IaC plan run .................................... 0
IaC apply run ................................... 0
Production resources changed .................... 0
Customer resources touched ...................... 0
Real transactions performed ..................... 0
Dedicated power idempotency changed ............. NO
First disposable VPS created .................... NO
```

The only mutations this session made anywhere were: starting the local
PostgreSQL and Redis services in this container after an idle period, and
writing this document. Neither touches real infrastructure.

## 26. Plan for the first disposable VPS — **not executed**

Prepared for a later, separately approved stage. Every field marked `TBD` is
`TBD` because the preflight could not observe it, and each is a gate on
execution rather than a value to guess.

| Item | Plan |
| --- | --- |
| Target node selection rule | the node with the most free memory after applying `PROXMOX_NODE_MEMORY_HEADROOM_PERCENT` and `PROXMOX_NODE_CPU_OVERCOMMIT_RATIO`, and fewer than `COMPUTE_MAX_CUSTOMER_VMS_PER_NODE` guests; tie broken by lowest VM count |
| Target node | `TBD` — no node observed |
| Storage | `TBD` — no storage observed. Must be a thin-provisioned local or shared target with `images` content enabled |
| Network / bridge | `vmbr0` per the example inventory, once a real inventory confirms it |
| VLAN | `TBD` — the example declares `vmbr0` VLAN-aware but names no customer VLAN id |
| Template | `TBD` — **blocker 5.** Must be a cloud-init-enabled template, recorded by id, never created by this phase |
| CPU / RAM / disk | 1 vCPU, 1024 MiB, 10 GiB — the smallest shape the catalogue's cheapest plan uses, so nothing about the test depends on a large allocation |
| cloud-init | one throwaway ed25519 public key generated for the run, never committed, private half destroyed with the VM; no password authentication |
| Temporary IP strategy | one address from a reservation pool marked non-customer, released on cleanup; never an address the IPAM module could assign to a customer |
| Naming | `zz-validation-30b-<UTC timestamp>` — the `zz-` prefix sorts it last in every list and cannot collide with a customer hostname |
| Tag | `lynomia-disposable-validation` on the guest, plus the same value in the control plane's own record |
| Expected control-plane records | one `provisioning_job`, one `service`, one `virtual_machine`, all owned by an operator-created validation account — **never a real customer** |
| Expected job states | `queued` → `running` → `succeeded`, with the provider task id recorded and polled rather than assumed |
| Timeout | 15 minutes to `succeeded`; on expiry the run is a failure and cleanup begins |
| Cleanup | stop, then delete the guest, then release the address, then delete the control-plane records, then verify by re-reading the node's VM list and confirming absence. Cleanup is idempotent and runs on both success and failure |
| Failure recovery | if deletion fails, the guest stays stopped and tagged, the run is recorded as failed with the orphan's id, and the next step is manual — the automation never retries a destructive operation against an unknown state |

**§30's twelve preconditions, honestly scored:** management access known ✗,
network paths understood ✗, credentials scoped ✗, target node known ✗, target
storage known ✗, target network known ✗, disposable template known ✗, cleanup
path known ✓ (above), rollback/recovery strategy known ✓ (above), monitoring
visibility known ✗, backup position known ✗, naming and tagging standard known
✓ (above).

**Three of twelve. No first VPS.**

## 27. Recommended 30B.1 scope

**30B.1 as a validation phase cannot be scheduled from here, and scheduling it
would be the wrong answer.** Blockers 1–4 are properties of the execution
environment, not of the platform's code. The code is `CODE_COMPLETE`,
`TESTED` and `RUNTIME_VERIFIED` for every real adapter that exists; what is
missing is somewhere to point it.

So the single recommended next step is a prerequisite, not a phase of
validation:

> **30B.0-E — provide an execution environment for real-infrastructure
> validation.**
>
> 1. An operator-run environment with a route to the real management network,
>    and **no application-layer interception** — or, if interception is
>    unavoidable, a documented method that establishes reachability only
>    through an authenticated endpoint whose response identifies the product
>    (see §3a).
> 2. The real inventory, from the private inventory repository, naming the
>    nodes, storage, bridges, VLANs, PBS datastore, hosting hosts and BMC
>    endpoints that actually exist.
> 3. Read-only, separately-scoped credentials for Proxmox, PBS, the hosting
>    panel, the BMCs, Cloudflare and Stripe — issued as `CredentialReference`
>    rows resolved from the controller's own environment, which is the
>    mechanism the platform already implements. Read-only first; write scopes
>    are a later, separate grant.
> 4. One disposable cloud-init template chosen and recorded by id, closing
>    blocker 5.
>
> When those four exist, 30B.0 is re-run in that environment — it is cheap, it
> is entirely read-only, and it produces the node, storage, template and
> network facts that §30 requires. Only then does a first-write phase become
> schedulable.

Nothing about the registrar or MyFatoorah belongs in that step: both are
`NOT_IMPLEMENTED` and neither is on the path to a first VPS.
