# Phase 30B-P — scope addendum: product readiness expansion and deferred capability closure

**Status: IN PROGRESS.**

The original Phase 30B-P report, `docs/phase-30b-p-infrastructure-provider-control-center.md`,
is **CLOSED and unchanged**. This document is the authoritative record of the
addendum only. Nothing here reopens the closed architecture; the three bounded
modules (`Infrastructure`, `Providers`, `ProductReadiness`) are extended, not
replaced, and every product-side change rides the rails those modules and
Phase 30A++ laid.

## A. Starting baseline

| | |
|---|---|
| Branch | `claude/hv-t6hq1p` |
| HEAD at handoff | `1035e45` — the PHPStan-wording correction on the closed report; last commit of code `ba0f4ab` |
| Backend tests | 2708 |
| Frontend unit tests | 81 (18 files) |
| Browser E2E | 133 (23 files) |
| OpenAPI operations | 211 |
| PHPStan | 0 errors locally and in CI's Static analysis job |
| `REAL_INFRA_VERIFIED` | none |
| `READY_TO_SELL` | none |

Verified on arrival with `git status` (clean), `git branch --show-current`
(`claude/hv-t6hq1p`) and `git rev-parse HEAD` (`1035e45…`). No newer commits
existed; nothing was reset.

## B. Current HEAD

Filled at closure. Every slice is pushed and its CI run recorded in section AC.

## C. Delta map

Produced before any code was written, from a full read of the closure report,
both matrices, `docs/phase-30b-real-infrastructure-validation.md`,
`docs/phase-30b-first-node-verdict.md`, `docs/phase-30a-plusplus-domains-wordpress-final.md`,
`docs/customer-capability-matrix.md`, `docs/architecture.md`, `docs/billing.md`,
`docs/dns.md`, and a survey of the `ProductReadiness`, `Providers`, `Domains`,
`Dns`, `Backups`, `SharedHosting` (WordPress), `Billing`, `Payments`, `Wallet`,
`Subscriptions`, `Orders`, `Identity`, `Audit`, `Rbac`, `Notifications` and
`Monitoring` modules and the `infrastructure/` tree.

`REUSE` = the thing exists and is called as it is. `EXTEND` = the thing
exists and gains cases, methods or rows. `NEW` = nothing exists and a typed
thing is built inside an existing module. `BLOCKED_EXTERNAL` = the software
side can be finished; the rest waits on hardware, a licence, a provider or a
credential and is recorded as such, never faked.

### C.1 Engines that are reused, never duplicated

| Engine | Where it lives | Verdict |
| --- | --- | --- |
| Product readiness ladder | `ProductReadiness\Domain\Services\ProductReadinessEvaluator` | REUSE — the rung rules do not change; one cap is added for products whose software is prepared only |
| Requirements | `ProductReadiness\Domain\Services\ProductRequirements` | EXTEND — rows for five prepared products; existing rows made faithful to what the product code calls (see C.3) |
| Capabilities | `Providers\Domain\Enums\ProviderCategory::capabilities()` | EXTEND — new categories with the capabilities their consumers name; a gate ties every declared capability to a consumer |
| Provider readiness | `Providers\Domain\Services\ProviderReadiness` | REUSE |
| Credentials, licences | `Providers` credential and licence centre | REUSE |
| Safety | `Infrastructure\Domain\Services\SafetyGate` | REUSE |
| Plan / approval | `Infrastructure` plan → approval → job chain | REUSE as the pattern for the currency-change request (states, four-eyes, fingerprint) |
| Audit | `Audit\Application\Actions\RecordActAtomically` | REUSE — new `AuditAction` cases land in the same commit as their writer |
| RBAC | `Rbac\Domain\Enums\Permission`, `Role::defaultPermissions()` | EXTEND — permissions for the currency-change approval; nothing else new needs one |
| Observability | `Monitoring` collectors, `infrastructure/monitoring/prometheus/rules` | EXTEND — bounded series and three alerts |
| Notifications | `Notifications\Application\Actions\NotifyCustomer` | EXTEND — types for redemption, file restore, currency change, WordPress staging |
| Provider abstractions | one contract per category, one fake per contract | EXTEND — optional interfaces beside `WordPressInstaller`; typed contracts for prepared products |

### C.2 The addendum items

| # | Item | Verdict | What exists | What is built |
| --- | --- | --- | --- | --- |
| 1 | Product readiness expansion | EXTEND | `Product` (7 cases), `ProductRequirements`, evaluator, `ReadinessPage`, `product_readiness` table | Five more `Product` cases with a declared software state; requirements per product; the twelve readiness answers on the API and the screen; new-sale guard in production; blocker propagation tests |
| 2 | Domain redemption | EXTEND | `DomainState::Redemption`, `DomainOperationKind::Redeem`, `RegistrarCapability::Redemption`, `DomainRegistrarProvider::redeem()`, `domain_tlds.redemption_price_minor`, `DomainInvoicing::describe(Redeem)`, `QuoteDomain` accepts `redeem` | `OrderDomainRedemption` (quote → invoice → operation), dispatch on `InvoicePaid`, `RedeemDomainAtRegistrar` job with the Timeout Rule, reconciliation of `Redemption` domains, fake registrar markers for refusal and timeout, capability answer per TLD driver, audit `domain.redemption.*`, notifications, customer UI states, browser test |
| 3 | DNS zone import / export | NEW (inside `Dns`) | `DnsRecordRules`, `AssertRecordFitsTheZone`, `AddRecord`/`ChangeRecord`/`RemoveRecord`, six record types, per-zone ceiling | BIND parser with bounds, normaliser, plan with ADD/UPDATE/REMOVE/UNCHANGED/REFUSED, whole-plan refusal, non-destructive merge by default with explicit replace, apply through the existing actions only, export of the authoritative model, audit `dns.zone.imported/exported`, customer UI, browser tests |
| 4 | File-level backup restore | NEW (inside `Backups`) | `BackupProvider`, `FakeBackupProvider`, `RestoreServiceBackup`, `BackupState`, customer backups screen | Optional `BackupFileBrowser` interface (the `WordPressInstaller` pattern), path model that never carries a host path, restore request → provider → verification → completed / needs_review / indeterminate, short-lived authorised downloads, traversal and cross-tenant tests, audit `backup.file_restore.*` / `backup.file_download.*`, customer UI, browser tests. Real PBS: BLOCKED_HARDWARE |
| 5 | Country / currency change | NEW (inside `Billing`) | `customers.currency` / `country` with no mutating route, `TaxResolver`, `PaymentProvider::supportsCurrency()`, the plan/approval pattern | A request with states, an impact analysis over invoices / subscriptions / wallets / refunds / tax / gateways / catalogue / orders / domain contacts, blocked or awaiting approval, four-eyes approval, applied only to future commerce, historical rows untouched (tested), audit `account.country_currency_change.*`, customer and operator UI, browser tests |
| 6 | WordPress staging / cloning | EXTEND | `WordPressInstaller` optional interface, `FakeHostingProvider` implements it, `WordPressSite`, provisioning handlers | `WordPressEnvironmentManager` optional interface with a typed capability set, environments table (production / staging / clone), create-staging and push-to-production operations with impact plan, backup awareness, confirmation, Timeout Rule, capability-aware customer UI. Real toolkits: BLOCKED_LICENSE / BLOCKED_PROVIDER |
| 7 | CDN readiness | NEW (typed seat) | nothing | `ProviderCategory::Cdn` with the capabilities the product model names; `Product::Cdn` depending on DNS; no adapter, no catalogue driver marked available |
| 8 | Object storage readiness | NEW (typed seat) | nothing | `ProviderCategory::ObjectStorage`, `ObjectStorageProvider` contract with the methods the product model calls, `Product::ObjectStorage`; no cluster, no adapter |
| 9 | GPU compute readiness | EXTEND (`Compute`) | `ComputeNode`, plan resources | GPU device model (vendor, model, VRAM, count, node, PCI identity, passthrough capability, allocation state), `ProviderCategory::Compute` capability `gpu_passthrough`, `Product::GpuCompute` depending on VPS; BLOCKED_HARDWARE |
| 10 | Email hosting readiness | NEW (typed seat) | nothing | `ProviderCategory::EmailHosting` distinct from `Email`, `EmailHostingProvider` contract, `Product::EmailHosting` depending on DNS with MX/SPF/DKIM/DMARC as capabilities on the DNS requirement |
| 11 | Transactional email readiness | EXTEND | `ProviderCategory::Email` with no driver; `EmailChannel` sends through Laravel mail | A catalogued `smtp` driver backed by the existing mail transport as the adapter, `testable=false` (no tester can be written without a relay); readiness stays blocked on credentials |
| 12 | Kubernetes readiness only | NEW (typed seat) | nothing | `Product::ManagedKubernetes` with dependencies and provider categories (`LoadBalancer`, `Certificates`, `ClusterLifecycle`) that carry only what readiness consumes; capped below production by its software state; declaration refused |
| 13 | Readiness UI expansion | EXTEND | `ReadinessPage` | Twelve answers per product, drill-down product → requirement → provider → credential / licence → machine, twelve products |
| 14 | Provider catalogue expansion | EXTEND | `ProviderCatalogue`, `TheCatalogueOnlyClaimsWhatExistsTest` | Only `smtp` gains an entry, because only it has an adapter. CDN, object storage, email hosting get categories and contracts, not drivers |
| 15 | Capability engine expansion | EXTEND | plain-string capabilities per category | New gate: every declared capability is consumed by a requirement; every requirement names a declared capability; every catalogued driver has an adapter |
| 16 | Gates | EXTEND | eight gates listed in the brief | Extended, none weakened; new `EveryDeclaredCapabilityHasAConsumerTest`, `PreparedProductsCannotBeSoldOnHopeTest` |
| 17 | Security audit | NEW tests | the 30B-P adversarial suites | one adversarial suite per new surface |
| 18 | Audit | EXTEND | `AuditAction` | cases with writers in the same commit |
| 19 | Observability | EXTEND | `ProductCollector`, `ControlCenterCollector`, rules | bounded series, three alerts with runbooks |
| 20 | Notifications | EXTEND | `NotificationType` | customer notifications for the real flows only |
| 21 | Frontend | EXTEND | Control Center and portal screens | English/Arabic, LTR/RTL, under the translation gate |
| 22 | Browser E2E | EXTEND | 133 specs | one spec per real capability, Arabic coverage |
| 23 | Whole-life tests | NEW | the 30A++ pattern | one per real flow; readiness invariants |
| 24 | Failure / uncertainty | REUSE | the Timeout Rule as written for domains, backups, deployments | applied to redemption, file restore, push-to-production |
| 25 | Matrices | EXTEND | both matrices | rows for every new capability and product |
| 26 | Documentation | NEW | — | this document; one link from the original report |
| 27 | Clean room | REUSE | the 30B-P clean-room script | run again from a fresh clone; PHPStan recorded truthfully |
| 28 | CI | REUSE | the pipeline | every slice pushed and observed |

### C.3 Requirements that were narrower than the code

The closure matrix said own requirements name "the capabilities the product's
code calls". Reading the code against the declared question sets found the
following calls that no requirement named, and the following declared
capabilities that nothing calls. Both are corrected in slice 1 and a gate
keeps them corrected.

| Category capability | Called by | Was required | Now |
| --- | --- | --- | --- |
| compute `resize` | `ApplyPlanChange` → `ComputeProvider::resizeVm` | no | required by VPS |
| compute `console` | `VpsController` → `consoleEndpoint` | no | required by VPS |
| compute `templates` | `ReinstallVm` chooses from `VmTemplate` | no | required by VPS |
| backup `verify` | `BackupProvider::supportsVerification` / `startVerification` | no | required by Backups |
| hosting `sso` | `HostingController` → `createSsoSession` | no | required by Shared hosting |
| hosting `usage` | `HostingController` → `accountUsage` | no | required by Shared hosting |
| wordpress_installer `version` | `WordPressInstallation::$version` read by verification | no | required by WordPress |
| registrar `premium` | `DomainPricing` with `AvailabilityAnswer::premium` | no | required by Domains |
| registrar `held_names` | `ReconcileDomains::findOrphans` | no | required by Domains |
| registrar `redemption` | nothing (deferred in 30A++) | no | required by Domains once slice 2 lands |
| payment `refund` | `RefundPayment` → `PaymentProvider::refund` | no | shared requirement |
| payment `currencies` | `PaymentProvider::supportsCurrency` | no | shared requirement; also consumed by the currency-change impact analysis |
| bmc `inventory` | `DiscoverServer` through the BMC tester | no | required by Dedicated |
| bmc `firmware` | `DedicatedProvider::firmwareInventory` | no | required by Dedicated |
| email `tls`, `sender_identity` | nothing | — | removed from the question set: no code asks, and a discovered answer nobody reads is a claim |
| monitoring `logs` | nothing | — | removed; `scrape`, `alerting` consumed by Kubernetes readiness |

### C.4 What is BLOCKED_EXTERNAL, stated once

| Thing | Blocker | What the software does about it |
| --- | --- | --- |
| Real registrar redemption, `.sy` redemption | BLOCKED_PROVIDER / BLOCKED_LICENSE | `SyRegistryProvider` answers `UNSUPPORTED` for redemption; a TLD whose windows are unknown quotes nothing and the customer sees "redemption unavailable" with the reason |
| PBS file-level browse | BLOCKED_HARDWARE | `ProxmoxBackupProvider` does not implement the browser interface; the capability reads `unsupported` for it and the fake proves the path |
| WP Toolkit / Softaculous / Installatron staging | BLOCKED_LICENSE / BLOCKED_PROVIDER | neither real panel implements the environment interface; capabilities differ per implementation and the UI shows only what the provider reports |
| Cloudflare CDN features | BLOCKED_CREDENTIALS / not implemented | no CDN adapter is catalogued; the category and contract exist |
| Object storage cluster | BLOCKED_HARDWARE / BLOCKED_PROVIDER | contract and product model only |
| GPU capacity | BLOCKED_HARDWARE | device model only, no inventory invented |
| Mail platform | BLOCKED_PROVIDER / BLOCKED_HARDWARE | contract and product model only |
| SMTP relay | BLOCKED_CREDENTIALS | driver catalogued, untestable |
| Kubernetes | readiness only by decree | the product cannot be declared sellable and is capped below production |

### C.5 What is deliberately not built

No second readiness engine. No `ProductControlCenter`, `FutureProducts`,
`UniversalProvider` or generic plugin engine. No S3 API, no mail server, no
CDN network, no GPU scheduler, no cluster control plane. No fake real adapter
for any prepared product. No `REAL_INFRA_VERIFIED`.

---

Sections D onward are written as each slice closes.
