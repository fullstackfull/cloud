# Phase 30B-P — scope addendum: product readiness expansion and deferred capability closure

**Status: CLOSED.** Verdict in section AJ: the addendum's applicable software scope is complete to the closure standard; nothing is `REAL_INFRA_VERIFIED`; no product is `READY_TO_SELL`.

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

| | |
|---|---|
| Last commit of code | `8d16255` — the browser-fixture fix after the addendum's eight slices (`5669d00`, `01a1eef`, `20a7186`, `8d503c2`, `d528255`, `67355e7`, `5844e37`, `24fddff`) |
| After it | `09cbc23` (report draft), `9e86429` (the link from the closed report), and this closure commit — documents only |
| Backend tests | 2 799 (+91) |
| Frontend unit tests | 91 in 20 files (+10, +2) |
| Browser E2E | 150 in 27 files (+17, +4) |
| Migrations | 54 (+4) |
| OpenAPI operations | 234 (+23) |
| PHPStan | 0 errors locally and in CI's Static analysis job; not executed in the clean room (AB) |
| `REAL_INFRA_VERIFIED` | none |
| `READY_TO_SELL` | none |

Every slice is pushed and its CI run recorded in AC.

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


## D. Product Readiness expansion

Commit `5669d00`. The ladder did not change; what stands on it did.

| | |
|---|---|
| Products | 12: the seven of the closure report, plus `cdn`, `object_storage`, `gpu_compute`, `email_hosting` (**prepared**) and `managed_kubernetes` (**readiness only**) — `ProductReadiness\Domain\Enums\Product` |
| Software state | `ProductSoftwareState` (`complete`, `prepared`, `readiness_only`) declared on the enum, not in the database, so nobody can edit a product into completeness |
| The cap | `ProductReadinessEvaluator` stops a prepared or readiness-only product at `ready_for_real_validation` with blocker `not_implemented`, whatever its providers say; `DeclareProductSellable` refuses those products by name (`readiness_refused`) |
| The sale guard | `AssertProductMaySell`, called by `PlaceOrder`, `OrderDomainRegistration`, `OrderDomainTransfer` and `OrderWordPressSite`; in production a new sale of a product below `ready_to_sell` answers 409 `product.not_sellable`; outside production it stands aside so the sale can be rehearsed; renewals and existing services are never examined |
| The answers | `ReadinessQuestions` derives the ten readiness answers the addendum asks from the verdict, so screen and verdict cannot disagree; `GET readiness/products` carries them as `answers` |
| GPU | `gpu_devices` table, `RegisterGpuDevice`, `GET|POST infrastructure/servers/{server}/gpus` (`infrastructure.view` / `infrastructure.manage`), audit `infrastructure.gpu.registered`, 409 `gpu_exists` at the same PCI address; a card counts as capacity only on a machine classified to allow configuration, in a passthrough mode; recording never raises a classification |
| Requirements | rows for the five products, and the corrections of C.3 |
| Tests | `PreparedProductsCannotBeSoldOnHopeTest` (9), `AGpuIsRecordedAndCountedOnlyWhereItMayBeUsedTest` (3), `NothingIsSellableOnHopeTest` extended; gates in AA |
| Screens | `/readiness` shows every product with its software state and its answers, each blocker linking to what carries it; `/machines` records a GPU |
| E2E | `control-center-readiness.e2e.ts` ("sees the prepared products as prepared, answered, and not for sale", Arabic), `control-center-machines.e2e.ts` ("records a GPU in a machine without touching it, and the classification stays where it was") |

Where the twelve stand today is table AE. All twelve are `not_ready`.

## E. Domain Redemption

Commit `01a1eef`. Phase 30A++ deferred self-service redemption because the
platform refused to quote a penalty nobody had told it. The software side is
complete now, on the rails a renewal already uses.

| | |
|---|---|
| Contract | `DomainRegistrarProvider::redemptionSupport(): RedemptionSupport` (`supported`, `unsupported`, `unknown`, `blocked_configuration`) and a typed `RedemptionAnswer` — silence is never read as support; `SyRegistryProvider` answers `unknown` because no policy has been published |
| Price | from `domain_tlds.redemption_price_minor` and the windows on the catalogue row, never from the request; a namespace whose penalty or windows are unknown refuses with the reason and quotes nothing |
| Flow | `OrderDomainRedemption`: quote → invoice → `Redeem` operation created only on `InvoicePaid` → registrar job with the Timeout Rule |
| Timeout | an unanswered redemption leaves the domain itself `indeterminate` (the customer holds nothing usable either way); reconciliation settles it against the registry's own expiry: restored → `active`, still lapsed → `expired` on the registry's clock |
| Refusal | the name stays in `redemption`; the invoice is marked as owing a refund; `service.domain_redemption_failed` |
| Invariants | one attempt in flight per name; two orders from one quote make one operation; a settlement that arrives twice recovers the name once; the lifecycle sweep never deletes a name whose paid recovery is in flight; a name not in redemption cannot be redeemed and one that is cannot be renewed |
| Audit | `domain.redemption.ordered` |
| Observability | `lynomia_domain_redemptions_total{state}`; alert `DomainRedemptionNeedsReview` → `docs/runbooks/domain-redemption-indeterminate.md` |
| Tests | `TheWholeLifeOfADomainRedemptionTest` (11), including cross-tenant and the `.sy` answer |
| Screens | the domain row says whether the name can be recovered and, if not, why; `POST domains/{domain}/redemptions` |
| E2E | `domains.e2e.ts`: "sees the redemption penalty, orders the recovery, and is sent to the invoice"; "is told plainly when a namespace cannot be recovered, and offered nothing"; Arabic |

No registrar was selected. No `.sy` behaviour was invented: the `.sy` answer
is `unknown`, and the screen says so.

## F. DNS Zone Import / Export

Commit `20a7186`, inside the `Dns` module.

| | |
|---|---|
| Parser | `ZoneFileParser`: bounded before it is read (bytes, lines, line length, UTF-8, control characters), `$INCLUDE`, `$GENERATE` and unknown directives refused by name, `$ORIGIN` other than the zone refused, SOA and apex NS listed as ignored, every unreadable line refused with its line and reason; IP literals are never rewritten, so an MX at an address is refused by the record rules |
| Plan | `PlanZoneImport` → `ZoneImportPlan` with `add` / `update` / `remove` / `unchanged` / `refused` / `ignored` entries and a fingerprint over the changes and the zone's current records; `applicable` is false while any line is refused |
| Apply | `ApplyZoneImport` recomputes the plan, requires the fingerprint (409 `dns.import.plan_changed` otherwise), refuses whole (409) while a line is refused, then removes, updates and adds through `RemoveRecord`, `ChangeRecord` and `AddRecord` only — each record its own publish, its own state, its own audit row marked `import`; `merge` keeps what the file does not mention, `replace` lists what it removes before it does |
| Ledger | every attempt (applied, refused, plan changed) is a `dns_zone_imports` row written after the refused transaction rolled back; `lynomia_dns_zone_imports_total{outcome}` counts them |
| Export | `ExportZone` writes the authoritative records at their TTLs with no provider or platform identifiers |
| Routes | `POST dns/zones/{zone}/import/plan`, `POST …/import`, `GET …/export`, throttled 20/20/10 per minute; a member who may only look can export and cannot plan or import |
| Audit | `dns.zone.imported`, `dns.zone.exported`, plus `dns.record.created/updated/deleted` per record |
| Tests | `TheWholeLifeOfAZoneImportTest` (9), `ZoneFileParserTest`, and the parser and fingerprint cases in S |
| Screen | `/dns` import/export card: file or pasted text (262 144 bytes at most), mode, preview table with kind badges and counts, typed-zone-name confirmation, export with copy and download |
| E2E | `dns-import.e2e.ts` (4, one Arabic) |

## G. File-Level Backup Restore

Commit `8d503c2`, inside the `Backups` module.

| | |
|---|---|
| Contract | `FileLevelBackupProvider` — optional beside `BackupProvider`, the `WordPressInstaller` pattern: `listFiles`, `readFile`, `startFileRestore`; `FileLevelSupport::describe()` says on every backup row whether the provider can open archives and why not |
| Providers | `FakeBackupProvider` implements it over a fixed tree (a nested directory, files, a symlink, a device, a 4 GiB archive, and markers for a restore that times out, is refused, or fails after starting); `ProxmoxBackupProvider` **does not**: PBS's file-restore API has never been called from this platform against a real backup server, and the provider's docblock says so |
| Path model | `BackupPath::of()` refuses `..`, `.`, empty segments, backslashes, control characters, bad encoding, a segment over 255, depth over 64, length over 4 096, and anything not starting at `/`, before any provider is asked; no response names a datastore, a node or the provider's handle on the archive |
| Symlinks | listed as links, never followed: not browsed, not downloaded, not restored, and one among good paths refuses the whole request |
| Download | `IssueBackupFileDownload` → 64-hex token in the URL once, sha256 in `backup_file_downloads`, 300 s, the account's own, needs the session; `ServeBackupFileDownload` spends it in one conditional update and streams octet-stream with nosniff and a sandboxing CSP; audit `backup.file.downloaded` |
| Restore | `RestoreBackupFiles` clears the whole-machine bar (hostname typed exactly, completed backup of this machine, active service, nothing else restoring into the machine), at most 50 paths; `backup_file_restores` row with `FileRestoreState`; `ReconcileFileRestores` polls on the `backups:reconcile` schedule; timeout → `needs_review`, never retried, blocks further restores until settled; audit `backup.files.restored` |
| Notifications | `service.file_restore_completed/failed/needs_review` |
| Observability | `lynomia_backup_file_restores_total{state}`; alert `FileRestoreIndeterminate` → `docs/runbooks/file-restore-indeterminate.md` |
| Routes | `GET vps/{vm}/backups/{backup}/files`, `POST …/files/downloads` (30/min), `POST …/files/restore` (5/min), `GET …/file-restores`, `GET backups/downloads/{token}` (64 hex only) |
| Tests | `TheWholeLifeOfAFileRestoreTest` (12) |
| Screen | `/backups` file browser: breadcrumb path, tick files and folders, download opens a new window (the link is never printed), typed-hostname confirmation, restores as rows |
| E2E | `backup-files.e2e.ts` (4, one Arabic) |

## H. Country / Currency Workflow

Commit `d528255`, inside `Identity` for the request and `Admin` for the queue.
The policy is written in `docs/billing.md`, "Changing an account's country or
currency".

| | |
|---|---|
| Rule | nothing already written is ever converted: an invoice keeps its currency and its tax, a subscription keeps the currency it was sold in, credit is not exchanged |
| Analysis | `AnalyseCountryCurrencyChange` → `CountryCurrencyImpact` (facts, blockers, warnings): a currency change is blocked by an open invoice, an order in flight, a domain operation in flight or indeterminate, a live subscription, a wallet balance, or a catalogue that prices nothing in the new currency; a country-only change is not blocked and states the tax before and after via `TaxResolver` |
| States | `CountryCurrencyChangeState`: `requested`, `blocked`, `awaiting_approval`, `scheduled`, `applied`, `needs_review`, `rejected`, `withdrawn`; one open request per account |
| Decision | `DecideCountryCurrencyChange` re-analyses, refuses past a blocker, applies now or schedules for `apply_at` (in the future only); `ApplyDueCountryCurrencyChanges` every five minutes; `ApplyCountryCurrencyChange` checks once more under a row lock and holds the change in `needs_review` if a blocker appeared |
| The only writer | `ApplyCountryCurrencyChange` is the only place `customers.country` and `customers.currency` are written after registration; `PATCH /me` ignores both fields (proved in S) |
| Routes | customer `GET|POST account/country-currency-changes`, `POST …/{change}/reanalyse`, `POST …/{change}/withdraw` (`customer.manage`, throttled 5 and 10 per minute); operator `GET admin/customers/country-currency-changes` (`customer.view_any`), `POST …/{change}/approve|reject` (`customer.update`) |
| Audit | `account.country_currency_change.requested/withdrawn/approved/rejected/applied/blocked` |
| Notifications | `billing.country_currency_change_applied/rejected/needs_review`, emailed |
| Observability | `lynomia_country_currency_changes_total{state}`; alert `CurrencyChangeNeedsReview` → `docs/runbooks/currency-change-needs-review.md` |
| Tests | `TheWholeLifeOfACountryCurrencyChangeTest` (6) |
| Screens | the dashboard's "Country and currency" section (`billed-in`, request, blockers and warnings, check again, withdraw); `/admin/account-changes` queue with approve and reject |
| E2E | `account-changes.e2e.ts` (2, one Arabic): customer and operator in two sessions, KW → SA → KW, the open invoice untouched, the inbox left as found |

## I. WordPress Staging / Cloning

Commits `67355e7` and, for one refusal, `24fddff`.

| | |
|---|---|
| Contract | `WordPressStagingProvider` — optional beside `WordPressInstaller`: `copyWordPress`, `pushWordPressToProduction`; `WordPressCopySupport::describe()` says on the site row what its panel can do and why not |
| Providers | `FakeHostingProvider` implements it with markers for a copy or push that is refused or never answers; **no cPanel or DirectAdmin implementation**: no toolkit endpoint has been called from this platform against a real node, and the customer's live site is the wrong place to find out what a push answers |
| Model | `wordpress_sites.kind` (`production`, `staging`, `clone`) and `parent_site_id`; `wordpress_site_operations` (`create_staging`, `clone`, `push_to_production` × `requested`, `running`, `succeeded`, `failed`, `indeterminate`) |
| Copy | `CopyWordPressSite::staging()` at `staging.<domain>` on the same account, `clone()` at a typed domain; a name any account already serves is refused 409 `wordpress.domain_in_use` for both (the staging case was a 500 at the unique index until `24fddff`); one operation in flight per site |
| Push | `PushWordPressToProduction::impact()` says what is overwritten, that a database push loses every post and order since the copy, and that the platform holds no backup of a shared-hosting site; `execute()` needs the production domain typed exactly (422 `wordpress.push_confirmation_mismatch`), only a staging copy can be pushed, and a copy whose production site is gone or belongs elsewhere is 409 `wordpress.production_gone` |
| Jobs | `ProvisioningJobKind::CopyWordPressSite`, `PushWordPressToProduction` with handlers and 1 200 s timeouts; an unanswered copy leaves both rows indeterminate; an unanswered push leaves production `needs_review`, the customer told not to push again, nothing retrying |
| Audit | `wordpress.staging.requested`, `wordpress.clone.requested`, `wordpress.push.requested` |
| Notifications | `service.wordpress_push_completed/failed/needs_review` |
| Observability | `lynomia_wordpress_site_operations_total{kind,state}`; alert `WordPressPushIndeterminate` → `docs/runbooks/wordpress-push-indeterminate.md` |
| Routes | `POST wordpress/sites/{site}/staging`, `POST …/clones`, `GET …/push/impact`, `POST …/push` (3/min), `GET …/operations` |
| Tests | `TheWholeLifeOfAWordPressCopyTest` (6) |
| Screen | `/wordpress` copies card: kind badge, staging / clone / push per what the provider reports, impact then a confirmation with scope and warnings, operations as rows |
| E2E | `wordpress-copies.e2e.ts` (3, one Arabic) |

## J. CDN Product Readiness

`Product::Cdn`, prepared. Category `cdn` with the capabilities `enable`,
`disable`, `purge_all`, `purge_urls`, `cache_rules`, `development_mode`,
`tls_status`, each a method of `Cdn\Domain\Contracts\CdnProvider`
(`CdnZoneStatus`, `CacheRule`). Depends on DNS. No adapter, no catalogue
driver, no class implementing the seat. Today: `not_ready`,
`blocked_dependency` on DNS; with DNS ready it would stop at
`ready_for_real_validation` with `not_implemented`. No Cloudflare feature
was invented: none is claimed.

## K. Object Storage Readiness

`Product::ObjectStorage`, prepared. Category `object_storage` with
`create_bucket`, `delete_bucket`, `list_buckets`, `quota`, `usage`,
`issue_access_key`, `revoke_access_key`, `endpoint`, `versioning`,
`lifecycle`, each a method of `ObjectStorageProvider` (`Bucket`,
`BucketUsage`, `LifecycleRule`, `IssuedAccessKey` whose secret is redacted
from `__debugInfo` and returned once). No cluster, no S3 API, no adapter.
Today: `not_ready`, `blocked_dependency` — no object storage provider is
registered and none can be, because no driver exists.

## L. GPU Compute Readiness

`Product::GpuCompute`, prepared, hardware-blocked. Requires the VPS compute
set plus `gpu_passthrough` and reverse DNS. The device model of section D
is the whole of it: no scheduler, no inventory invented. Today:
`not_ready`, `blocked_hardware` — no GPU is recorded on a machine the
platform may configure. The seeded estate records none.

## M. Email Hosting Readiness

`Product::EmailHosting`, prepared. Category `email_hosting` with the
sixteen mailbox, domain and DKIM capabilities, each a method of
`EmailHostingProvider` (`MailDomain`, `Mailbox`, `MailboxUsage`,
`DkimRecord`; a mailbox password travels in and never out). Depends on
DNS. No mail server, no adapter. Today: `not_ready`, `blocked_dependency`
on DNS.

## N. Transactional Email Readiness

Not a product: the shared `email` requirement every product carries.
`TransactionalEmailProvider` (`name()`, `send()`) is implemented by
`LaravelMailTransport`, the framework's mailer, which `EmailChannel` now
sends through; the `smtp` driver is that transport wearing a catalogue
entry (`needs_endpoint: false` — a relay is a deployment setting and speaks
SMTP, which the Control Center never dials; `needs_credential: true`;
`testable: false`). A row registered for it stays `not_ready` with
`blocked_credentials` and `can_test: false`; a connection test answers 422
`unknown_driver`. Readiness can now say "an email provider is registered and
nobody has proven it", which is a different answer from "there is no email
provider". `TheSmtpDriverIsCataloguedButNeverProvenTest` (2).

## O. Kubernetes Readiness Only

`Product::ManagedKubernetes`, `readiness_only`. Requires `cluster_lifecycle`,
`load_balancer`, `certificates` and `monitoring` capabilities and depends on
VPS, DNS, backups and object storage. No contract, no adapter, no cluster.
It is visible on `/readiness` with its answers and can never become a
product by declaration: `kubernetes_is_visible_in_readiness_and_can_never_become_a_product_by_declaration`.

## P. Requirement changes

C.3 lists the sixteen corrections. Beyond them, the addendum introduced
**optional** capabilities — ones a product uses only where the provider
reports them, whose absence never blocks a rung: backup `file_browse`,
`file_restore`; wordpress_installer `staging`, `clone`, `push_to_production`;
registrar `redemption`. Every row is in `docs/product-readiness-matrix.md`.

## Q. Capability changes

| Category | Change |
| --- | --- |
| `compute` | `gpu_passthrough` added (consumer: GPU compute readiness) |
| `backup` | `file_browse`, `file_restore` (optional; consumer: `FileLevelSupport`) |
| `wordpress_installer` | `staging`, `clone`, `push_to_production` (optional; consumer: `WordPressCopySupport`) |
| `registrar` | `redemption` (consumer: `OrderDomainRedemption`) |
| `cdn`, `object_storage`, `email_hosting` | new categories, one method per capability on the contract |
| `email` | `tls`, `sender_identity` removed — nothing asked |
| `monitoring` | `logs` removed — nothing asked |

`EveryDeclaredCapabilityHasAConsumerTest` ties every declared capability,
required or optional, to a consumer; `EveryPreparedCategoryHasAContractTest`
ties the three prepared categories to their contracts in both directions.

## R. Provider catalogue changes

One entry: `smtp` (category Email), as in N. No CDN, object storage, email
hosting, GPU or Kubernetes driver was added; the ADAPTERS map in
`TheCatalogueOnlyClaimsWhatExistsTest` is exact, and the same gate refuses any
class that implements a prepared seat while the addendum records no adapter.

## S. Security

One adversarial suite across the new surfaces,
`tests/Feature/Security/AttackingWhatTheAddendumBuiltTest.php` (5 tests,
every case writes nothing), beside the adversarial cases inside each
whole-life suite. What was tried and what answered:

| Surface | Tried | Answer |
| --- | --- | --- |
| Zone import | 5 001 lines; 270 000 bytes; a 4 100-character line; a NUL mid-line; an escape sequence; invalid UTF-8 | 413/422 before the parser reads a record (`validation.failed` for the size rule, `dns.zone_file.*` for the rest) |
| Zone import | 300 records into a zone with a ceiling below that; `$INCLUDE /etc/passwd`; `$GENERATE`; `$ORIGIN evil.test.` | plan not applicable, each reason named, zero records written |
| Zone import | another account's zone for plan, apply and export; a fingerprint from a different file; a forged fingerprint; a viewer previewing | 404, 409 `dns.import.plan_changed`, 409, 403 |
| Download links | 63 characters; upper case; a trailing slash; `%00`; `../` | 404 for each — the route pattern admits 64 lower hex only |
| Country / currency | `PATCH /me` carrying `currency` and `country` | both ignored; the columns are unchanged |
| Country / currency | approving, rejecting and withdrawing an applied change; `apply_at` in the past | 409, 409, 409, 422; another account's queue is empty |
| WordPress | a staging copy re-parented onto another account's production site: impact and push | 409 `wordpress.production_gone`; no operation written; the other site untouched |
| WordPress | a clone onto a name another account serves; a staging request whose name is already served | 409 `wordpress.domain_in_use` (the second was a 500 until `24fddff`) |
| WordPress | the staging domain typed as the push confirmation | 422 `wordpress.push_confirmation_mismatch` |
| Backups | the traversal family, symlinks, another tenant's backup, a spent or foreign token | in `TheWholeLifeOfAFileRestoreTest` |
| Redemption | another customer's name, a quote for another name, an expired quote, a replayed settlement | in `TheWholeLifeOfADomainRedemptionTest` |
| Readiness | a controlled provider on a prepared product; a declaration on a prepared product | `ready_for_test` at most; refused by name |

Every new mutating route is throttled (`EveryAuthenticatedRouteIsThrottledTest`
still passes). No new surface dials anything: the zone import writes records
through the zone's provider one at a time, the file browser asks the backup
provider, the copy asks the hosting provider, the `smtp` entry has no
endpoint. Nothing in Git or in any log carries a password, a token, an auth
code, registrant PII or a WordPress credential; the object-storage secret is
redacted from debug output by construction.

## T. RBAC

No permission was added. `git diff 1035e45..HEAD -- src/Modules/Rbac` is
empty. Every new route hangs on a permission that already existed:

| Route | Permission |
| --- | --- |
| zone import plan / apply, file browse / download / restore, WordPress staging / clone / push, redemption order | the service-manage role the customer already needs for the record, backup, site or domain it acts on; a member who may only look can export a zone and nothing else |
| `account/country-currency-changes` | `customer.manage` |
| `admin/customers/country-currency-changes` | `customer.view_any`; approve / reject `customer.update` |
| `infrastructure/servers/{server}/gpus` | `infrastructure.view`; record `infrastructure.manage` |
| readiness answers | `infrastructure.view`, as the ladder |

`only_the_accounts_managers_may_ask_and_only_an_operator_with_customer_update_may_decide`
and `the_request_is_validated_and_recording_needs_the_manage_permission` prove
the two operator surfaces.

## U. Audit

Every case below landed in the same commit as its writer, and
`EveryAuditActionIsRecordedSomewhereTest` holds.

| Action | Writer |
| --- | --- |
| `infrastructure.gpu.registered` | `ServerController::registerGpu` |
| `domain.redemption.ordered` | `DomainController::redeem` |
| `dns.zone.imported`, `dns.zone.exported` | `DnsZoneTransferController`; per record `dns.record.created/updated/deleted` with `import: true` from `ApplyZoneImport` |
| `backup.files.restored`, `backup.file.downloaded` | `BackupFileController::restore`, `::fetch` |
| `account.country_currency_change.requested/withdrawn` | `Identity\…\CountryCurrencyChangeController` |
| `account.country_currency_change.approved/rejected` | `Admin\…\CountryCurrencyChangeController` |
| `account.country_currency_change.applied/blocked` | `ApplyCountryCurrencyChange` |
| `wordpress.staging.requested`, `wordpress.clone.requested`, `wordpress.push.requested` | `WordPressCopyController` |

## V. Observability

| Series | Labels | Collector |
| --- | --- | --- |
| `lynomia_domain_redemptions_total` | `state` | `ProductCollector` |
| `lynomia_dns_zone_imports_total` | `outcome` | `DnsCollector` |
| `lynomia_backup_file_restores_total` | `state` | `ProductCollector` |
| `lynomia_country_currency_changes_total` | `state` | `ProductCollector` |
| `lynomia_wordpress_site_operations_total` | `kind`, `state` | `ProductCollector` |

Every label set is an enum; no domain, customer, bucket, email, path, server
or credential appears (`MetricsCarryNoIdentifiersTest`). The metrics
endpoint's query budget is 42, raised by exactly the queries the five series
cost (`MetricsQueryBudgetTest`).

| Alert | Group | Runbook |
| --- | --- | --- |
| `DomainRedemptionNeedsReview` | `business.domains` | `docs/runbooks/domain-redemption-indeterminate.md` |
| `FileRestoreIndeterminate` | `business.backups` | `docs/runbooks/file-restore-indeterminate.md` |
| `CurrencyChangeNeedsReview` | `business.accounts` | `docs/runbooks/currency-change-needs-review.md` |
| `WordPressPushIndeterminate` | `business.wordpress` | `docs/runbooks/wordpress-push-indeterminate.md` |

`EveryAlertNamesARunbookThatExistsTest` requires every named runbook to exist
and every new alert to name one; the eighteen node, platform, probe and two
business alerts that predate the convention are listed in the gate so that
the list can only shrink. The infrastructure validators
(`validate-monitoring.py`, `validate-runbooks.py`) pass in CI on every green
run of AC.

## W. Notifications

Ten types, each with a real writer, English and Arabic strings, and the
emailed list where the customer must be reached:

| Type | Writer |
| --- | --- |
| `service.domain_redemption_failed` | the redemption job on refusal |
| `service.file_restore_completed/failed/needs_review` | `ReconcileFileRestores` |
| `billing.country_currency_change_applied/rejected/needs_review` | `ApplyCountryCurrencyChange`, `DecideCountryCurrencyChange` |
| `service.wordpress_push_completed/failed/needs_review` | `PushWordPressToProductionHandler` |

No notification exists for a flow that does not: a copy that succeeds is a
row on the screen, and a zone import is an act the customer just performed.
`NotificationsCannotBeTurnedAgainstACustomerTest` still holds.

## X. Frontend

| Screen | What was added |
| --- | --- |
| `/readiness` | five more products, software-state badge, the ten answers per product, blocker links |
| `/machines` | GPU list and record form per machine |
| `/domains` | redemption answer per name (recoverable with the penalty, or the reason it is not) and the recovery order |
| `/dns` | `ZoneTransfer` card: import (file or text, mode, preview, typed confirmation) and export (copy, download) |
| `/backups` | `BackupFileBrowser`: breadcrumb path, selection, download, typed-hostname restore, restore rows; the Files button carries the provider's reason when disabled |
| `/` (dashboard) | `CountryCurrencySection`: billed-in, request form, impact, check again, withdraw |
| `/admin/account-changes` | the operator queue with approve (note, optional moment) and reject |
| `/wordpress` | `SiteCopies`: kind badge, staging / clone / push, impact dialogue, operations rows |

Every string is in `en` and `ar` under `EveryStateAScreenShowsIsTranslatedTest`,
which gained the new states, kinds, modes and scopes. In Arabic the layout
mirrors and the things that must not — currency codes, domain names, zone
text, file paths, hostnames — are pinned left-to-right, each asserted by an
Arabic browser test. Component tests cover the zone transfer card (7), the
file browser, the country/currency section, the copies card and the admin
queue; `npm run typecheck` and `npm run lint` are clean.

## Y. Browser E2E

New specs, all on the seeded fixtures, all in the full suite:

| Spec | Tests |
| --- | --- |
| `control-center-readiness.e2e.ts` | prepared products as prepared, answered and not for sale; Arabic |
| `control-center-machines.e2e.ts` | records a GPU without touching the machine; Arabic |
| `domains.e2e.ts` | sees the penalty, orders the recovery, is sent to the invoice; is told plainly when a namespace cannot be recovered; Arabic |
| `dns-import.e2e.ts` | preview as a diff, apply through the same checks, export without identifiers; one refused line refuses the file; replace says what it removes, merge never removes; Arabic |
| `backup-files.e2e.ts` | browse folder by folder with a symlink offered nothing; download opens a new window and the link is never printed; restore only after the hostname is typed; Arabic |
| `account-changes.e2e.ts` | customer asks, operator approves, applied without touching anything issued, and back; Arabic |
| `wordpress-copies.e2e.ts` | staging made, shown as its own site, pushed back only with the domain typed; a production site offers copies and not a push, a site with no panel offers the reason; Arabic |

The full suite in the working copy at `8d16255`: **148 passed, 2 failed** of
150 in 10.3 min — the two failures were two tests of
`control-center-credentials.e2e.ts`, both at the sign-in step, both a 10 s
wait for the welcome heading, while the clean room's `composer install` was
running on the same machine. The spec alone, immediately after: 5 passed in
20 s. The same 150 passed in the clean room under no other load (AB) and in
CI (AC, run 126), so the two are recorded as load on this machine, not as a
defect, and nothing was changed for them.

## Z. Whole-life tests

| Suite | Tests |
| --- | --- |
| `PreparedProductsCannotBeSoldOnHopeTest` | 9 |
| `AGpuIsRecordedAndCountedOnlyWhereItMayBeUsedTest` | 3 |
| `TheWholeLifeOfADomainRedemptionTest` | 11 |
| `TheWholeLifeOfAZoneImportTest` | 9 |
| `TheWholeLifeOfAFileRestoreTest` | 12 |
| `TheWholeLifeOfACountryCurrencyChangeTest` | 6 |
| `TheWholeLifeOfAWordPressCopyTest` | 6 |
| `TheSmtpDriverIsCataloguedButNeverProvenTest` | 2 |
| `AttackingWhatTheAddendumBuiltTest` | 5 |

Each walks model → authorization → API → real application path → job or
handler → provider → surface → audit → observable state → failure state, and
each has a timeout case where a provider is involved. The backend suite is
**2 799** tests in the working copy (2 708 at the baseline).

## AA. Architecture gates

No gate was weakened. Three were added and four extended:

| Gate | Change |
| --- | --- |
| `EveryDeclaredCapabilityHasAConsumerTest` | new — every category capability, required or optional, has a consumer, and every consumer's call is declared (the addendum's *EveryProductRequirementHasAConsumer* and *EveryOptionalProviderCapabilityHasACaller*) |
| `EveryPreparedCategoryHasAContractTest` | new — capabilities ↔ contract methods both ways for `cdn`, `object_storage`, `email_hosting`, `email`; no class may implement a prepared seat (with `the_sweep_examines_every_product_including_the_prepared_ones`, the addendum's *EveryPreparedProductHasReadiness*) |
| `EveryAlertNamesARunbookThatExistsTest` | new — V |
| `NothingIsSellableOnHopeTest` / `PreparedProductsCannotBeSoldOnHopeTest` | the addendum's *NoControlledProviderCanSellAProduct*: a controlled provider carries a product to `ready_for_test` and no further, prepared or not |
| `TheCatalogueOnlyClaimsWhatExistsTest` | `smtp` in the ADAPTERS map |
| `EveryStateAScreenShowsIsTranslatedTest` | the new state, kind, mode and scope enums |
| `MetricsQueryBudgetTest` | 42 |
| `OpenApiSpecificationTest` | 234 operations, spec regenerated and committed on every slice |

`LayeringTest`, `NoDeadMethodsTest`, `NoDeadCapabilitiesTest`,
`EveryAuditActionIsRecordedSomewhereTest`, `ANewRecordKnowsItsOwnStateTest`,
`TheModulesAreNamedForWhatTheyOwnTest` and `MetricsCarryNoIdentifiersTest`
are unchanged and green.

## AB. Clean Room

Run from `git clone --branch claude/hv-t6hq1p` of the repository at
`8d16255` into a directory of its own — no reuse of the working copy, no
existing database, no untracked env file, no manual keys, no hidden
fixtures. The commits after `8d16255` are this report and one link line.

| Step | Command | Result |
| --- | --- | --- |
| PHP dependencies | `composer install --prefer-dist` | OK |
| Keys | `php artisan key:generate` for `.env` and `--env=testing` | OK |
| Node dependencies | `npm ci` at the workspace root, once | OK |
| Databases | `lynomia_cradd`, `_test`, `_e2e` | created empty by `psql` |
| Migrations | `php artisan migrate --force` | 54 migrations from an empty database, the addendum's four among them |
| Seed | `php artisan db:seed --force` | roles and permissions, the software catalogue, products, plans, prices, nodes, addresses, servers — the development seed, 1.0 s |
| Backend suite | `php vendor/bin/phpunit` | **2 799 passed, 125 608 assertions**, 358 s — the working copy's and CI's number exactly |
| Style | `vendor/bin/pint --test` on the whole tree | passed |
| Static analysis | `composer install --working-dir=tools/phpstan` | **not run**: the install fails with `Could not authenticate against github.com` (exit 100), as in every previous clean room here — the proxy will not serve two packages as dist. PHPStan passes with 0 errors in the working copy and in CI's Static analysis job on every green run in AC. The gap is in this environment's network, not in the repository. |
| Typecheck | `npm run typecheck` | OK |
| Lint | `npm run lint` | OK |
| Frontend unit | `npx vitest run` | 91 passed in 20 files |
| Production build | `npm run build` | OK (two chunk-size notes from the bundler) |
| OpenAPI | `php artisan openapi:generate` then `git diff --exit-code docs/openapi.yaml` | up to date: 234 operations, no diff |
| OpenAPI validation | `npm run openapi:lint` | valid, 4 warnings |
| Scheduler | `php artisan schedule:list` | 24 commands registered, `customers:apply-country-currency-changes` among them |
| Queue worker | `php artisan queue:work redis --stop-when-empty` | connected to Redis, found the default queue empty (the development seed queues nothing), exited 0 with "Worker STOPPED Queue empty" |
| Infrastructure validators | `validate-inventory.py`, `test_validate_inventory.py`, `test_safety_gate.sh`, `validate-monitoring.py`, `validate-runbooks.py`, `check-ci-cannot-apply.py` | all pass: 12 hosts ok; 15/15; 10/10; 7 rule files, 73 rules over 54 exported and 7 declared series; 118 files, 62 artisan invocations all defined; 38 CI steps, none applies |
| Architecture and security gates | inside the backend suite above | green |
| Browser suite | `npx playwright test` against a fresh `lynomia_cradd_e2e` | **150 passed** (6.7 min), the 17 addendum tests and every Arabic test among them |

Nothing in the clean room needed a fix. The one thing it could not run is
the one thing it has never been able to run here, and it is named above
rather than omitted.

## AC. CI

Pushed and observed. Every red run is listed with its cause and its fix.

| Run | Commit | Conclusion | Cause / note |
|---|---|---|---|
| 115 | `5669d00` | green | product readiness expansion |
| 116 | `01a1eef` | green | domain redemption |
| 117 | `01a1eef` | green | the same commit on the pull-request trigger |
| 119 | `20a7186` | green | DNS zone import and export |
| 120 | `8d503c2` | green | file-level backup restore |
| 121 | `d528255` | **red** | Browser end-to-end only: `portal.e2e.ts` "the notification inbox shows what the platform has told this account" and "marking a notification read clears it from the unread count" — the new `account-changes.e2e.ts` runs earlier in the suite, applies two country changes, and each tells the customer; the seeded inbox then held three unread notifications where the seeder wrote one. Not seen locally because the spec had only been run on its own. Fixed in `8d16255`. Every other job green. |
| 122 | `67355e7` | **red** | the same two failures, same cause; every other job green |
| 123 | `5844e37` | **red** | the same two failures, same cause; every other job green |
| 124 | `24fddff` | cancelled | superseded by the push of `8d16255` eight minutes later; the branch's concurrency group cancels the older run |
| 125 | `8d16255` | cancelled | superseded by the push of `09cbc23` |
| 126 | `09cbc23` | green | `8d16255` (the last commit of code) plus the report draft; all nine jobs green, 150 browser tests |
| 127 | `9e86429` | green | the link line in the closed report; documents only |
| 128 | `82e9c76` | green | this report's closure; documents only; all nine jobs green |
| 129 | this commit | not recorded | rows 127 and 128 above; documents only |

No red run was re-run into green: each of 121 to 123 failed on the same
defect, fixed once in `8d16255`.

## AD. Updated Infrastructure Capability Matrix

`docs/infrastructure-control-capability-matrix.md` gained rows for the
prepared products and the cap, the twelve readiness answers, the GPU record,
the three prepared seats and the `smtp` entry, and the five series and four
alerts. No row is `REAL_INFRA_VERIFIED`.

## AE. Updated Product Readiness Matrix

`docs/product-readiness-matrix.md` records the twelve products, the
corrected requirement rows, the optional capabilities, the software cap, and
where each product stands:

| Product | State | First blocker |
| --- | --- | --- |
| VPS, Dedicated, Shared hosting, WordPress, Domains, DNS, Backups | `not_ready` | as the closure report: no real provider is ready |
| CDN, Email hosting | `not_ready` | `blocked_dependency` on DNS; then the cap |
| Object storage | `not_ready` | `blocked_dependency`: no provider and no driver |
| GPU compute | `not_ready` | `blocked_hardware` |
| Managed Kubernetes | `not_ready` | `blocked_dependency` on four products; then the cap, which nothing lifts |

`docs/customer-capability-matrix.md` gained the customer-side rows for the
zone import and export, the file browser, download and restore, the
country/currency request and decision, and the WordPress copies and push,
each with its real-provider column stating what is not implemented.

## AF. Remaining External Blockers

| | |
|---|---|
| Proxmox | BLOCKED_HARDWARE / BLOCKED_CREDENTIALS |
| PBS (and file-level restore against it) | BLOCKED_HARDWARE |
| cPanel, DirectAdmin | BLOCKED_HARDWARE / BLOCKED_LICENSE |
| WordPress Toolkit (staging, cloning, push) | BLOCKED_LICENSE / BLOCKED_PROVIDER |
| Cloudflare | BLOCKED_CREDENTIALS / BLOCKED_NETWORK |
| Registrar (and redemption) | BLOCKED_PROVIDER / BLOCKED_CREDENTIALS |
| `.sy` | BLOCKED_LICENSE — redemption `unknown` |
| Payment | BLOCKED_CREDENTIALS |
| Transactional email (`smtp`) | BLOCKED_PROVIDER / BLOCKED_CREDENTIALS |
| Object storage | BLOCKED_HARDWARE / BLOCKED_PROVIDER |
| GPU | BLOCKED_HARDWARE |
| Email hosting | BLOCKED_PROVIDER / BLOCKED_HARDWARE |
| Kubernetes | readiness only |

None was removed to make a screen look complete. No real access appeared
during the addendum; nothing from Phase 30B's real validation was mixed in.

## AG. REAL_INFRA_VERIFIED status

**NONE.** No real endpoint was dialled, no real provider was
connection-tested, no machine was deployed to, and no adapter for any
prepared product exists. Every provider row and every product row reports
this.

## AH. READY_TO_SELL status

**NONE.** All twelve products are `not_ready` (AE). In production, every new
sale of any of them is refused at the four actions that create one; nothing
prepared can be declared sellable at all.

## AI. Closing Questions

| # | Question | Answer | Evidence |
| --- | --- | --- | --- |
| 1 | Can Lynomia explain why each old and newly prepared product is or is not ready? | **YES** | `GET readiness/products` carries the verdict, the first blocker in dependency order and the ten answers for all twelve; `every_prepared_product_is_on_the_screen_with_its_software_state_and_its_answers`; `control-center-readiness.e2e.ts` |
| 2 | Can a prepared-only product ever become READY_TO_SELL merely because its software model exists? | **NO** | the cap at `ready_for_real_validation` with `not_implemented`; `a_prepared_product_on_real_live_providers_is_refused_a_declaration_because_of_its_software` |
| 3 | Can Domain Redemption reach payment and provider orchestration without the client supplying the price? | **YES** | the price comes from `domain_tlds`; `the_price_is_the_quotes_and_a_quote_for_another_name_is_refused`; `a_namespace_whose_penalty_or_windows_are_unknown_refuses_with_the_reason_and_never_quotes` |
| 4 | Can a registrar timeout during redemption create a duplicate redemption attempt? | **NO** | one attempt in flight; `a_registrar_that_never_answered_leaves_the_name_indeterminate_and_reconciliation_settles_it`; `a_settlement_that_arrives_twice_recovers_the_name_once` |
| 5 | Can a DNS zone be imported with a preview/diff and without bypassing DNS validation? | **YES** | the plan; every write through `AddRecord` / `ChangeRecord` / `RemoveRecord`; `a_cname_that_would_stand_beside_another_record_is_refused_on_the_zone_as_it_would_be` |
| 6 | Can a malicious zone file alter records outside the customer's zone? | **NO** | `$ORIGIN` other than the zone refused; names outside the zone refused; `a_file_cannot_write_outside_the_zone_or_point_at_somebody_elses_platform_address`; another account's zone is 404; S |
| 7 | Can a customer browse or restore only files belonging to their own backup? | **YES** | `another_tenants_backup_files_are_not_found_rather_than_forbidden`; the download token is scoped to the account and the session |
| 8 | Can a path traversal or symlink escape reach another filesystem location? | **NO** | `BackupPath::of()` refuses the family before any provider is asked; `the_traversal_family_is_refused_by_name_before_any_provider_is_asked`; `a_symlink_is_never_followed_browsed_downloaded_or_restored` |
| 9 | Can country/currency change mutate historical financial records? | **NO** | `ApplyCountryCurrencyChange` writes two customer columns and nothing else; `…_approved_and_applied_without_converting_anything`; the E2E finds `INV-E2E-0001` as it was |
| 10 | Can an active subscription be silently repriced into another currency? | **NO** | a live subscription blocks a currency change (`blocked` on the request, refused at approval, `needs_review` at apply); `a_scheduled_change_is_applied_by_the_sweep_after_checking_again_and_held_when_a_blocker_appeared` |
| 11 | Can WordPress staging capabilities differ honestly between toolkit providers? | **YES** | the optional interface; `WordPressCopySupport::describe()`; `a_site_without_a_panel_that_can_copy_says_so_on_the_row_and_refuses_every_copy_route`; the fake implements it and the real panels do not |
| 12 | Can push-to-production happen without explicit impact/confirmation controls? | **NO** | `GET …/push/impact` then the production domain typed exactly; `wordpress.push_confirmation_mismatch`; S |
| 13 | Can CDN appear as prepared while still refusing real sale without a provider? | **YES** | J; `AssertProductMaySell` in production; `in_production_a_new_order_is_refused_until_the_product_is_ready_to_sell` |
| 14 | Can Object Storage exist in readiness without pretending an S3 cluster exists? | **YES** | K: a contract and a product row, no driver, `blocked_dependency` with the reason "no object storage provider registered" |
| 15 | Can GPU Compute remain blocked until actual GPU capacity exists? | **YES** | `blocked_hardware` until a device is recorded on a machine the platform may configure; `a_card_counts_as_capacity_only_on_a_machine_classified_to_allow_configuration` |
| 16 | Are Email Hosting and Transactional Email separate concerns? | **YES** | M and N: `EmailHostingProvider` (mailboxes, a product) versus `TransactionalEmailProvider` (the platform's own mail, a shared requirement); different categories, different contracts, different gates |
| 17 | Can Kubernetes remain visible in readiness without accidentally becoming a sellable product? | **YES** | O; `kubernetes_is_visible_in_readiness_and_can_never_become_a_product_by_declaration` |
| 18 | Can any controlled/test provider satisfy real-production readiness? | **NO** | `a_controlled_provider_carries_a_product_to_ready_for_test_and_no_further`; `a_controlled_provider_cannot_make_a_prepared_product_look_readier_than_ready_for_test`; `ProductionGuardTest` |
| 19 | Did any new method, capability, audit enum, API endpoint or UI control end without a caller at the far end? | **NO** | `NoDeadMethodsTest`, `NoDeadCapabilitiesTest`, `EveryAuditActionIsRecordedSomewhereTest`, `EveryDeclaredCapabilityHasAConsumerTest`, `OpenApiSpecificationTest` (every operation a route, every route an operation) all green; every screen control is exercised by a component or browser test in X and Y; `ZoneImportPlan::hasChanges()` was the one method written without a caller during the addendum and was deleted before its slice was pushed |

## AJ. Verdict

```text
Original Phase 30B-P
Status: CLOSED
Unchanged

Phase 30B-P Scope Addendum
Status: CLOSED

Software:
Every item of the addendum's applicable software scope is complete to the
closure standard: the product readiness expansion (five products, the cap,
the sale guard, the answers, the GPU record), domain redemption, DNS zone
import and export, file-level backup restore, the country/currency workflow,
WordPress staging, cloning and push, the CDN, object storage and email
hosting contracts, transactional email as a catalogued contract, Kubernetes
as readiness only; requirements, capabilities, blocker propagation and the
ready-to-sell guards extended; RBAC on existing permissions; audit,
security, observability, notifications; frontend in English and Arabic with
RTL and LTR; browser E2E; architecture gates; whole-life tests; both
matrices and the customer matrix; this report.

Runtime:
Backend 2 799 tests, frontend unit 91, browser 150,
PHPStan 0 locally and in CI, Pint clean, OpenAPI 234 operations in step,
scheduler and a real Redis worker running, in the working copy, in CI and
in a fresh clone (AB, AC) — except PHPStan in the clean room, where the
toolchain install is blocked by the proxy, recorded as such in AB.

Real infrastructure:
NONE. No real endpoint was dialled and no real provider was tested during
the addendum.

Products READY_TO_SELL:
NONE. Twelve products, all not_ready (AE); a new sale of any of them is
refused in production.

Remaining external blockers:
AF — Proxmox, PBS, cPanel, DirectAdmin, WordPress Toolkit, Cloudflare, the
registrar and .sy, payment, transactional email, object storage, GPU, email
hosting; Kubernetes is readiness only.
```

This addendum stops here. No 30B-P+, 30A+++ or 30C is opened by it.
