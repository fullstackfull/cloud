# Customer capability matrix

Every action a customer can take on Lynomia Cloud, and whether it can actually
finish.

The question this document answers is not "does the endpoint exist" or "is
there a test". Both were true of a great deal of this platform while the work
behind it could not complete. It answers: **if a customer does this, does the
thing they wanted actually happen?**

Status vocabulary, used consistently across the Phase 29 documents:

| Status | Meaning |
| --- | --- |
| `RUNTIME_VERIFIED` | Proven end to end against a running system — a real queue worker, a browser, or both. |
| `TESTED` | Proven against the adapter and a fake provider. Correct by construction; the real provider has never answered. |
| `CODE_COMPLETE` | Written and reachable, with tests below the level that would prove the whole path. |
| `NOT_IMPLEMENTED` | The customer cannot do this. Stated here rather than discovered. |
| `BLOCKED_*` | Implemented and unprovable without credentials, hardware, a licence or a network. |

Nothing in this file is marked from reading code. Every `RUNTIME_VERIFIED` row
names the test that proves it.

---

## Account and access

| Capability | Portal | API | Status | Proven by |
| --- | --- | --- | --- | --- |
| Register, verify email, sign in, sign out | `/register`, `/sign-in` | `POST /register`, `POST /login` | `RUNTIME_VERIFIED` | `e2e/auth.e2e.ts` — the real form, the real CSRF cookie, the real session |
| Reset a forgotten password | `/forgot-password` | `POST /password/forgot` | `TESTED` | `PasswordResetTest` |
| Change password, update profile | `/profile` | `PUT /me`, `PUT /me/password` | `RUNTIME_VERIFIED` | `e2e/account.e2e.ts` |
| Enable and disable two-factor | `/security` | `POST /me/two-factor` | `RUNTIME_VERIFIED` | `e2e/account.e2e.ts` — reports its real state, not a hardcoded one |
| See and revoke sessions and devices | `/security` | `GET /me/sessions` | `RUNTIME_VERIFIED` | `e2e/account.e2e.ts` |
| Read sign-in history | `/security` | `GET /me/login-activity` | `RUNTIME_VERIFIED` | `e2e/account.e2e.ts` |
| Issue and revoke API tokens | `/api-tokens` | `POST /me/api-tokens` | `RUNTIME_VERIFIED` | `e2e/account.e2e.ts` — shown exactly once |
| Invite and manage team members | — | — | `NOT_IMPLEMENTED` | A customer with two people cannot give the second one access. Memberships exist and are seeded; nothing creates one. |

## Buying

| Capability | Portal | API | Status | Proven by |
| --- | --- | --- | --- | --- |
| Browse the catalogue and see prices in their currency | `/catalogue` | `GET /catalog/products` | `RUNTIME_VERIFIED` | `e2e/portal.e2e.ts`, `CataloguePricingScopeTest` |
| Place an order | — | `POST /orders` | `TESTED` | `PlaceOrderTest`, `OrderToProvisionedServiceTest` |
| Pay an invoice | `/invoices` | `POST /invoices/{invoice}/payments` | `TESTED` | `StartInvoicePaymentTest`; the provider is a fake |
| Have a paid order become a running service | — | — | `RUNTIME_VERIFIED` | `OrderToProvisionedServiceTest` and `ARealWorkerConsumesTheQueueTest` — a real worker builds the machine |
| Have a zero-total order fulfil | — | — | `TESTED` | `OrderToProvisionedServiceTest`: a 100% coupon settles with no invoice and no transaction |
| See orders and invoices | `/orders`, `/invoices` | `GET /orders` | `RUNTIME_VERIFIED` | `e2e/portal.e2e.ts` |
| Use wallet credit | `/wallet` | `GET /wallet` | `RUNTIME_VERIFIED` | `e2e/portal.e2e.ts` |

## Subscriptions and renewal

| Capability | Portal | API | Status | Proven by |
| --- | --- | --- | --- | --- |
| See subscriptions and when they renew | `/subscriptions` | `GET /subscriptions` | `RUNTIME_VERIFIED` | `e2e/portal.e2e.ts` — a renewing subscription and a cancelled one, told apart |
| Cancel a subscription | `/subscriptions` | `POST /subscriptions/{subscription}/cancel` | `TESTED` | `CancelSubscriptionTest`, `SweepSubscriptionLifecycleTest` |
| Change plan mid-cycle | — | `POST /subscriptions/{subscription}/plan` | `CODE_COMPLETE` | `ChangeSubscriptionPlanTest` proves the proration. **No portal screen** — a customer must use the API. |
| Be renewed automatically | — | — | `TESTED` | `RenewDueSubscriptionsTest` + `subscriptions:renew` on the scheduler |
| Keep the service when a payment fails, during grace | — | — | `TESTED` | `PaymentAfterSuspensionTest` |
| Lose access when grace expires | — | — | `TESTED` | `PaymentAfterSuspensionTest` — the service really moves to suspended and the guard refuses power actions |
| **Get the service back by paying** | — | — | `TESTED` | `PaymentAfterSuspensionTest`. Until Phase 29 the customer paid and was terminated anyway. |

## Cloud VPS

| Capability | Portal | API | Status | Proven by |
| --- | --- | --- | --- | --- |
| See machines, specs, addresses, power state | `/vps` | `GET /vps` | `RUNTIME_VERIFIED` | `e2e/portal.e2e.ts` |
| Start, stop, shut down, reboot | `/vps` | `POST /vps/{vm}/power` | `TESTED` | `VpsPowerHandlerTest` — through the HTTP endpoint and the engine, against the container the application really boots. Until Phase 29 no handler was registered and every one of these died at the worker. |
| Reinstall | — | `POST /vps/{vm}/reinstall` | `NOT_IMPLEMENTED` | The job is created and refused at the worker: no handler exists for `reinstall`, deliberately. The path from a running server to a rebuilt one has not been designed. |
| Open a console | — | `GET /vps/{vm}/console` | `NOT_IMPLEMENTED` | A permit is issued and there is nothing to spend it on: the console gateway that would redeem it is a separate process that does not exist. |
| Resize | — | — | `NOT_IMPLEMENTED` | No code path creates a resize job. |

## Backups

| Capability | Portal | API | Status | Proven by |
| --- | --- | --- | --- | --- |
| See backups and their state | `/backups` | `GET /vps/{vm}/backups` | `RUNTIME_VERIFIED` | `e2e/portal.e2e.ts` — both the finished and the needs-review state |
| Take a backup | `/backups` | `POST /vps/{vm}/backups` | `TESTED` | `BackupEndpointTest`, `BackupLifecycleTest` |
| Restore a backup | `/backups` | `POST /vps/{vm}/backups/{backup}/restore` | `RUNTIME_VERIFIED` | `RestoreBackupTest` (9 cases) and three browser specs including Escape closing the dialog without restoring |
| Know a backup is restorable | `/backups` | — | `TESTED` | "Completed" and "restore tested" are separate columns. A backup nobody has restored from is not reported as verified. |
| Delete a backup | — | — | `NOT_IMPLEMENTED` | Deleting interacts with the datastore's own prune policy, which the platform does not own. |

## Dedicated servers

| Capability | Portal | API | Status | Proven by |
| --- | --- | --- | --- | --- |
| See servers and their hardware | `/dedicated` | `GET /dedicated` | `TESTED` | `DedicatedServerIndexEndpointTest` |
| Power on, off, cycle | — | `POST /dedicated/{server}/power` | `BLOCKED_HARDWARE` | `TESTED` against a fake BMC. No real controller has answered. |
| Reinstall | — | `POST /dedicated/{server}/reinstall` | `NOT_IMPLEMENTED` | As with VPS: the job is created and fails loudly at the worker, on purpose. |
| Have hardware facts stay current | — | — | `TESTED` | `dedicated:sync-inventory` hourly. Until Phase 29 the record was whatever was typed in when the chassis was racked. |

## Shared hosting

| Capability | Portal | API | Status | Proven by |
| --- | --- | --- | --- | --- |
| See accounts and usage | `/hosting` | `GET /hosting` | `TESTED` | `ListHostingAccountsEndpointTest` |
| Have usage figures be real | — | — | `TESTED` | `hosting:sync-usage` hourly. Until Phase 29 every account read zero for ever. |
| Open the control panel | — | `POST /hosting/{account}/sso` | `BLOCKED_LICENSE` | `TESTED` against a fake panel. cPanel and DirectAdmin are commercial products and no licence is held. |
| Be suspended for non-payment, and restored on payment | — | — | `TESTED` | `PaymentAfterSuspensionTest` — the panel is told in both directions |

## Networking

| Capability | Portal | API | Status | Proven by |
| --- | --- | --- | --- | --- |
| See assigned addresses | `/ips` | `GET /ips` | `TESTED` | `IpAssignmentEndpointTest` |
| Set reverse DNS | — | `PUT /ips/{assignment}/rdns` | `BLOCKED_CREDENTIALS` | `TESTED` against a fake Cloudflare. No token is held. |
| Have a released address come back into circulation | — | — | `TESTED` | `ipam:reclaim` every ten minutes. Until Phase 29 every path out of a pool was one-way. |

---

## What a customer cannot do

Stated plainly, because a matrix of mostly-green rows is easy to skim past.

1. **Invite a colleague.** There is no team management. A customer is one login.
2. **Reinstall a server**, virtual or physical. Both endpoints accept the
   request and both jobs fail at the worker, deliberately and loudly.
3. **Open a console.** The permit is issued; nothing redeems it.
4. **Change plan from the portal.** The API can; there is no screen.
5. **Delete a backup**, or **resize a machine**.

Every one of these is a decision, and every one is visible in this table rather
than discovered by a customer.
