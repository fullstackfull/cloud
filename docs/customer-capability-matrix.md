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
| Ask for the account's country or currency to change, see what it would touch, check again, withdraw | `/` | `GET|POST /account/country-currency-changes`, `POST …/{id}/reanalyse`, `POST …/{id}/withdraw` | `RequestCountryCurrencyChange`, `AnalyseCountryCurrencyChange`, `ReanalyseCountryCurrencyChange`, `WithdrawCountryCurrencyChange` | sync | — | — | `account-changes.e2e.ts` | `RUNTIME_VERIFIED` | n/a — nothing already issued is ever converted |
| Have the change decided by a person and applied, now or at a scheduled moment | `/admin/account-changes` | `GET /admin/customers/country-currency-changes`, `POST …/{id}/approve|reject` | `DecideCountryCurrencyChange`, `ApplyCountryCurrencyChange`; `customers:apply-country-currency-changes` every 5 min | scheduler | — | — | `account-changes.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Sign out | any page | `POST /logout` | — | sync | — | — | `auth.e2e.ts`, `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Reset a forgotten password | `/forgot-password` | `POST /password/forgot`, `POST /password/reset` | Laravel's password broker | `notifications` | `DeliverNotification` | SMTP | — | `TESTED` | No |
| Change password, edit profile | `/profile` | `PUT /me/password`, `PATCH /me` | — | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Enable and disable two-factor | `/security` | `POST`/`DELETE /me/two-factor` | `ManageTwoFactor` | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| See and revoke sessions | `/security` | `GET`/`DELETE /me/sessions` | — | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Read sign-in history | `/security` | `GET /me/login-activity` | — | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Issue and revoke API tokens | `/api-tokens` | `POST`/`DELETE /me/api-tokens` | `IssueApiToken` | sync | — | — | `account.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Invite a colleague, and set what they may do | `/settings/team` | `POST /team/invitations` | `InviteMember` | `notifications` (the offer) | `DeliverNotification` | SMTP | `team.e2e.ts` | `RUNTIME_VERIFIED` | No — mail is not sent from here |
| Read an offer, accept it, or decline it | `/invitations/:token` | `GET /invitations/{token}`, `POST /invitations/{token}/accept`, `POST /invitations/{token}/decline` | `AcceptInvitation`, `DeclineInvitation` | sync | — | — | — | `TESTED` | n/a — the browser suite drives the inviter's half; the invitee's is proven in `tests/Feature/Team` |
| Withdraw an offer | `/settings/team` | `DELETE /team/invitations/{id}` | `RevokeInvitation` | sync | — | — | `team.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Send the offer again | `/settings/team` | `POST /team/invitations/{id}/resend` | `ResendInvitation` | `notifications` | `DeliverNotification` | SMTP | — | `TESTED` | No |
| See who is on the account and what each may do | `/settings/team` | `GET /team/members`, `GET /team/invitations` | — | sync | — | — | `team.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Be shown the list without a way to change it, on a role that may not | `/settings/team` | as above | — | sync | — | — | `team.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Change a colleague's role | `/settings/team` | `PATCH /team/members/{member}` | `ChangeMemberRole` | sync | — | — | `team.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Remove a colleague | `/settings/team` | `DELETE /team/members/{member}` | `RemoveMember` | sync | — | — | — | `TESTED` | n/a |
| Be refused the act that would leave the account ownerless | `/settings/team` | as above | `ChangeMemberRole`, `RemoveMember` | — | — | — | — | `TESTED` | n/a |
| Hand the account to somebody else | `/settings/team` | `POST /team/transfer-ownership` | `TransferOwnership` | sync | — | — | — | `TESTED` | n/a |

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
| See what credit would cover before spending it | `/invoices` | `GET /invoices/{invoice}/wallet-credit` | `QuoteWalletPayment` | sync | — | — | `wallet-credit.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Pay an invoice from credit, in full or in part | `/invoices` | `POST /invoices/{invoice}/wallet-credit` | `PayInvoiceFromWallet` → `SettleInvoice` | sync | — | — | `wallet-credit.e2e.ts` | `RUNTIME_VERIFIED` | n/a — the money never leaves the platform |
| Have a refund of a wallet payment go back to the wallet | — | `POST /api/admin/payments/{payment}/refund` (operator) | `IssueRefund` | sync | — | — | — | `TESTED` | n/a |

## Subscriptions, plan changes and cancellation

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See subscriptions and renewal dates | `/subscriptions` | `GET /subscriptions` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Cancel a subscription at the end of the paid period | `/subscriptions` | `POST /subscriptions/{id}/cancel` | `CancelCustomerSubscription` | `notifications` | `DeliverNotification` | SMTP | `leaving.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| End one today, behind its own reference typed back | `/subscriptions` | `POST /subscriptions/{id}/cancel` with `immediately` | `CancelCustomerSubscription` | `provisioning` (the service stops) | `EnforceServiceStateForSubscription` | `suspendVm` / `suspendAccount` | `leaving.e2e.ts` | `RUNTIME_VERIFIED` | No |
| See when the data behind a stopped service is destroyed | `/services` | `GET /services` | — | sync | — | — | `leaving.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Have the service actually end when the window closes | — | — | `EndExpiredServices` → `EndOfService` | scheduler → `destroy_vps` | `DestroyVpsHandler` | `destroyVm`, `terminateAccount`, decommission | — | `RUNTIME_VERIFIED` | No — `TheNewSweepsRunOutsideThisProcessTest` runs the sweep in its own process and a worker destroys the machine |
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
| Have a terminated machine actually destroyed | `/subscriptions` (by cancelling) or `DELETE /api/admin/services/{service}` (operator) | as noted | `EndOfService` → `TerminateVpsService` | `destroy_vps` | `DestroyVpsHandler` | `destroyVm` | — | `RUNTIME_VERIFIED` | No — the address goes to quarantine, the node's capacity comes back, and a worker in another process does the destroying |
| Have the platform confirm the machine was really built | — | — | `PollProviderTasks` | scheduler | — | `getTask` | — | `RUNTIME_VERIFIED` | No — a job that succeeded means the request was accepted; this is what turns that into evidence |

## Backups

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See backups and their state | `/backups` | `GET /vps/{vm}/backups` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Browse a backup folder by folder, with symlinks shown and never followed | `/backups` | `GET /vps/{vm}/backups/{backup}/files?path=` | `BrowseBackupFiles`; `BackupPath` refuses traversal | sync | — | `FileLevelBackupProvider::listFiles` | `backup-files.e2e.ts` | `RUNTIME_VERIFIED` (fake) | `BLOCKED_HARDWARE` — the Proxmox provider does not implement the interface; PBS file-restore was never called |
| Download one file through a short-lived single-use link | `/backups` | `POST …/files/downloads`, `GET /backups/downloads/{token}` | `IssueBackupFileDownload`, `ServeBackupFileDownload` | sync | — | `readFile` | `backup-files.e2e.ts` | `RUNTIME_VERIFIED` (fake) | same |
| Put named files back on the server, with the hostname typed | `/backups` | `POST …/files/restore`, `GET …/file-restores` | `RestoreBackupFiles`; `ReconcileFileRestores` on `backups:reconcile` | scheduler | — | `startFileRestore`, `taskState` | `backup-files.e2e.ts` | `RUNTIME_VERIFIED` (fake) | same; `needs_review` on a timeout, never retried |
| Take a backup | `/backups` | `POST /vps/{vm}/backups` | `RequestServiceBackup` | sync + `ReconcileRunningBackups` | — | `startBackup` | — | `TESTED` | `BLOCKED_CREDENTIALS` — no Proxmox Backup Server |
| Restore over the machine | `/backups` | `POST /vps/{vm}/backups/{backup}/restore` | `RestoreServiceBackup` | sync | — | `startRestore` | `portal.e2e.ts` (three specs, including Escape) | `TESTED` | No |
| Be refused a restore from a backup nobody can vouch for | `/backups` | as above | `RestoreServiceBackup` | — | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Delete a backup, behind its own reference typed back | `/backups` | `DELETE /vps/{vm}/backups/{backup}` | `RequestBackupDeletion` | scheduler → `DeleteBackupAtProvider` | — | `deleteBackup`, `listBackups` | `backup-deletion.e2e.ts` | `TESTED` | `BLOCKED_CREDENTIALS` — the sweep confirms absence by listing the datastore |
| Change their mind before the sweep acts | `/backups` | `POST /vps/{vm}/backups/{backup}/keep` | `RequestBackupDeletion::cancel` | sync | — | — | `backup-deletion.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Have expired archives removed on the plan's terms | — | — | `EnforceBackupRetention` | scheduler | — | `deleteBackup` | — | `TESTED` | `BLOCKED_CREDENTIALS` |
| Have the platform notice an archive that has silently gone | — | — | `ReconcileBackupInventory` | scheduler | — | `listBackups` | — | `TESTED` | `BLOCKED_CREDENTIALS` |
| Keep their backups through a cancellation's retention window | — | — | `HoldBackupsThroughRetention` | sync (on the cancellation) | — | — | — | `TESTED` | n/a |

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

## Domains

The one product on this platform that cannot be repossessed: a registry fee is
spent the moment a registration succeeds, so every row below invoices first and
asks the registrar only when the money has arrived.

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Search for a name and be told all five answers | `/domains` | `GET /domains/search` | `SearchDomains` | sync | — | `checkAvailability`, `supports` | `domains.e2e.ts` | `RUNTIME_VERIFIED` | `BLOCKED_CREDENTIALS` — no registrar account |
| Be quoted a price the platform will honour | `/domains` | `POST /domains/quotes` | `QuoteDomain` → `DomainPricing` | sync | — | `checkAvailability` | `domains.e2e.ts` | `RUNTIME_VERIFIED` | `BLOCKED_CREDENTIALS` |
| Buy a name | `/domains` | `POST /domains` | `OrderDomainRegistration` | `payments` listener → job | `RegisterDomainAtRegistrar` | `register` | — | `TESTED` | `BLOCKED_CREDENTIALS` — `TheWholeLifeOfADomainRegistrationTest` |
| Not be charged twice when a settlement webhook repeats | — | — | `RegisterDomainOnPayment` | `payments` | — | — | — | `TESTED` | n/a |
| Have an unanswered registration left alone rather than retried | — | — | `RegisterDomainAtRegistrar` | `provisioning` | — | — | — | `TESTED` | n/a |
| Have that uncertainty settled against the registry | — | — | `ReconcileDomains` | scheduler | — | `inspect`, `transferStatus` | — | `TESTED` | `BLOCKED_CREDENTIALS` |
| Get the name back if nobody ever paid | — | — | `SweepDomainLifecycle` | scheduler | — | — | — | `TESTED` | n/a |
| Change the delegation | `/domains` | `PUT /domains/{domain}/nameservers` | `SetDomainNameservers` | sync | — | `setNameservers` | `domains.e2e.ts` | `RUNTIME_VERIFIED` | `BLOCKED_CREDENTIALS` |
| Change the registrant | `/domains` | `PUT /domains/{domain}/contacts` | `UpdateDomainContacts` | sync | — | `setContacts` | — | `TESTED` | `BLOCKED_CREDENTIALS` |
| Lock and unlock the name | `/domains` | `PUT /domains/{domain}/transfer-lock` | `SetTransferLock` | sync | — | `setTransferLock` | `domains.e2e.ts` | `RUNTIME_VERIFIED` | `BLOCKED_CREDENTIALS` |
| Take the transfer code and leave | `/domains` | `POST /domains/{domain}/authorisation-code` | `IssueAuthorisationCode` | sync | — | `authorisationCode` | `domains.e2e.ts` | `RUNTIME_VERIFIED` | `BLOCKED_CREDENTIALS` |
| Renew a name | `/domains` | `POST /domains/{domain}/renewals` | `OrderDomainRenewal` | `payments` listener → job | `RenewDomainAtRegistrar` | `renew` | — | `TESTED` | `BLOCKED_CREDENTIALS` |
| Have auto-renew actually renew | — | — | `SweepDomainLifecycle` | scheduler | — | — | — | `TESTED` | n/a |
| Be warned before a name lapses | `/notifications` | — | `SweepDomainLifecycle` | scheduler | — | — | — | `TESTED` | n/a |
| Transfer a name in | `/domains` | `POST /domains/transfers` | `OrderDomainTransfer` | `payments` listener → job | `StartDomainTransfer` | `startTransfer` | — | `TESTED` | `BLOCKED_CREDENTIALS` |
| Buy a `.sy` name | — | — | — | — | — | — | — | `NOT_IMPLEMENTED` | `BLOCKED_LICENSE` — no registry licence or technical contract |
| Recover a name from redemption | — | — | — | — | — | `redeem` | — | `NOT_IMPLEMENTED` | The catalogue refuses to quote one; see below |

## WordPress hosting

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Make a staging copy, or clone to another domain | `/wordpress` | `POST /wordpress/sites/{id}/staging`, `POST …/clones` | `CopyWordPressSite` | `copy_wordpress_site` | `CopyWordPressSiteHandler` | `WordPressStagingProvider::copyWordPress` | `wordpress-copies.e2e.ts` | `RUNTIME_VERIFIED` (fake) | `NOT_IMPLEMENTED` for cPanel and DirectAdmin — no toolkit endpoint has been called |
| See what a push would overwrite, then push the copy over production with the domain typed | `/wordpress` | `GET …/push/impact`, `POST …/push`, `GET …/operations` | `PushWordPressToProduction` | `push_wordpress_to_production` | `PushWordPressToProductionHandler` | `pushWordPressToProduction` | `wordpress-copies.e2e.ts` | `RUNTIME_VERIFIED` (fake) | same; production `needs_review` on a timeout, never retried; the platform holds no backup of a shared-hosting site and says so |
| Order a site, with any of the four domain options | `/wordpress` | `POST /wordpress/sites` | `OrderWordPressSite` → `PlaceOrder` | sync | — | — | `wordpress.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Have the hosting account built when it is paid for | — | — | `ProvisionOrderedService` | `create_hosting_account` | `CreateHostingAccountHandler` | `createAccount` | — | `TESTED` | `BLOCKED_LICENSE` |
| Have WordPress installed into it | — | — | `InstallWordPressOnceTheAccountExists` | `install_wordpress` | `InstallWordPressHandler` | `installWordPress` | — | `TESTED` | `NOT_IMPLEMENTED` for cPanel and DirectAdmin — see below |
| Have an unanswered install left alone rather than repeated | — | — | `InstallWordPressHandler` | `install_wordpress` | — | `wordPressInstallation` | — | `TESTED` | n/a |
| Be told the site is live only once somebody looked | `/wordpress` | `GET /wordpress/sites` | `VerifyWordPressSites` | scheduler | — | `SiteProbe::probe` | `wordpress.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Be told which of the four steps is outstanding | `/wordpress` | `GET /wordpress/sites` | — | sync | — | — | `wordpress.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Pay for a domain and its hosting on one invoice | — | — | `IssueInvoice` | `payments` | — | — | — | `TESTED` | n/a |

## Networking

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See assigned addresses | `/ips` | `GET /ips`, `GET /ips/{assignment}` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Preview a BIND zone file as a diff, apply exactly the previewed plan, export the zone | `/dns` | `POST /dns/zones/{id}/import/plan`, `POST …/import`, `GET …/export` | `PlanZoneImport`, `ApplyZoneImport` (through `AddRecord`/`ChangeRecord`/`RemoveRecord`), `ExportZone`; `ZoneFileParser` with bounds | sync | — | the zone's DNS provider, one record at a time | `dns-import.e2e.ts` | `RUNTIME_VERIFIED` (fake) | as DNS |
| Set reverse DNS | `/ips` | `PUT /ips/{assignment}/rdns` | `SetReverseDns` | `PublishReverseDnsRecord` | — | `DnsProvider::publish` | — | `TESTED` | `BLOCKED_CREDENTIALS` — no Cloudflare token |
| Have a released address quarantined before reuse | — | — | `IpAllocator::releaseAssignment` | `destroy_vps` | `DestroyVpsHandler` | — | — | `TESTED` | n/a |
| Have a released address come back into circulation | — | — | `ReleaseQuarantinedAddresses` | scheduler | — | — | — | `TESTED` | n/a |
| Hold a domain's DNS here | `/dns` | `POST /dns/zones` | `ClaimZone` → `PublishZone` | `default` | — | `createZone` | `dns.e2e.ts` | `TESTED` | `BLOCKED_CREDENTIALS` — no Cloudflare token |
| See what to delegate the domain to | `/dns` | `GET /dns/zones` | — | sync | — | — | `dns.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Publish A, AAAA, CNAME, MX, TXT and CAA records | `/dns` | `POST /dns/zones/{zone}/records` | `AddRecord` → `PublishRecord` | `default` | — | `publish` | `dns.e2e.ts` | `TESTED` | `BLOCKED_CREDENTIALS` |
| Change what a record says | `/dns` | `PATCH /dns/zones/{zone}/records/{record}` | `ChangeRecord` | `default` | — | `publish` | — | `TESTED` | `BLOCKED_CREDENTIALS` |
| Remove a record | `/dns` | `DELETE /dns/zones/{zone}/records/{record}` | `RemoveRecord` | `default` | — | `delete` | `dns.e2e.ts` | `TESTED` | `BLOCKED_CREDENTIALS` |
| Be refused a record that cannot mean what they meant | `/dns` | as above | `DnsRecordRules`, `AssertRecordFitsTheZone` | — | — | — | `dns.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Give the domain up, behind its own name typed back | `/dns` | `DELETE /dns/zones/{zone}` | `ReleaseZone` → `RemoveZone` | `default` | — | `deleteZone` | `dns.e2e.ts` | `TESTED` | `BLOCKED_CREDENTIALS` |
| Have the platform notice a zone edited behind its back | — | — | `ReconcileZones` | scheduler | — | `records`, `zones` | — | `TESTED` | `BLOCKED_CREDENTIALS` — reported, never repaired |

## Notifications and history

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Be told when a service is ready, fails, or needs review | `/notifications` | `GET /notifications` | `NotifyOnProvisioningOutcome` | `notifications` | `DeliverNotification` | SMTP | `portal.e2e.ts` | `RUNTIME_VERIFIED` | No — two worker processes, `AWorkerFinishesWhatTheCustomerStartedTest` |
| Be told about invoices, payments and dunning | `/notifications` | `GET /notifications` | `NotifyOnBillingEvent` | `notifications` | `DeliverNotification` | SMTP | `portal.e2e.ts` | `TESTED` | No |
| Be told about suspension and restoration | `/notifications` | `GET /notifications` | `NotifyOnSubscriptionChange` | `notifications` | `DeliverNotification` | SMTP | — | `TESTED` | No |
| Mark notifications read | `/notifications` | `POST /notifications/{id}/read`, `/read-all` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Switch off optional messages, and be refused for the rest | `/notifications` | `PUT /me/notification-preferences` | — | sync | — | — | `portal.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Read what has happened to a service | `/services` | `GET /services/{service}/events` | — | sync | — | — | — | `TESTED` | n/a |
| Raise a support ticket, with files | `/support` | `POST /support/tickets` | `OpenTicket`, `StoreAttachments` | `notifications` | `DeliverNotification` | SMTP | `support.e2e.ts` | `RUNTIME_VERIFIED` | No |
| Read the thread, and never an internal note | `/support` | `GET /support/tickets/{ticket}` | — | sync | — | — | `support.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Reply, which hands the ticket back to the team | `/support` | `POST /support/tickets/{ticket}/replies` | `ReplyToTicket` | `notifications` | `DeliverNotification` | SMTP | `support.e2e.ts` | `RUNTIME_VERIFIED` | No |
| Close a ticket — and not resolve one | `/support` | `POST /support/tickets/{ticket}/close` | `ChangeTicketState` | sync | — | — | `support.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Download an attachment, streamed and never linked | `/support` | `GET /support/attachments/{id}` | — | sync | — | — | — | `TESTED` | n/a |

## What an operator can do about the above

Not customer capabilities, and here because several of the rows above depend on
them: a reconciliation nobody reads is a log file, and a job stuck in review
that no screen shows is a service quietly not working.

| Capability | UI | API | Action | Queue | Handler | Provider | E2E | State | Real provider |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| See what disagrees with the provider, on any resource | `/admin/drift` | `GET /api/admin/drift` | — | sync | — | — | `reconciliation.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| See which resource a finding is about, not only which provider | `/admin/drift` | as above | — | sync | — | — | `reconciliation.e2e.ts` | `RUNTIME_VERIFIED` | n/a |
| Acknowledge a finding, or close it with a written resolution | `/admin/drift` | `POST /api/admin/drift/{drift}/review` | `ReviewDrift` | sync | — | — | `reconciliation.e2e.ts` | `RUNTIME_VERIFIED` | n/a — the button says so: nothing here changes a provider |
| Be told a hosting account exists here and not on the panel | `/admin/drift` | as above | `ReconcileHostingNodes` | scheduler (`hosting:reconcile`) | — | `listAccounts` | `reconciliation.e2e.ts` | `TESTED` | `BLOCKED_LICENSE` — five findings, and it repairs none of them |
| Be told an account exists on the panel and not here, or a suspension the two disagree about | `/admin/drift` | as above | `ReconcileHostingNodes` | scheduler | — | `listAccounts` | `reconciliation.e2e.ts` (the orphan) | `TESTED` | `BLOCKED_LICENSE` |
| Be told a job whose remote task never finished | `/admin/provisioning` | `GET /api/admin/provisioning/needs-review` | `PollProviderTasks` | scheduler (`compute:poll-tasks`) | — | `getTask` | `reconciliation.e2e.ts` | `RUNTIME_VERIFIED` | No — an indeterminate task goes to review and is never retried |
| See a service the platform has stopped trusting | `/admin/operations` | `GET /api/admin/operations/reinstalls` | — | sync | — | — | — | `TESTED` | n/a |
| Answer a ticket, and write a note the customer never sees | `/admin/support` | `POST /api/admin/support/tickets/{ticket}/replies` | `ReplyToTicket` | `notifications` | `DeliverNotification` | SMTP | `support.e2e.ts` | `RUNTIME_VERIFIED` | No |
| Read every state above in Arabic, translated rather than as its enum value | both portals | — | — | — | — | — | `reconciliation.e2e.ts`, `appearance.e2e.ts` | `RUNTIME_VERIFIED` | n/a — guarded by `EveryStateAScreenShowsIsTranslatedTest` |

---

## What a customer still cannot do

Stated plainly, because a matrix of mostly-green rows is easy to skim past.
Every entry on the previous edition of this list — team membership, spending
wallet credit, support tickets, deleting a backup, forward DNS, ending their
own service — is now a row above. What replaces them is shorter, and none of
it is an oversight:

1. **Buy a `.sy` name.** The seat exists, every capability answers false, and
   the search reports the namespace as not sold. What is missing is a registry
   licence and a technical contract, neither of which can be written around:
   an invented EPP client would produce tests that pass and a first real
   registration that fails, with every design decision downstream of it made
   from fiction.
2. **Recover a name from redemption.** The state exists, the price column
   exists, and the catalogue refuses to quote one unless an operator has been
   told the registry's penalty. Every registry requires a manual step for
   this, and none of them is built.
3. **Have WordPress installed on a real cPanel or DirectAdmin node.** The
   installer is an optional interface on the panel boundary, and neither real
   adapter implements it. Writing one means guessing at WP Toolkit's or
   Softaculous's API for the version each node runs. The fake implements it
   fully, so the product chain is proven; the two real panels will refuse to
   take a WordPress order until the toolkit clients exist.
4. **Move a zone's records in or out as a file.** No zone-file import or
   export: records are added one at a time. A customer with fifty records
   migrating in will feel it.
5. **Restore one file from a backup.** A restore is the whole machine; there
   is no file-level browse.
6. **Undo a deletion once the sweep has run.** The hour between asking and
   acting is the only window, and it is on purpose — after it, the archive is
   gone at the provider and no row in this platform brings it back.
7. **Change their own account's currency or country.** Both are set at
   registration and pinned to the ledger; changing either is an operator act
   through support, because it would reprice open subscriptions.

## What no test in this repository proves

Every provider in this table is a fake, a recorded HTTP exchange, or a
controlled local socket. Specifically, and to be repeated in every report:

- **No Proxmox cluster** has created, resized, rebuilt, suspended or destroyed
  a machine for this platform, and none has answered `getTask` about one.
- **No physical server** has been reimaged. The dedicated reinstall reaches
  `TESTED` and `BLOCKED_HARDWARE`, and never `REAL_INFRA_VERIFIED`.
- **No cPanel or DirectAdmin licence** is held, so no real panel has opened,
  suspended or repackaged an account — and the hosting reconciliation has
  never compared this platform against a real one.
- **No payment gateway account** exists; no real money has moved. Wallet
  credit is the one payment path proven end to end, and only because the
  money never leaves the platform.
- **No registrar account** exists. No real registry has answered an
  availability check, taken a registration, moved a term, released an
  authorisation code or acknowledged a transfer. Every domain row above that
  says `TESTED` was proven against a fake that models the two failures that
  cost money — a registration that times out with the name registered, and a
  refusal that leaves nothing behind — and against nothing else.
- **No WordPress toolkit** has installed anything. The install path is proven
  against a fake, and the two real panel adapters deliberately do not
  implement the installer at all.
- **No customer's site has been fetched over the public internet.** The
  verification sweep is proven against a probe that answers from markers in a
  name. What has been proven for real is the refusal: nine tests establish
  that a name resolving anywhere private is declined before a request is
  made.
- **No Cloudflare token** is held. No PTR record, no zone and no record of any
  type has been published to a real resolver, and no name this platform holds
  has ever resolved on the public internet.
- **No mail has been sent.** Every notification, invitation and ticket reply
  proven above was written to a log by `MAIL_MAILER=log`.
- **No provider has deleted a backup.** The deletion sweep and the retention
  sweep are proven against a fake datastore that answers `listBackups`
  honestly; a real Proxmox Backup Server has never been asked to prune
  anything.

---

## Phase 30B: none of the above changed

Phase 30B set out to move rows in this document from "real provider: no" to
`REAL_INFRA_VERIFIED`, one capability at a time and only with evidence.

**Not one cell changed**, because no evidence of that kind was produced. The
environment running the phase had no route to any management network, no
credential for any provider, no hardware, and an outbound proxy that refuses
every third-party API this platform integrates with.

The list above is therefore still exact. Every capability's specific blocker —
which of `BLOCKED_NETWORK`, `BLOCKED_CREDENTIALS`, `BLOCKED_HARDWARE` or
`BLOCKED_LICENCE` has to be removed first — is recorded in
[real-infrastructure-verification-matrix.md](real-infrastructure-verification-matrix.md),
and the probes behind those blockers are in
[phase-30b-real-infrastructure-inventory.md](phase-30b-real-infrastructure-inventory.md).

The rule this document has followed since it was written still holds: a cell
changes when one specific action has been performed against one real provider
and independently confirmed. Not when a provider is connected, and never for a
whole provider at once.
