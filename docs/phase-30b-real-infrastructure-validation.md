# Phase 30B — real infrastructure validation

**Verdict: NO GO for `REAL_INFRA_VERIFIED` on every capability.**

Phase 30B's job was to move capabilities from "tested against a fake" to "proven
against a real provider". It could not, and the reason is not the software. The
environment this phase runs in has no route to any management network, no
credential for any provider, no hardware to act on, and an outbound proxy that
refuses every third-party API this platform integrates with.

What the phase delivered instead is everything that does not require reaching a
machine — the infrastructure source of truth, the safety gates that make
classification mechanical, the monitoring configuration, the network design, the
runbooks, and the CI that validates all of it. Those are real and reviewable.

Nothing in this report is marked verified on the strength of a plan.

---

## A. Actual starting commit

| Field | Value |
| --- | --- |
| Branch | `claude/hv-t6hq1p` |
| HEAD at phase start | `f755083` |
| Working tree | Clean |

Phase 30A++'s measured work ended around `56ed5f1`. That commit was **not** reset
to; the four commits after it (`90c3fa3`, `c47210f`, `074881c`, `f755083`) are
intact, and the baseline below was re-measured on the real HEAD rather than
carried forward.

```
$ git log --oneline -5
f755083 Prove a domain purchase against a worker that is not this process
074881c Record the dependency advisory in the phase report
c47210f Drop a dependency nobody used and a vulnerability with it
90c3fa3 Write down what these two products can and cannot do
56ed5f1 Two things that were built and could not happen
```

### Software baseline, re-measured on `f755083`

```
$ php artisan test
{"tool":"phpunit","result":"passed","tests":2446,"passed":2446,
 "assertions":69585,"duration_ms":247609}
```

| Measure | Value |
| --- | --- |
| Backend tests | 2,446, all passing |
| Assertions | 69,585 |
| PHPStan errors | 0 |
| Migrations | 45 |
| OpenAPI operations | 150 |
| Metric families | 41 |
| Browser E2E specs | 105 across 15 files |

The figures match Phase 30A++'s close, as they should: this phase added
infrastructure configuration and documents, and deliberately no product code.

---

## A2. A mistake this phase made, and what it produced

The phase's opening rule is *do not create a second infrastructure system if one
already exists*. It did, for several hours.

The first survey listed `infra/`, `ansible/`, `opentofu/`, `terraform/`,
`deploy/`, `scripts/` and `monitoring/`, found none of them, and concluded there
was no infrastructure tree. It never listed `infrastructure/` — which holds 15
Ansible roles, 11 playbooks, an OpenTofu tree with two environments, a monitoring
stack with 55 alerts and 9 recording rules across six files, and three example
inventories. A second tree at `infra/` was built beside it and committed.

It was caught by reading `docs/deployment.md`, whose machine roles did not match
the group names in the new inventories — the mismatch that was supposed to be
impossible was itself the signal. The duplicate has been deleted; everything
worth keeping was folded into `infrastructure/`, and the runbooks into
`docs/runbooks/` beside the three that were already there.

Working through the existing tree with the new checks then found four things
that were already wrong:

1. **Ten invented artisan commands in the three pre-existing runbooks.** The
   worst of them is in `provisioning-stuck.md` §4 — the timeout-after-creation
   case, the one that costs a customer two VMs — where all five commands for
   finding and adopting an orphaned resource named binaries that do not exist.
   The real surface is `compute:poll-tasks`, `infrastructure:reconcile`, and
   three operator endpoints under `/admin/provisioning/`.

2. **Seven directories `infrastructure/README.md` claimed to have** —
   `proxmox/`, `pbs/`, `pxe/`, `networking/`, `security/`, `dedicated/`,
   `hosting/` — none of which exist.

3. **Six backup alerts that cannot fire.** `monitoring/README.md` declares a
   textfile-collector contract for `lynomia_backup_*` and says the collector
   belongs to `infrastructure/pbs`. Nothing implements it. Two of the six exist
   to catch an unverified backup — the failure that looks exactly like success
   until somebody tries to restore.

4. **No safety classification anywhere.** The tree had per-operation flags
   (`allow_reimage`, `pxe_allow_serve_dhcp`, `proxmox_cluster_join_enabled`,
   `postgresql_allow_initdb`), all absent by default, which is a good design for
   "is this destructive thing scheduled today". It had nothing for the question
   that comes first: may we connect to this machine and write to it at all.

5. **A component with no deployment path.** `docs/deployment.md` lists eleven
   machine roles. Ten have an inventory group; `console_gateway` has none, and
   no Ansible role and no playbook either. `console-gateway:serve` exists and
   runs — nothing puts it on a machine. It is the one component that
   deliberately bridges the public and management networks, which makes it the
   worst one to have no deployment story.

All five are fixed or, where they cannot be fixed here, made loud — see G and
the sections below. Each of the three that cannot be fixed here is now
acknowledged in writing next to the thing it affects, and the check that found
it fails the build if that acknowledgement is deleted while the gap remains.
That is the pattern this phase settled on for a gap it cannot close: the gap may
stand, silence about it may not.

The mistake produced a better result than a clean run would have, which is not a
defence of making it.

---

## B. Actual infrastructure inventory

Full detail in `docs/phase-30b-real-infrastructure-inventory.md`. Summary:

| Category | Found |
| --- | --- |
| Proxmox nodes | None |
| PBS datastores | None |
| Shared hosting nodes | None |
| Dedicated servers / BMC | None |
| Staging hosts | None |
| Monitoring hosts | None |
| Machines of any kind | One, at `192.168.1.1:443`, identity unknown |

The one machine that answered presents a TLS certificate for `CN=default.domain`
whose SANs do not cover its own address. It is almost certainly the sandbox
host's gateway. It has no established relationship to Lynomia, nobody has
authorised anything against it, and probing stopped at that observation.

**Every physical machine is classified `DO_NOT_TOUCH`**, which is the default and
the only defensible classification for a machine whose owner has not spoken. No
machine is `DISCOVERY_ONLY`, `CONFIGURATION_ALLOWED` or `REIMAGE_ALLOWED`,
because those are authorizations and none has been given.

The staging and production inventories contain zero hosts.

---

## C. Network topology

Designed in `docs/phase-30b-network-flow-matrix.md`. Four networks — Public,
Management, Storage, Install — with the Management network unreachable from the
internet, from the Public network, and from any customer VPS.

**Observed** topology of the environment running this phase:

| Fact | Value |
| --- | --- |
| Addresses | Loopback, and one address in `192.0.2.0/24` (TEST-NET-1) |
| RFC1918 reachability | None on `10/8`, `172.16/12`, `100.64/10`; nothing on `192.168.1.1:8006` |
| Outbound | HTTPS through a filtering proxy; GitHub and package registries only |

A host whose only routable address is in the documentation range is not attached
to a production network. Nothing in section C's design has been built or tested.

---

## D. Deployment architecture

```
GitHub  →  CI (validate only)  →  deployment controller  →  Management network  →  estate
```

GitHub Actions reaches nothing on any Lynomia network. It validates: inventory
schema, alert-rule consistency, Ansible syntax and lint, OpenTofu fmt and
validate, and a check that no workflow applies infrastructure. The last is
enforced by `infrastructure/scripts/check-ci-cannot-apply.py`, which parses the workflow
and inspects what each step actually runs rather than grepping the file — the
grep version mistook a syntax-check split across two lines for a real run. It
inspects 37 run steps across the one workflow this repository has.

The deployment controller is the only thing that crosses into Management, it
holds the credentials, and a person operates it.

**Who may deploy:** an operator with access to the controller.
**From where:** the controller, on the Management network.
**Which credentials:** the controller's environment; none are in Git.
**Which hosts:** only those present in an inventory with a permitting class.
**Which actions:** whatever the host's `safety_class` allows, and no more.

Not built. No controller exists.

### Separated verbs, and the gate before them

The existing tree already separates preflight from apply — `make infra-check`
runs `playbooks/preflight.yml` in `--check --diff`, `make infra-plan` runs
`tofu plan`, and every playbook is check-capable. That was not rebuilt.

What was added is the question that comes before any of them. `safety_gate` is
now the first role in all eleven playbooks, and it refuses a host whose
`safety_class` does not permit what the play is about to do:

```
DISCOVERY_ONLY          read facts, change nothing
CONFIGURATION_ALLOWED   change configuration, never partitions or firmware
REIMAGE_ALLOWED         may be wiped, and only with allow_reimage on that host
DO_NOT_TOUCH            do not connect to it for any write   <- the default
```

Every group in all three inventories now carries one. `dedicated` is
`DISCOVERY_ONLY`, because those are customers' own machines and the platform
only ever reads their BMC. The rest are `CONFIGURATION_ALLOWED`, with the
destructive halves still behind the per-operation flags the tree already had —
`safety_class` says whether we may touch the machine, `allow_reimage` says
whether this particular wipe is scheduled.

---

## E. Secrets handling

Git stores desired state, inventory metadata, roles, playbooks, OpenTofu
definitions, monitoring configuration and runbooks. Git stores no password, no
private key, no Proxmox secret, no BMC password, no Cloudflare token, no
registrar credential, no payment secret, no SMTP credential, no database
password and no WordPress administrator credential.

Secrets reach a run from the deployment controller's environment. The
`env.j2` template uses `| mandatory` on each one, so a missing secret fails the
render rather than writing an empty value the application would treat as
configured.

No secrets manager was built, per the phase's instruction.

Four mechanical defences:

1. `validate-inventory.py` fails the build for a host variable whose *name*
   reads like a credential (`password`, `token`, `secret`, `api_key`,
   `private_key`, `auth_code`) or whose *value* looks like a key.
2. `validate-monitoring.py` fails on an inline credential in any monitoring
   config; receivers use `*_file` fields or environment placeholders.
3. CI fails on any `.pem`, `.key`, `.p12`, `.pfx`, `.tfstate` or `id_rsa`
   tracked under `infrastructure/`.
4. `.gitignore` refuses OpenTofu state, which contains every value a provider
   returned.

The one field that mentions credentials is `credentials_available`, a boolean
that records whether one is held. The validator rejects it if it is not a
boolean, which is what stops somebody writing the credential into the field
meant to avoid exactly that.

Logs: `infrastructure/monitoring/alloy/config.alloy` drops any line matching a credential
shape at the collector, and redacts registrant, admin and contact email
addresses before shipping. Coarse on purpose — a dropped log line costs an
investigation some detail; a leaked one costs a customer their domain.

---

## F. Staging deployment

**Not done. `BLOCKED_HARDWARE`.**

No staging host exists. The playbooks are written, syntax-clean, and pass
`ansible-lint` at the production profile (0 failures, 0 warnings, 28 files), but
they have never run against a machine. `infrastructure/ansible/inventories/staging/` has
no hosts.

The deployment proof the phase asks for — Git → CI → deploy → migrations →
application health → worker health → scheduler health → browser — cannot be
produced. `playbooks/verify.yml` is written to be that proof: it asserts the
health endpoint reports database and cache up, no migration is pending, the
worker unit is active, the scheduler timer is active, and no fake provider is
enabled. It has run zero times.

---

## G. Monitoring

**Configuration written and validated. Not deployed.**

`infrastructure/monitoring/` was already substantial: Prometheus, Alertmanager,
Grafana with five generated dashboards, Loki, Alloy, and 55 alert rules plus 9
recording rules across six files, every image pinned to an exact tag, every port
bound to localhost.

What this phase added is a consistency check over it:

```
$ python3 infrastructure/scripts/validate-monitoring.py infrastructure
6 rule file(s), 64 rule(s), 41 exported by the control plane,
7 declared by a collector contract
  NOTE the textfile collector for infrastructure/pbs is declared and not
       implemented; alerts on its series cannot fire
```

It resolves every metric an alert names against one of the two places a
`lynomia_*` series legitimately comes from — the control plane's own endpoint,
read out of the PHP source, or a textfile-collector contract declared in
`monitoring/README.md` for a machine that has no Prometheus endpoint of its own.
It also requires every alert to carry a `runbook` path that exists or a
`runbook_url`, and skips recording rules, which page nobody.

37 of the 55 alerts now also carry a `runbook` — a path in this repository that
CI resolves — alongside the `runbook_url` they already had. The other eighteen
have no page here, and `docs/runbooks/README.md` lists which and why, so the gap
is a known one rather than a discovery made at 4am.

The NOTE is the finding. Six alerts in `backups.yml` read `lynomia_backup_*`
series that nothing writes, because the collector "belongs to
`infrastructure/pbs`" and that directory does not exist. Two of the six —
`BackupVerificationFailed` and `UnverifiedSnapshotsAccumulating` — exist
precisely to catch an unverified backup, which looks identical to a good one
right up until a restore.

It is not fixed here. The collector parses `proxmox-backup-manager` output, and
writing it against output nobody has seen is guesswork with a green tick on it —
the same reason no registrar adapter was written. What was done instead is make
the gap impossible to forget: the contract is marked `NOT IMPLEMENTED` with its
blocker, the check prints the gap on every run, and the check *fails* if that
paragraph is deleted while the directory is still missing.

No Prometheus has scraped anything and no alert has ever fired.

---

## H. PostgreSQL

**Not deployed.** The restore procedure is written
(`docs/runbooks/database-restore.md`) and includes the two things that make a
restore survivable: restoring into a *new* database and verifying it before
swapping, and renaming the broken database rather than dropping it. Never
executed against a real deployment.

## I. Redis

**Not deployed.** `docs/runbooks/redis-outage.md` covers it. The important
operational note is recorded there: Redis holds the locks that stop two workers
acting on one order, so a Redis outage stopping work is the *correct* failure.

---

## J. Proxmox

**Not connected. `BLOCKED_HARDWARE`, then `BLOCKED_CREDENTIALS`.**

Port 8006 answered on no probed address. No API token exists in the environment.
Not even the read-only first step of Phase 30B.6 was possible.

The intended sequence is recorded in `docs/phase-30b-first-node-verdict.md`:
`DISCOVERY_ONLY` first, a `PVEAuditor` token, read the cluster, and only then
raise privileges. Lynomia does not use the Proxmox root password for normal API
operations.

## K. Real VPS lifecycle
## L. Console
## M. Suspension
## N. VPS reinstall
## O. VPS termination

**All blocked on J.** Each is `RUNTIME_VERIFIED` against the fake provider,
including through a real `queue:work` process in a separate OS process against a
real Redis, and through a real browser. None has touched a hypervisor.

Each of these is a separate row in
`docs/real-infrastructure-verification-matrix.md` and will be verified
separately. Creating a VM does not verify destroying one.

---

## P. PBS
## Q. Backup / restore

**Blocked. `BLOCKED_HARDWARE`.** No datastore exists. `docs/runbooks/pbs-unavailable.md`
and `docs/runbooks/backup-failure.md` are written, including the rule that a
restore is always tested into a scratch VM and never over a customer's live one,
and that a backup inside a customer's retention window is never deleted to make
room.

---

## R. Cloudflare DNS
## S. Reverse DNS

**Blocked. `BLOCKED_NETWORK`.**

```
$ curl https://api.cloudflare.com/
curl: (56) CONNECT tunnel failed, response 403
```

The proxy refuses it. No account, no token, no test zone. Public resolution
cannot be proven, and proving it properly needs two independent resolvers
answering for a record Lynomia published — which is the point of the proof.

PTR additionally needs a delegated address block, which does not exist either.

---

## T. Registrar selection

**Not made.** `docs/phase-30b-registrar-selection.md` explains why at length. In
short: the phase requires evaluating *current official capabilities* and forbids
selecting from an old conversation, and this environment cannot reach any
registrar's documentation. A comparison table written from training data would
satisfy the form of the document and violate its purpose — registrar pricing,
TLD entitlements, sandbox coverage and API versions change quarterly, and a table
that looks researched is worse than an empty one, because somebody would act on
it.

What the document does contain is derived from evidence: the fifteen methods and
twelve capabilities the platform's own contract requires, and two criteria that
are disqualifying rather than weighted — idempotency on registration, and a
sandbox that actually supports registration.

## U. Registrar integration

**No adapter written, deliberately.** Implementing fifteen methods against
endpoints recalled from training data would pass PHPStan, the layering test and
the dead-capability test, and CI would be green. It would also be a capability
that exists in code and cannot complete in the product — the exact defect class
the last two phases spent their time deleting — with a customer's money and a
name in a global namespace on the other side of it.

`FakeDomainRegistrarProvider` remains the only implementation.

## V. Real/sandbox domain lifecycle

**Blocked on T and U.** No sandbox account, no selection.

## W. `.SY` status

**`BLOCKED_LICENSE`, unchanged.** No published API, no accreditation, no
documents received during this phase. `SyRegistryProvider` declares no
capabilities it cannot perform, and no endpoint has been invented — as has been
true since Phase 30A++ and remains the correct answer.

---

## X. Payment provider

**Blocked. `BLOCKED_CREDENTIALS` and `BLOCKED_NETWORK`.** No TEST account, and
`api.stripe.com` is unreachable. A webhook additionally needs a publicly
reachable endpoint, which this environment does not have — so even with
credentials, the half of the payment flow that matters most could not be proven.

## Y. SMTP

**Blocked. `BLOCKED_CREDENTIALS`.** No relay, no credentials, no sending domain.
SPF, DKIM and DMARC cannot be established without one.

---

## Z. Hosting panel

**Blocked. `BLOCKED_LICENCE`, then `BLOCKED_HARDWARE`.** No cPanel or DirectAdmin
licence, no node.

## AA. WordPress toolkit

**No real `WordPressInstaller` written.** The interface exists as a separate
optional contract — deliberately not more methods on `HostingProvider`, so a
panel that cannot install WordPress does not have to pretend it can. Writing the
implementation requires a licensed panel to write it against; the alternative is
guessing at a panel API, which is the same mistake as U.

The site probe is real code: `HttpSiteProbe` resolves the hostname before
connecting and refuses twelve private and reserved ranges, proven by nine tests.
It has probed no real site.

## AB. Real WordPress lifecycle

**Blocked on Z and AA.**

---

## AC. Dedicated / BMC / PXE

**Blocked. `BLOCKED_HARDWARE` and `BLOCKED_NETWORK`.**

No BMC is reachable. `infrastructure/pxe/` contains no profile, and that is a decision
rather than an omission: PXE means a DHCP server answering boot requests, and on
a shared or unknown network it answers *other people's* machines. Three things
must be true and recorded before a profile lands there — an isolated install
VLAN, every machine that can hear it inventoried and classified, and the target
`REIMAGE_ALLOWED` with `allow_reimage: true`. None is established.

There is no generic reimage playbook, and the one written earlier in this phase
was deleted with the duplicate tree — correctly, because the guards it carried
already existed here in a stronger form.

The `opnsense` role, which rewrites the edge firewall's ruleset, refuses three
times before it acts: unless the host declares `lynomia_role: firewall`, unless
that individual host carries `allow_reimage: true`, and unless
`opnsense_console_access_confirmed` is true — because the difference between a
five-minute mistake and a four-hour outage is whether somebody already had a
console open when the management path went away. The `pxe` role will not serve
DHCP without `pxe_allow_serve_dhcp`. None of those flags is set anywhere in any
inventory, which is the intended steady state: a destructive flag is added for
one scheduled piece of work against one named host and removed afterwards.

What Phase 30B added in front of all of them is `safety_gate`, which asks first
whether the machine may be written to at all.

---

## AD. Failure testing

**Not performed against real providers.** Failure behaviour is proven against
the fake, including the cases that matter most: an indeterminate registration
that registers the name and then times out, a provider that becomes unreachable
during availability search, and a purchase driven through a real queue worker in
a separate OS process that is killed mid-job.

Real chaos testing — pulling a node, blocking a registrar, filling a datastore —
requires the things to pull.

## AE. Disaster recovery

**Not performed.** `database-restore.md` is written and untested against real
data. RPO and RTO cannot be stated: both are measurements, and nothing has been
measured. Stating a target as though it were a result is the failure this whole
phase exists to prevent.

## AF. Performance

**Not measured on real topology.** Every performance figure the repository holds
was measured against a local development database, and is recorded as such in
`docs/performance-report.md`. Real topology adds network latency between the
control plane, the database and the hypervisor, which is the variable that
matters and the one that cannot be simulated here.

## AG. Security

**The deployment security review was done; the deployment was not.**

Reviewed and enforced:

| Control | State |
| --- | --- |
| No credential in Git | Enforced by three CI checks and `.gitignore` |
| No credential in logs | Enforced at the Alloy collector |
| CI cannot apply infrastructure | Enforced by `check-ci-cannot-apply.py` |
| Default `DO_NOT_TOUCH` | Enforced by `safety_gate` and the inventory validator |
| No wildcard destructive operations | Enforced per role: `opnsense` demands a firewall role, `allow_reimage` on that host, and a confirmed open console; `pxe` will not serve DHCP without its own flag. No flag is set in any inventory |
| Metrics carry no customer identifiers | Enforced by an existing architecture test |
| Metrics endpoint behind a bearer token | Configured |
| Management network unreachable from the internet | Designed, not built |

**Not done:** the port scan from each network segment showing exactly the flows
in `docs/phase-30b-network-flow-matrix.md` open and no others. That requires the
segments to exist. It is the single most important unperformed check in this
phase, because the whole security posture rests on the Management network being
unreachable, and that is currently an assertion rather than an observation.

## AH. Infrastructure drift

**Not measurable.** Drift is the difference between declared state and observed
state, and there is no observed state. The mechanism — `plan.sh` running Ansible
in `--check --diff` and OpenTofu in `plan` — is in place and writes nothing.

---

## AH2. Observed CI

Every run on this branch during Phase 30B, including the red one.

| Run | Commit | Result |
| --- | --- | --- |
| 84 | `f755083` | Green — the Phase 30A++ baseline, re-measured here |
| 85 | `dd1cd59` | **Red**, then cancelled. Security checks failed: the committed-secret gate matched this phase's own test fixture, the case proving the inventory validator rejects an inlined private key. Fixed by assembling the header from fragments rather than adding a third exclusion to the gate — an exclusion would mean a real key pasted into that file went uncaught. The Infrastructure validation job passed all thirteen of its steps in the same run |
| 86 | `c2f7007` | **Green**, all jobs |
| 87 | `5cd3893` | Cancelled — the consolidation |
| 88 | `7e2214f` | Cancelled |
| 89 | `b730ae1` | Cancelled |
| 91 | `faa7539` | Cancelled |

Four consecutive runs were cancelled, and that is worth stating plainly rather
than leaving as a gap in the numbering. The workflow's concurrency group cancels
an in-progress run when a new commit lands on the same ref, and pushes went out
faster than CI could finish. So the last run to complete on its own merits was
86 — which predates the consolidation entirely, and therefore tested the
duplicate tree rather than this one.

The fix was to stop pushing and wait, which is the discipline the concurrency
setting assumes and which this phase did not show until it had wasted four runs.

Measured locally on the consolidated tree in the meantime: 2,446 backend tests
green with 69,585 assertions; `ansible-lint` clean at the production profile
across 126 files; both OpenTofu environments valid; and all the static checks
passing, including the two that test the gates themselves — 15/15 for the
inventory validator and 10/10 for the safety gate.

A report that lists only green runs is not a record of what happened.

---

## AI. `REAL_INFRA_VERIFIED` matrix

`docs/real-infrastructure-verification-matrix.md`. **No row is
`REAL_INFRA_VERIFIED`.** Three rows are verified as mechanisms in CI — alert-rule
consistency, inventory safety classification, and CI's inability to apply
infrastructure — and the matrix says explicitly that none of those is movement
toward provider verification.

`docs/customer-capability-matrix.md` is unchanged. Its "Real provider = No"
column stays as it is, because changing a cell there requires evidence, and this
phase produced none of that kind.

## AJ. Remaining blockers

| Blocker | What it stops | Removed by |
| --- | --- | --- |
| `BLOCKED_NETWORK` | Cloudflare, registrar, payment, SMTP, registrar documentation | An environment that can reach third-party APIs |
| `BLOCKED_CREDENTIALS` | Proxmox, PBS, Cloudflare, registrar, payment, SMTP | Credentials issued by whoever owns those accounts |
| `BLOCKED_HARDWARE` | Staging, monitoring, Proxmox, PBS, hosting nodes, dedicated, BMC, PXE | Machines made reachable and classified by their owner |
| `BLOCKED_LICENCE` | cPanel/DirectAdmin, WordPress toolkit | A purchased licence |
| `BLOCKED_LICENSE` | `.sy` | Registry accreditation and published documentation |

Section H of `docs/phase-30b-real-infrastructure-inventory.md` lists the nine
concrete things needed, in the order the phase would consume them.

## AK. Expansion decision

**Do not expand.** `docs/phase-30b-first-node-verdict.md` records ten
requirements for the first-node gate and ten failures. The phase's own rule —
do not scale to the rest of the hardware until the first node passes — is not
close to being satisfied, because there is no first node.

## AL. Go / No-Go verdict

**NO GO.**

Not for production. Not for a second machine. Not for marking any capability
`REAL_INFRA_VERIFIED`.

The software is where Phase 30A++ left it: 2,446 tests green, PHPStan clean, and
every customer path proven end to end against fake providers, through a real
queue worker and a real browser. That was never the question Phase 30B asked.
Phase 30B asked whether any of it works against something real, and the honest
answer is that nothing real was reachable to ask.

---

## Final questions

The phase requires these answered with evidence, and the exact blocker stated
for every NO.

> **Can a completely paid order create a real VPS without a human creating
> anything in Proxmox?**

**NO.** The chain is built and proven against the fake provider end to end:
payment → listener → job → provider → active service, through a real worker
process. It has never reached a hypervisor.
*Blocker: `BLOCKED_HARDWARE` — no Proxmox node exists; port 8006 answered on no
probed address. Then `BLOCKED_CREDENTIALS` — no API token.*

> **Can Lynomia prove the VM it believes exists actually exists with the expected
> resources?**

**NO.** `inspect()` and the reconciliation path exist and are proven against the
fake. No real VM has been inspected.
*Blocker: `BLOCKED_HARDWARE`.*

> **Can a worker or provider timeout occur without creating duplicate destructive
> work?**

**Proven against the fake; not against a real provider.** This is the platform's
strongest guarantee and its most tested: the Timeout Rule means an indeterminate
destructive or money operation is never auto-retried, jobs that touch money run
with `$tries = 1`, state transitions happen under a row lock inside a
transaction, dispatch happens after commit, and an operation may only start from
`Requested` or `Queued` so a redelivered message cannot begin a second purchase.
`ADomainPurchaseSurvivesARealWorkerTest` proves it against a `queue:work` process
in a separate OS process. There is no Force Success anywhere in the platform.
*Blocker for the real half: `BLOCKED_HARDWARE` and `BLOCKED_CREDENTIALS` — the
guarantee has not been tested against a provider that times out for real
reasons.*

> **Can the customer operate, resize, reinstall, suspend, restore and terminate a
> disposable real VPS safely?**

**NO.** All six are `RUNTIME_VERIFIED` against the fake, each with its own
lifecycle test and browser spec. None has touched hardware.
*Blocker: `BLOCKED_HARDWARE`.*

> **Can Lynomia create a real backup and restore it successfully?**

**NO.**
*Blocker: `BLOCKED_HARDWARE` — no PBS datastore.*

> **Can Lynomia publish DNS and prove independent public resolution?**

**NO.** The DNS module and Cloudflare provider are complete and proven against
the fake.
*Blocker: `BLOCKED_NETWORK` — `api.cloudflare.com` returns `CONNECT tunnel
failed, response 403` from this environment. Then `BLOCKED_CREDENTIALS` — no
account, token or test zone.*

> **Can a domain registration through the selected registrar complete without
> bypassing Lynomia billing?**

**NO, and no registrar is selected.** The billing order is right in the software
and proven: no registrar call happens until payment settles, the listener moves
the operation to `Queued` under a lock inside a transaction and dispatches after
commit, and a quote is redeemed rather than trusted, so a tampered price cannot
reach the registrar.
*Blocker: no registrar selected — `BLOCKED_NETWORK` prevents evaluating current
official capabilities, and the phase forbids selecting from memory. Then
`BLOCKED_CREDENTIALS` for a sandbox account.*

> **Can a real WordPress hosting order reach HTTPS READY without somebody
> manually installing WordPress?**

**NO.** The full chain exists and is proven against fakes — order → hosting
account → DNS → SSL → install → probe → `ready` — and a site is only called live
after `HttpSiteProbe` has actually seen it answer over HTTPS. No real installer
exists.
*Blocker: `BLOCKED_LICENCE` — no cPanel or DirectAdmin licence to write a real
`WordPressInstaller` against, and no node to run it on.*

> **Can the platform recover from provider disagreement without Force Success?**

**Yes in the software; unproven against a real provider.** Reconciliation
compares Lynomia's belief with the provider's answer, records drift, and resolves
it under an operator's eye with a dry run first. There is no Force Success in the
codebase, and settling an indeterminate operation requires a person to state the
outcome after checking the provider.
*Blocker for real proof: `BLOCKED_HARDWARE` — no provider has yet disagreed.*

> **Can operators see every indeterminate or needs-review operation?**

**Yes in the software; unproven in a real deployment.** The operator surface
lists indeterminate operations, `lynomia_provider_task_total{state="indeterminate"}`
is exported, and the `ProviderTasksIndeterminate` alert fires on any that persist
for 30 minutes, pointing at `docs/runbooks/provider-indeterminate.md`. The
alert's metric is confirmed to exist by `validate-monitoring.py`.
*Blocker: no Prometheus has scraped a real control plane, so the alert has never
fired. `BLOCKED_HARDWARE`.*

> **Can the platform itself be restored from backup?**

**NO.** The procedure is written and untested.
*Blocker: `BLOCKED_HARDWARE` — no deployment exists to back up or restore, and a
restore procedure that has never been executed is a document, not a capability.*

---

## What this phase should be measured by

Ten of eleven questions answer NO, and the eleventh answers "in software only".
That is the honest result, and it is a result about the environment rather than
about the platform.

The temptation in a phase like this is to produce evidence-shaped documents:
capability tables from memory, adapters against remembered endpoints, RPO
figures that are really targets, a matrix with a few rows shaded green because
the code looks right. Every one of those would have passed review and none would
have been true, and the whole point of `REAL_INFRA_VERIFIED` as a status is that
it cannot be reached by writing.

What was delivered is what could be delivered honestly: an infrastructure source
of truth in Git, safety classification that a machine enforces rather than a
person remembers, four separated verbs with two refusals demonstrated, a
monitoring configuration whose alerts are checked against the metrics that exist,
a network design with its refusals written down, nineteen new runbooks and ten
corrections to the three that existed, and a CI job
that validates all of it and is mechanically prevented from applying any of it.

Phase 30B resumes the moment one machine and one credential exist.
`docs/phase-30b-first-node-verdict.md` says exactly what to do with them, in
order, starting read-only.
