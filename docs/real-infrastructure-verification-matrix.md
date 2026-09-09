# Real infrastructure verification matrix

One row per capability. A row reaches `REAL_INFRA_VERIFIED` only when that
specific action has been performed against a real provider or real hardware and
independently confirmed. Verifying one action never verifies its neighbours:
Proxmox authentication working says nothing about whether a VPS can be destroyed
cleanly.

**As of 2026-09-09, no row is `REAL_INFRA_VERIFIED`.** Every row is blocked, and
each blocker is stated exactly. `docs/phase-30b-real-infrastructure-inventory.md`
records the probes behind those blockers.

## Legend

| Status | Meaning |
| --- | --- |
| `REAL_INFRA_VERIFIED` | Performed against the real provider, independently confirmed, evidence recorded |
| `RUNTIME_VERIFIED` | Proven end to end against the fake provider, including through a real queue worker and a real browser |
| `TESTED` | Covered by tests, not proven through a whole customer path |
| `BLOCKED_CREDENTIALS` | The credential does not exist in the environment |
| `BLOCKED_NETWORK` | The endpoint cannot be reached from the environment |
| `BLOCKED_HARDWARE` | No machine exists to act on |
| `BLOCKED_LICENCE` | A commercial licence or registry authorisation is missing |

The three blockers stack. Where several apply, the row names the one that must
be removed **first**.

---

## Compute

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Create VPS | Proxmox | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` — no Proxmox node; port 8006 unreachable on every management range probed |
| Start VPS | Proxmox | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Stop VPS | Proxmox | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Resize VPS | Proxmox | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Reinstall VPS | Proxmox | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Suspend VPS | Proxmox | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Console | Proxmox | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Destroy VPS | Proxmox | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Authenticate to cluster | Proxmox | `TESTED` | Not attempted | — | Blocked | `BLOCKED_CREDENTIALS` — no `PROXMOX_TOKEN_ID` or `PROXMOX_TOKEN_SECRET` in the environment |

## Backup

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Create backup | PBS | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` — no PBS datastore |
| Restore backup | PBS | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Delete backup | PBS | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Retention sweep | PBS | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |

## DNS

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Create zone | Cloudflare | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_NETWORK` — `api.cloudflare.com` returns `CONNECT tunnel failed, response 403` |
| Manage records | Cloudflare | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_NETWORK` |
| Reconcile zone | Cloudflare | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_NETWORK` |
| Public resolution proof | — | n/a | Not attempted | — | Blocked | `BLOCKED_NETWORK` — needs a published record and two independent resolvers |
| Reverse DNS (PTR) | Cloudflare | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_NETWORK`, then delegation of a real address block |

## Registrar

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Search | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | No registrar selected — `docs/phase-30b-registrar-selection.md` §E |
| Availability | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Register | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Renew | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Transfer in | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Nameservers | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Contacts | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Transfer lock | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Auth code | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Redemption | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Reconciliation (`heldNames`) | Not selected | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| `.sy` registration | Syrian registry | Not implemented | Not attempted | — | Blocked | `BLOCKED_LICENSE` — no published API, no accreditation. Unchanged since Phase 30A++, and no endpoint has been invented |

## Payment

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Take payment | Stripe | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_CREDENTIALS` — no test account; `api.stripe.com` also unreachable |
| Receive webhook | Stripe | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_NETWORK` — a webhook needs a publicly reachable endpoint, and this environment has none |
| Refund | Stripe | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_CREDENTIALS` |

## Email

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Deliver a notification | SMTP relay | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_CREDENTIALS` — no relay, no credentials, port 587 outbound not available |
| SPF / DKIM / DMARC | — | Not applicable | Not attempted | — | Blocked | Needs a real sending domain |

## Shared hosting

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Create account | cPanel/DirectAdmin | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_LICENCE`, then `BLOCKED_HARDWARE` — no licensed node |
| Single sign-on | cPanel/DirectAdmin | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Change package | cPanel/DirectAdmin | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Suspend | cPanel/DirectAdmin | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Terminate | cPanel/DirectAdmin | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |
| Reconcile | cPanel/DirectAdmin | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | As above |

## WordPress

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Install | None implemented | `RUNTIME_VERIFIED` against the fake | Not attempted | — | Blocked | No real `WordPressInstaller` exists. Writing one needs a licensed panel to write it against — `BLOCKED_LICENCE` |
| Issue SSL | — | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | Needs a real domain resolving to a real node |
| Verify site is live | `HttpSiteProbe` | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | Needs a site to probe. The probe itself is real code with an SSRF guard proven by nine tests |

## Dedicated

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Inventory | Redfish/iLO/IPMI | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` — no BMC reachable |
| Power on / off / cycle | Redfish/iLO/IPMI | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |
| Reinstall | Redfish + PXE | `RUNTIME_VERIFIED` | Not attempted | — | Blocked | `BLOCKED_HARDWARE`, and `BLOCKED_NETWORK` — no isolated install VLAN, and PXE is never run on an unknown or shared network |
| Disk wipe at end of life | — | Not implemented | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |

## Platform

| Capability | Provider | Software state | Real provider result | Evidence | Status | Blocker |
| --- | --- | --- | --- | --- | --- | --- |
| Staging deployment | Ansible | Playbooks written, syntax and lint clean | Not attempted | `infra/ansible/`, CI job `infrastructure` | Blocked | `BLOCKED_HARDWARE` — no staging host |
| Monitoring stack | Prometheus/Loki/Alloy | Configuration written and validated | Not deployed | `infra/monitoring/`, `validate-monitoring.py` | Blocked | `BLOCKED_HARDWARE` — no monitoring host |
| Alert rules match exported metrics | — | **Verified in CI** | n/a | `validate-monitoring.py`: 17 rules, 41 metric families, 0 mismatches | Verified (configuration, not a live alert) | — |
| Inventory safety classification | — | **Verified in CI** | n/a | `validate-inventory.py` + `test_validate_inventory.py`, 11/11 | Verified (mechanism, not a real host) | — |
| CI cannot apply infrastructure | — | **Verified in CI** | n/a | `check-ci-cannot-apply.py`, 36 run steps inspected | Verified | — |
| Database backup and restore | PostgreSQL | Runbook written | Not attempted | `infra/runbooks/database-restore.md` | Blocked | `BLOCKED_HARDWARE` |
| Restore the platform itself | — | Runbook written | Not attempted | — | Blocked | `BLOCKED_HARDWARE` |

---

## What the two "verified" rows mean, and what they do not

Three rows above are verified, and all three are verifications of a *mechanism*
in CI, not of a real machine:

- The alert rules name metrics the control plane genuinely exports, and every
  runbook they reference exists. This means the alerts are wired to something
  real. It does not mean an alert has ever fired.
- The inventory validator rejects a host with no owner, no purpose, no
  classification, or an embedded credential — proven by its own test suite. This
  means the safety classification is enforced rather than documented. It does
  not mean a machine has been classified.
- No workflow in this repository applies infrastructure. This is verified by
  parsing the workflow rather than grepping it.

Nothing here should be read as movement toward `REAL_INFRA_VERIFIED`. That
column stays empty until a real provider answers.
