# Customer capability matrix

Every action a customer can take on Lynomia Cloud, traced from the screen they
press to the provider that has to do the work — and whether each link in that
chain exists.

The question this document answers is not "does the endpoint exist" or "is
there a test". Both were true of a great deal of this platform while the work
behind it could not complete: a reinstall endpoint whose job had no handler, a
console permit nothing could redeem, a plan change that moved money and left
the machine alone, a hosting order that queued a job naming no package. It
answers: **if a customer does this, does the thing they wanted actually
happen?**

## How to read the columns

| Column | What it records |
| --- | --- |
| **UI** | The portal route. `—` means the capability exists only over the API. |
| **API** | The endpoint, under `/api/v1` for customers and `/api/admin` for operators. |
| **Action** | The application action or use case the endpoint calls. |
| **Queue** | The job kind the work travels on, or `sync` when it completes in the request. |
| **Handler** | What executes the job. |
| **Provider** | The provider-contract method the work ends in, or `—` for work that never leaves the platform. |
| **E2E** | The browser spec that drives it, where one does. |
| **State** | The classification below. |
| **Real provider** | Whether a real vendor has ever answered this call. |

### Classification

| Status | Meaning |
| --- | --- |
| `REAL_INFRA_VERIFIED` | Proven against real hardware or a real vendor. **Nothing in this platform holds this classification.** |
| `RUNTIME_VERIFIED` | Proven end to end against a running system — a real Redis worker in its own process, a browser, or both. |
| `TESTED` | Proven against the adapter and a fake provider. Correct by construction; no real vendor has answered. |
| `CODE_COMPLETE` | Written and reachable, with tests below the level that would prove the whole path. |
| `BLOCKED_CREDENTIALS` / `BLOCKED_LICENSE` / `BLOCKED_HARDWARE` | Implemented and unprovable here: no token, no licence, no machine. |
| `NOT_IMPLEMENTED` | The customer cannot do this. Stated here rather than discovered. |

Nothing in this file is marked from reading code. Every row names what proves
it, and where nothing does, the row says so.

---

## Account and access

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Register and verify an email | `/register` | `POST /register`, `GET /email/verify/{id}/{hash}` | `RegisterCustomer` | `notifications` (the mail) | `DeliverNotification` | SMTP | `auth.e2e.ts` | `RUNTIME_VERIFIED` | No — mail is not sent from here |
| Sign in, including a second factor | `/sign-in` | `POST /login`, `POST /login/two-factor` | `AttemptLogin` | sync | — | — | `auth.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Sign out | any page | `POST /logout` | — | sync | — | — | `auth.e2e.ts`, `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Reset a forgotten password | `/forgot-password` | `POST /password/forgot`, `POST /password/reset` | Laravel's password broker | `notifications` | `DeliverNotification` | SMTP | — | `TESTED` | No |
| Change password, edit profile | `/profile` | `PUT /me/password`, `PATCH /me` | — | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Enable and disable two-factor | `/security` | `POST`/`DELETE /me/two-factor` | `ManageTwoFactor` | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| See and revoke sessions | `/security` | `GET`/`DELETE /me/sessions` | — | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Read sign-in history | `/security` | `GET /me/login-activity` | — | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Issue and revoke API tokens | `/api-tokens` | `POST`/`DELETE /me/api-tokens` | `IssueApiToken` | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Invite a colleague | — | — | — | — | — | — | — | `NOT_IMPLEMENTED` | A customer is one login. Memberships exist in the schema and nothing creates one. |

## Buying

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Browse the catalogue in their own currency | `/catalogue` | `GET /catalog/products` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Place an order | `/catalogue/:slug` | `POST /orders` | `PlaceOrder` | sync | — | — | — | `TESTED` | n/a |
| Cancel an unpaid order | `/orders` | `POST /orders/{order}/cancel` | `CancelOrder` | sync | — | — | — | `TESTED` | n/a |
| Pay an invoice | `/invoices` | `POST /invoices/{invoice}/payments` | `StartInvoicePayment` | sync | — | `createPaymentIntent` | — | `TESTED` | `BLOCKED_CREDENTIALS` — no gateway account |
| Have a paid order become a running service | — | — | `ProvisionOrderedService` | `create_vps` / `create_hosting_account` / `provision_dedicated` | `CreateVpsHandler`, `CreateHostingAccountHandler`, `ProvisionDedicatedHandler` | `createVirtualMachine`, `createAccount`, BMC install | — | `RUNTIME_VERIFIED` | No — `ARealWorkerConsumesTheQueueTest` builds it with a real worker against a fake hypervisor |
| Have a free order fulfil with no invoice | — | `POST /orders` | `PlaceOrder` | as above | as above | as above | — | `TESTED` | n/a |
| See orders, invoices and payments | `/orders`, `/invoices` | `GET /orders`, `/invoices`, `/payments` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| See wallet balance and its history | `/wallet` | `GET /wallet`, `/wallet/transactions` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Spend wallet credit | — | — | `WalletLedger::debit` | — | — | — | — | `NOT_IMPLEMENTED` | Credit can be received and cannot be spent. Listed in `NoDeadMethodsTest::RESERVED`. |

## Subscriptions, plan changes and cancellation

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See subscriptions and renewal dates | `/subscriptions` | `GET /subscriptions` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Cancel a subscription | `/subscriptions` | `POST /subscriptions/{id}/cancel` | `CancelCustomerSubscription` | sync | — | — | — | `TESTED` | n/a |
| Price every plan they could move to | `/subscriptions/:id/plan` | `GET /subscriptions/{id}/plan-options` | `QuotePlanChange` | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Change a VPS plan, and get the machine resized | `/subscriptions/:id/plan` | `POST /subscriptions/{id}/plan` | `ApplyPlanChange` | `resize` | `ResizeVpsHandler` | `resizeVm` | `portal.e2e.ts` | `RUNTIME_VERIFIED` | No — `TheWholeLifeOfAVpsTest` |
| Change a hosting plan, and get the quota applied | `/subscriptions/:id/plan` | `POST /subscriptions/{id}/plan` | `ApplyPlanChange` | `change_hosting_package` | `ChangeHostingPackageHandler` | `changePackage` | — | `TESTED` | `BLOCKED_LICENSE` — `TheWholeLifeOfAHostingAccountTest` proves it against a fake panel |
| Be refused a plan change that would shrink a disk | `/subscriptions/:id/plan` | `POST /subscriptions/{id}/plan` | `QuotePlanChange` | — | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Be renewed automatically | — | — | `RenewDueSubscriptions` | scheduler | — | — | — | `TESTED` | n/a |
| Keep the service through the grace period | — | — | `AdvanceDunning` | scheduler | — | — | — | `TESTED` | n/a |
| Lose the service when grace expires | — | — | `EnforceServiceStateForSubscription` | `provisioning` | listener | `suspendVm` / `suspendAccount` | `operations.e2e.ts` (the customer's view of it) | `RUNTIME_VERIFIED` | No — `AWorkerFinishesWhatTheCustomerStartedTest` |
| Get it back by paying | — | — | `EnforceServiceStateForSubscription` | `provisioning` | listener | `liftSuspension` / `unsuspendAccount` | — | `RUNTIME_VERIFIED` | No — the service reaches `active` only after the provider confirms |

## Cloud VPS

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See machines, specs, addresses, power and service state | `/vps` | `GET /vps`, `GET /vps/{vm}` | — | sync | — | — | `portal.e2e.ts`, `operations.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Start, stop, shut down, reboot | `/vps` | `POST /vps/{vm}/power` | `RequestVpsPowerChange` | `start`/`stop`/`restart` | `StartVpsHandler`, `StopVpsHandler`, `RestartVpsHandler` | `startVm`, `stopVm`, `shutdownVm`, `rebootVm` | `portal.e2e.ts` | `RUNTIME_VERIFIED` | No — `TheWholeLifeOfAVpsTest` drives the endpoint and reads the hypervisor back |
| Rebuild a machine, keeping its identity | `/vps` | `POST /vps/{vm}/reinstall` | `RequestVpsReinstall` | `reinstall_vps` | `ReinstallVpsHandler` | `reinstallVm` | `portal.e2e.ts`, `appearance.e2e.ts` (Arabic) | `RUNTIME_VERIFIED` | No — `AWorkerFinishesWhatTheCustomerStartedTest` runs it through a real worker |
| Be refused a rebuild while one is running, or the service is suspended | `/vps` | `POST /vps/{vm}/reinstall` | `VpsOperationGuard` | — | — | — | `operations.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Open a console | `/vps/:id/console` | `GET /vps/{vm}/console` | `IssueConsoleSession` → `AuthoriseConsoleConnection` | sync + the gateway process | `GatewayServer` | `consoleEndpoint` | `portal.e2e.ts` | `RUNTIME_VERIFIED` | No — `ConsoleGatewayRuntimeTest` drives real sockets against a controlled upstream |
| Resize a machine | — | — | `ApplyPlanChange` | `resize` | `ResizeVpsHandler` | `resizeVm` | — | `RUNTIME_VERIFIED` | A resize is only ever a plan change; there is no bare resize endpoint, deliberately |
| Have a terminated machine actually destroyed | — | `DELETE /api/admin/services/{service}` (operator) | `TerminateVpsService` | `destroy_vps` | `DestroyVpsHandler` | `destroyVm` | — | `TESTED` | No — the address goes to quarantine and the node's capacity comes back |

## Backups

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See backups and their state | `/backups` | `GET /vps/{vm}/backups` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Take a backup | `/backups` | `POST /vps/{vm}/backups` | `RequestServiceBackup` | sync + `ReconcileRunningBackups` | — | `startBackup` | — | `TESTED` | `BLOCKED_CREDENTIALS` — no Proxmox Backup Server |
| Restore over the machine | `/backups` | `POST /vps/{vm}/backups/{backup}/restore` | `RestoreServiceBackup` | sync | — | `startRestore` | `portal.e2e.ts` (three specs, including Escape) | `TESTED` | No |
| Be refused a restore from a backup nobody can vouch for | `/backups` | as above | `RestoreServiceBackup` | — | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Delete a backup | — | — | — | — | — | `deleteBackup` | — | `NOT_IMPLEMENTED` | The adapter can; nothing calls it. Retention at the provider is not managed. |

## Dedicated servers

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See servers and their hardware | `/dedicated` | `GET /dedicated`, `GET /dedicated/{server}` | — | sync | — | — | `portal.e2e.ts` | `TESTED` | n/a |
| Power on, shut down, cycle | `/dedicated` | `POST /dedicated/{server}/power` | `ChangeDedicatedServerPower` | sync | — | `powerOn`, `gracefulShutdown`, `reset` | — | `TESTED` | `BLOCKED_HARDWARE` — no BMC has answered |
| Rebuild a physical machine | `/dedicated` | `POST /dedicated/{server}/reinstall` | `RequestDedicatedReinstall` | `reinstall_dedicated` | `ReinstallDedicatedHandler` | BMC boot override, PXE, installer callback | `portal.e2e.ts`, `appearance.e2e.ts` (Arabic) | `BLOCKED_HARDWARE` | **No physical server has been reimaged.** `TESTED` against a fake controller; the hardware half is unproven and is not claimed. |
| Have hardware facts stay current | — | — | `SyncHardwareInventory` | scheduler | — | `hardwareHealth`, `firmwareInventory` | — | `TESTED` | `BLOCKED_HARDWARE` |
| Have the machine returned to stock when the service ends | — | `DELETE /api/admin/services/{service}` then `POST /api/admin/dedicated/{server}/return-to-stock` (operator) | `DecommissionDedicatedServer`, `ReturnDedicatedServerToStock` | sync | — | — | — | `TESTED` | Two acts on purpose: nothing this platform can call proves a physical disk was erased |

## Shared hosting

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See accounts and their usage | `/hosting` | `GET /hosting`, `GET /hosting/{account}/usage` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Have an ordered account actually opened | — | — | `ProvisionOrderedService` | `create_hosting_account` | `CreateHostingAccountHandler` | `createAccount` | — | `TESTED` | `BLOCKED_LICENSE` — `TheWholeLifeOfAHostingAccountTest` |
| Have usage figures be real | — | — | `SyncAccountUsage` | scheduler | — | `accountUsage` | — | `TESTED` | `BLOCKED_LICENSE` |
| Open the control panel | `/hosting` | `POST /hosting/{account}/sso` | `IssueHostingPanelSession` | sync | — | `createSsoSession` | — | `TESTED` | `BLOCKED_LICENSE` |
| Be suspended for non-payment | — | — | `SuspendHostingAccount` | `provisioning` | listener | `suspendAccount` | — | `TESTED` | `BLOCKED_LICENSE` |
| Be restored on payment | — | — | `UnsuspendHostingAccount` | `provisioning` | listener | `unsuspendAccount` | — | `TESTED` | `BLOCKED_LICENSE` |
| Have the account deleted when the service ends | — | `DELETE /api/admin/hosting-accounts/{account}` (operator) | `TerminateHostingAccount` | sync | — | `terminateAccount` | — | `TESTED` | `BLOCKED_LICENSE` |
| Have a panel that is unlicensed stop taking accounts | — | — | `SyncHostingNodeHealth` | scheduler | — | `licenceStatus`, `nodeHealth` | — | `TESTED` | `BLOCKED_LICENSE` |

## Networking

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See assigned addresses | `/ips` | `GET /ips`, `GET /ips/{assignment}` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Set reverse DNS | `/ips` | `PUT /ips/{assignment}/rdns` | `SetReverseDns` | `PublishReverseDnsRecord` | — | `DnsProvider::publish` | — | `TESTED` | `BLOCKED_CREDENTIALS` — no Cloudflare token |
| Have a released address quarantined before reuse | — | — | `IpAllocator::releaseAssignment` | `destroy_vps` | `DestroyVpsHandler` | — | — | `TESTED` | n/a |
| Have a released address come back into circulation | — | — | `ReleaseQuarantinedAddresses` | scheduler | — | — | — | `TESTED` | n/a |
| Buy or manage forward DNS zones | — | — | — | — | — | `createZone` | — | `NOT_IMPLEMENTED` | Not a product. The adapter can; nothing calls it. |

## Notifications and history

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Be told when a service is ready, fails, or needs review | `/notifications` | `GET /notifications` | `NotifyOnProvisioningOutcome` | `notifications` | `DeliverNotification` | SMTP | `portal.e2e.ts` | `RUNTIME_VERIFIED` | No — two worker processes, `AWorkerFinishesWhatTheCustomerStartedTest` |
| Be told about invoices, payments and dunning | `/notifications` | `GET /notifications` | `NotifyOnBillingEvent` | `notifications` | `DeliverNotification` | SMTP | `portal.e2e.ts` | `TESTED` | No |
| Be told about suspension and restoration | `/notifications` | `GET /notifications` | `NotifyOnSubscriptionChange` | `notifications` | `DeliverNotification` | SMTP | — | `TESTED` | No |
| Mark notifications read | `/notifications` | `POST /notifications/{id}/read`, `/read-all` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Switch off optional messages, and be refused for the rest | `/notifications` | `PUT /me/notification-preferences` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Read what has happened to a service | `/services` | `GET /services/{service}/events` | — | sync | — | — | — | `TESTED` | n/a |
| Raise a support ticket | — | — | — | — | — | — | — | `NOT_IMPLEMENTED` | Permissions for tickets exist in the role model; no ticket exists anywhere else in the platform. |

---

## What a customer still cannot do

Stated plainly, because a matrix of mostly-green rows is easy to skim past.

1. **Invite a colleague.** There is no team management. A customer is one login.
2. **Spend wallet credit.** It can be received — an overpayment lands there —
   and no path spends it.
3. **Raise a support ticket.** The roles anticipate one; nothing else does.
4. **Delete a backup**, or have expired archives pruned at the provider.
5. **Buy DNS hosting.** The reverse-DNS half exists; forward zones are not a
   product.
6. **End their own service.** Termination is an operator action behind a
   retention window; a customer cancels the subscription and the service is
   suspended first.

## What no test in this repository proves

Every provider in this table is a fake, a recorded HTTP exchange, or a
controlled local socket. Specifically, and to be repeated in every report:

- **No Proxmox cluster** has created, resized, rebuilt, suspended or destroyed
  a machine for this platform.
- **No physical server** has been reimaged. The dedicated reinstall reaches
  `TESTED` and `BLOCKED_HARDWARE`, and never `REAL_INFRA_VERIFIED`.
- **No cPanel or DirectAdmin licence** is held, so no real panel has opened,
  suspended or repackaged an account.
- **No payment gateway account** exists; no real money has moved.
- **No Cloudflare token** is held; no PTR record has been published.
