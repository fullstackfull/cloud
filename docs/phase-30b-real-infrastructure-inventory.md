# Phase 30B — real infrastructure inventory

This document records what was **observed**, on 2026-09-09, from the environment
this phase runs in. It is not a plan, a wish list, or a description of the
hardware Lynomia intends to own. Where a system was not reachable, that is
recorded as the fact it is.

No secret appears anywhere in this document, and none may be added to it.

---

## A. The environment doing the observing

| Fact | Value |
| --- | --- |
| Repository | `fullstackfull/cloud`, branch `claude/hv-t6hq1p` |
| Commit at the start of Phase 30B | `f755083` |
| Host | An ephemeral Linux container (`vm`), kernel 6.18.44 |
| Addresses held | `127.0.0.0/8` loopback, and one address in `192.0.2.0/24` |
| Outbound network | HTTPS only, through a filtering proxy on `127.0.0.1:37189` |
| Direct network to RFC1918 | None. The proxy's `noProxy` list names `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` and `100.64.0.0/10`, so traffic to those ranges is not proxied — and this host has no interface or route into any of them |

`192.0.2.0/24` is TEST-NET-1, the range reserved for documentation. A host whose
only routable address is in TEST-NET-1 is not attached to anyone's production
network, and that single fact settles most of what follows.

---

## B. What was probed, and what answered

Every check below was a connection attempt from this container. Nothing was
authenticated against, and nothing was changed.

### Management ranges

| Target | Result |
| --- | --- |
| `10.0.0.1:22` | unreachable |
| `172.16.0.1:22` | unreachable |
| `192.168.1.1:8006` (Proxmox API port) | unreachable |
| `100.64.0.1:22` | unreachable |
| `192.168.1.1:443` | **answered** — see section C |

### Provider APIs

| Endpoint | Result |
| --- | --- |
| `api.github.com` | HTTP 200 |
| `api.cloudflare.com` | `CONNECT tunnel failed, response 403` — refused by the proxy |
| `api.stripe.com` | no connection |
| `proxmox.com` | no connection |

The proxy permits GitHub and the language package registries. It permits no
provider API this platform integrates with. This is a property of the
environment, not a misconfiguration to work around.

### Credentials present in the environment

None. The environment holds no variable matching `PROXMOX*`, `CLOUDFLARE*`,
`PBS*`, `BMC*`, `REDFISH*`, `REGISTRAR*`, `SMTP*`, `CPANEL*`, `DIRECTADMIN*`,
`STRIPE*` or any equivalent. `apps/control-plane/.env.example` names every
variable each provider needs; not one of them has a value available here.

---

## C. The one machine that answered

| Field | Observed value |
| --- | --- |
| Address | `192.168.1.1` |
| Port | 443/tcp, open on three consecutive attempts |
| TLS | TLSv1.3, certificate subject `CN=default.domain` |
| Subject alternative names | Do not include `192.168.1.1` |
| Identity | **Unknown** |
| Relationship to Lynomia | **None established** |
| Safety classification | **`DO_NOT_TOUCH`** |

This is almost certainly the sandbox host's own gateway appliance. It is not
Lynomia hardware, nobody has said it is available, and its owner is unknown.

Probing stopped at the point above. No port sweep was run against it, no
authentication was attempted, and no further information was gathered. The rule
this phase operates under is that classification comes from a person who owns
the hardware — never from inference — and that an unused-looking machine is not
a spare one.

---

## D. Server safety classification

| Machine | Class | Why |
| --- | --- | --- |
| `192.168.1.1` (unidentified) | `DO_NOT_TOUCH` | Unknown owner, no authorization, no relationship to this platform |
| Every other physical machine | `DO_NOT_TOUCH` | The default. None has been presented, named or authorised |

There are no machines classified `DISCOVERY_ONLY`, `CONFIGURATION_ALLOWED` or
`REIMAGE_ALLOWED`. That is not an oversight — those classifications are
authorizations, and no authorization has been given.

The Ansible inventories at `infrastructure/ansible/inventories/staging/` and
`.../production/` therefore contain **no hosts**. Adding a host is the act that
makes it a target, and it is done by whoever owns the machine.

---

## E. Inventory schema

When machines do become available, each is recorded with the fields below.
`infrastructure/scripts/validate-inventory.py` fails CI for any host that omits one, and
for any variable whose name or value reads like a credential.

```yaml
some-host:
  ansible_host: <management address>
  safety_class: DO_NOT_TOUCH        # the default, changed only deliberately
  allow_reimage: false              # true only on a REIMAGE_ALLOWED host
  credentials_available: false      # whether a credential is held — never the credential
  purpose: "what this machine is for"
  owner: "who authorises changes to it"
```

The full field list Phase 30B asks for — purpose, hostname, management address,
provider/type, hardware, OS, firmware, network interfaces, storage, RAID/HBA,
management method, current workloads, safe-to-change, safe-to-reimage,
credentials available — is gathered by `infrastructure/ansible/playbooks/discover.yml`,
which writes facts to the controller and never to the target. It has not been
run, because it has nothing to run against.

---

## F. What this means for Phase 30B

Phase 30B's purpose is to move capabilities from `TESTED` to
`REAL_INFRA_VERIFIED`, and its standard is that no capability is marked verified
without evidence from a real provider or real hardware.

From this environment, that evidence cannot be produced for any capability. Not
because the software is not ready — the baseline below says otherwise — but
because there is nothing real to reach.

| Blocker | Applies to |
| --- | --- |
| `BLOCKED_NETWORK` | Every provider API: the proxy refuses all of them |
| `BLOCKED_CREDENTIALS` | Every provider: no credential exists in this environment |
| `BLOCKED_HARDWARE` | Proxmox, PBS, hosting nodes, dedicated servers, BMC, PXE |
| `BLOCKED_LICENCE` | cPanel/DirectAdmin, and `.sy` as before |

`docs/real-infrastructure-verification-matrix.md` records this capability by
capability, with the exact blocker for each.

What *was* produced in this phase is everything that does not require reaching a
machine: the infrastructure source of truth, the safety gates that enforce
classification mechanically, the monitoring configuration and its consistency
check, the network flow matrix, the registrar selection decision, the runbooks,
and the CI that validates all of it. Those went into the existing
`infrastructure/` tree and `docs/runbooks/`.

---

## G. Software baseline, measured on this commit

Re-measured at `f755083`, not carried forward from Phase 30A++:

| Measure | Value |
| --- | --- |
| Backend tests | 2,446 |
| Assertions | 69,585 |
| PHPStan errors | 0 |
| Migrations | 45 |
| OpenAPI operations | 150 |
| Metric families exported | 41 |
| Browser E2E specs | 105, across 15 files |

```
$ php artisan test
{"tool":"phpunit","result":"passed","tests":2446,"passed":2446,
 "assertions":69585,"duration_ms":247609}
```

The figures match Phase 30A++'s closing measurement, which is the expected
result: Phase 30B has added infrastructure configuration and documents, and has
deliberately not added product code.

---

## H. What would unblock this

For the phase to produce real evidence, the environment running it needs to
reach real systems. Concretely, and in the order the phase itself would consume
them:

1. **A staging control-plane host** — one machine, reachable, with a hostname
   and TLS, classified `CONFIGURATION_ALLOWED` by its owner.
2. **One Proxmox node**, read-only first: an API URL reachable from the
   deployment controller, and an API token with `PVEAuditor` on the node.
   Lynomia must not use the Proxmox root password for normal operations.
3. **One PBS datastore** reachable from that node.
4. **A Cloudflare account and a test zone**, with a scoped API token.
5. **A registrar sandbox account** — see
   `docs/phase-30b-registrar-selection.md` for which, and why.
6. **A payment gateway TEST account** and its webhook secret.
7. **An SMTP relay** that will accept mail from the staging host.
8. **One shared-hosting node** with a valid cPanel or DirectAdmin licence.
9. **One dedicated server** with BMC access, on an isolated management network,
   explicitly authorised for reimage by its owner.

Each of those is a credential or a machine somebody has to provide. None can be
manufactured here, and this phase does not invent any of them — including the
`.sy` registry API, which remains undocumented and unimplemented.
