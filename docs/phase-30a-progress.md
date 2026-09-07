# Phase 30A — progress report

**Branch:** `claude/hv-t6hq1p` · **At commit:** `b044471` · **Status: two of the six
product gaps are closed. Four are not started, and this document says so
rather than leaving it to be discovered.**

Phase 30A asked for one thing: close the last customer and operator product
gaps before real infrastructure integration, under a rule that makes the
whole phase harder than it sounds — *do not make an unavailable feature appear
functional*, and **there must be no button that silently does nothing.**

This report uses the status vocabulary the Phase 29 documents established, so
the two sets of claims can be read against each other:

| Status | Meaning |
| --- | --- |
| `RUNTIME_VERIFIED` | Proven end to end against a running system — a real queue worker, a browser, or both. |
| `TESTED` | Proven against the adapter and a fake provider. Correct by construction; the real provider has never answered. |
| `CODE_COMPLETE` | Written and reachable, with tests below the level that would prove the whole path. |
| `NOT_IMPLEMENTED` | The customer cannot do this. Stated here rather than discovered. |
| `BLOCKED_*` | Implemented and unprovable without credentials, hardware, a licence or a network. |

---

## A. Where the six gaps stand

| # | Gap | Status | Evidence |
| --- | --- | --- | --- |
| 30A.5 | Notification system | `RUNTIME_VERIFIED` | Inbox, preferences, both locales; browser specs in `e2e/portal.e2e.ts` |
| 30A.6 | Real compute suspension | `TESTED` + `BLOCKED_CREDENTIALS` | 46 tests across five files; the Proxmox adapter has never met a real cluster |
| 30A.1 | VPS reinstall | `TESTED` + `BLOCKED_CREDENTIALS` | 13 handler tests, 3 adapter tests, 2 browser specs |
| 30A.2 | Dedicated reinstall | `NOT_IMPLEMENTED` | Documented gap in `HandlerCoverageTest::documentedGaps()` |
| 30A.3 | Console gateway | `NOT_IMPLEMENTED` | `RedeemConsoleSession` is still the sole entry in the dead-capability allowlist |
| 30A.4 | Change-plan customer UI | `NOT_IMPLEMENTED` | Backend `ChangeSubscriptionPlan` exists; no customer surface reaches it |

The supporting work — 30A.7 through 30A.17 (capability matrix, method-level
dead-capability audit, full business flow tests, operator surfaces, bounded
metrics, clean room, security review, final report) — has not been started.

---

## B. 30A.6 — suspension that a customer cannot undo

Commit `0e12d44`.

Phase 29 deliberately refused to implement suspension as "stop the VM", and
the reasoning was right: at the hypervisor a stop is indistinguishable from
the customer stopping their own machine, and nothing about a stopped VM
prevents it being started again. The platform's API guard is one forgotten
check away from nothing.

**What was built**

- `compute.suspension_policy` takes one of three named values and no others —
  `power_off_and_lock`, `power_off`, `record_only`. An unrecognised value is
  refused at boot by `ProviderRegistryServiceProvider`, because a typo that
  silently resolves to a policy is discovered in an incident.
- `ComputeProvider::suspendVm()` / `liftSuspension()`. The Proxmox adapter
  stops the guest, clears `onboot`, then sets the config lock **last** — the
  order matters, because a locked VM refuses every subsequent operation
  including the stop.
- Both actions return a verdict re-read from the provider rather than
  trusting that the call was accepted.
- Reactivation is asymmetric on purpose. Suspending and finding the provider
  did not comply leaves a customer using something unpaid, which is a bill;
  reactivating and finding the same leaves a customer who *has* paid unable to
  work, which is an outage. So the service moves to `reactivating`, reaches
  `active` only on provider confirmation, and otherwise returns to `suspended`
  and tells the customer.
- Reconciliation now records `suspension_mismatch` at critical severity in
  both directions. Without it, the platform's only evidence that a machine was
  suspended was that it once asked.

**What the tests prove**

| File | Proves |
| --- | --- |
| `tests/Feature/Compute/ComputeSuspensionTest.php` | A locked machine refuses every power operation; duplicate suspension is safe; a foreign lock is left alone; each policy's actual effect; a machine missing at the provider is drift, not success |
| `tests/Feature/Subscriptions/SubscriptionSuspensionLifecycleTest.php` | Non-payment, operator suspension, redelivery, payment-during-suspension, worker-crash recovery mid-reactivation, and a reactivation the provider will not confirm |
| `tests/Feature/Vps/NoCustomerPathBypassesSuspensionTest.php` | **Every** non-usable `ServiceStatus` is refused by power, reinstall and console, with nothing queued behind the refusal |
| `tests/Feature/Compute/DetectVirtualMachineDriftTest.php` | A suspended service whose machine is running, and an active service still carrying the platform's lock, are both critical drift |
| `tests/Feature/Security/ProductionGuardTest.php` | An unimplemented suspension policy refuses to boot |

The bypass sweep is written over the enum rather than as a test per status,
because the failure it guards against is not a missing check today — it is the
status somebody adds next year. `reactivating` was added in this change, and a
per-status suite would have gone on passing while a machine mid-reactivation
accepted reboots.

**Defects found while proving it**

1. The fake provider rebuilt its machine records field by field on every power
   change and resize, dropping the suspension lock. A fake that lets a
   suspended machine start proves a property the real hypervisor does not
   have. `RemoteVmState` now carries copy helpers and no site rebuilds by hand.
2. `LiftComputeSuspension` started the machine before confirming the lock was
   gone, so a machine locked by a backup surfaced as a provider exception on a
   reactivation that had only found a busy machine.
3. `TransitionService` answered "is this already the target status" from the
   caller's copy before taking the lock. That copy is stale by definition where
   it matters — a listener loads a service once and moves it twice — so the
   second move was silently dropped. **A service whose reactivation failed
   stayed in `reactivating` for ever, invisible to the retry path that looks
   for suspended services.** Regression-tested in
   `tests/Feature/Provisioning/TransitionServiceTest.php`.

---

## C. 30A.1 — the VPS reinstall the button already promised

Commit `b044471`.

`POST /vps/{vm}/reinstall` answered 202, wrote a job row, and queued work no
handler was registered for. The job died at the worker with
`HandlerNotRegisteredException`. The confirmation dialogue, the guards, the
idempotency key and the one-attempt policy were all there; nothing rebuilt
anything, and the portal had no button at all. This was exactly the failure
the phase brief names: a button that ends at 202 with no worker.

**The rebuild happens in place.** Nothing in the handler places a machine,
reserves capacity or allocates an address, because each of those produces a
second thing where there should be one: a placement moves the machine, a
capacity reservation double-counts it on its own node, an allocation hands the
customer a second address while the first stays assigned.

What survives is explicit and asserted:

| Preserved | Why it matters |
| --- | --- |
| Provider id | A clone-and-swap leaves the platform's row pointing at the old machine and the new one billed to nobody |
| Service mapping | No row is created and none repointed, so the subscription still names the machine |
| IP assignment | Untouched in IPAM, written back through cloud-init. Releasing and re-reserving would give the customer's old address to somebody else while their DNS still pointed at it |
| MAC | A consequence of never touching `net0`. Licences and firewall rules are keyed on it |
| Shape (vCPU, memory, disk) | A reinstall that resized would change what the customer uses without changing what they pay |
| Hostname | The confirmation they typed *was* the hostname |
| Backup relationship | A customer who rebuilds the wrong server needs yesterday's backup still attached to it |

**The operation has its own state machine** — `requested → queued → preparing →
reinstalling → configuring → verifying → completed`, with `failed`,
`needs_review` and `indeterminate` as the three separate unhappy endings. The
engine's four job statuses cannot answer the question an operator asks first,
which is whether the disk was already gone when it stopped. The phase names
can. `failed` is reachable from both sides of the destructive line, so the
`destroyed_at` stamp — not the state name — is the authority.

**An indeterminate provider call is never retried.** It classifies as
`FailureClass::Timeout`, which the engine escalates to review and refuses to
retry, and the operation records the provider task, the machine, the node and
the service so somebody can ask the hypervisor what happened rather than
asking the customer. A retried reinstall lands on a machine that may be
mid-rebuild.

**Refusals before the destructive call are refusals, not incidents.** A guest
that will not stop inside the window, an image not staged on the machine's own
cluster, a machine with no live address, a machine whose storage is unknown —
each ends as a plain `failed` with the customer's disk untouched.

**The customer surface.** The portal now has the button, the rebuild state per
machine, and the sentence the operation deserves, in English and Arabic:

> The server disk will be replaced. Existing data may be permanently lost.
>
> سيتم استبدال قرص الخادم. قد تُفقد البيانات الحالية نهائيًا.

The machine's hostname must be typed back before the confirm button enables —
and the backend compares it too, so an operator tool or a support script gets
the same refusal.

**Two things found while building it**

1. The machine's storage was chosen by the scheduler and then thrown away, so
   a reinstall had no way to put the new disk where the old one was other than
   guessing at a tier the customer did not buy. It is recorded now, and a
   machine whose storage is unknown refuses to be rebuilt rather than being
   rebuilt somewhere plausible.
2. `reinstall` was one job kind shared by virtual and physical machines. Since
   the engine keys handlers by kind, registering a handler for it would have
   routed dedicated reinstall jobs into the VPS handler. It is now
   `reinstall_vps` and `reinstall_dedicated`.

---

## D. What is not built, stated plainly

**30A.2 — dedicated reinstall.** `RequestDedicatedReinstall` creates
`reinstall_dedicated` jobs and no handler is registered, so a job of that kind
fails loudly at the worker. That is the correct failure while the handler is
missing, and it is recorded as a documented gap that
`HandlerCoverageTest` enforces. The work is a one-time BMC boot override, a
PXE handshake and an unattended installer; it will be classified
`BLOCKED_HARDWARE` when written, because no physical machine will be reimaged
to prove it.

**30A.3 — console gateway.** `RedeemConsoleSession` remains the only entry in
the `NoDeadCapabilitiesTest` allowlist: permits are issued and nothing can
redeem them. The gateway is a separately deployable service and has not been
started.

**30A.4 — change-plan customer UI.** `ChangeSubscriptionPlan` exists and works
at the backend; no customer surface reaches it.

**30A.7–30A.17.** The capability matrix update, the method-level
dead-capability audit, the full business flow tests, the operator surfaces,
the bounded-metrics review, the clean room, the CI observation and the
security review have not been started. `docs/customer-capability-matrix.md`
still reflects Phase 29 and does not yet describe suspension or reinstall.

---

## E. Gate results at `b044471`

| Gate | Result |
| --- | --- |
| `php artisan test` | **1978 passed**, 48 998 assertions, 0 failures |
| PHPStan level 6 (larastan, no baseline) | **0 errors** |
| Pint | clean |
| `npm run typecheck` (`tsc -b`, `exactOptionalPropertyTypes`) | clean |
| `npm run lint` | clean |
| `npm test` (Vitest) | 45 passed in 7 files |
| `npm run test:e2e` (Playwright, real API + PostgreSQL + Redis) | **45 passed** |
| `npm run openapi:lint` | valid, 1 pre-existing warning |

The suite grew by 70 tests across the two gaps. `openapi:lint` was **failing**
before this session: the notifications operation added in 30A.5 declared
`page` and `per_page` twice. That is fixed.

---

## F. What comes next, in order

1. **30A.2** — dedicated reinstall: BMC one-time boot, PXE/iPXE, unattended
   install, network and SSH verification, with `HARDWARE_UNAVAILABLE` and
   `PROVISIONING_TIMEOUT` as first-class states. No DHCP will be enabled on
   any unspecified network, and nothing will be called `REAL_INFRA_VERIFIED`.
2. **30A.3** — console gateway: single-use permits, ownership and resource
   binding, replay prevention, a safe WebSocket proxy, proven against a
   controlled upstream.
3. **30A.4** — change-plan UI: backend-authoritative proration, no money
   arithmetic in React, and a refused disk shrink.
4. **30A.7–30A.17** — the audits, the matrix, the operator surfaces, the
   security review, and `docs/phase-30a-final-product-closure.md`.

Nothing in this document is marked from reading code. Every claim above names
the test, the commit or the absence that supports it.
