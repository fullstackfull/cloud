# Phase 30B-SIM — Gap 2 of 8

## Provider-specific connection identity testers, and executable protection against false-positive reachability

Baseline commit: `79e1c2179b2b51445ba13c26427dda7e209dc106`
Scope: gap 2 only. Gaps 3 and later — the unified `infra:preflight`, the reference
topology, the naming standard, and the simulator contract-gap assessment — are not
started in this pass and nothing here begins them.

---

## 1. What this gap was, in one sentence

Every driver the catalogue claims support for had an adapter that could provision a
customer's machine, and `src/Modules/Providers/Infrastructure/Testers/` held exactly
one file: the fake.

## 2. Repository truth before any code was written

Read from the repository rather than assumed from the brief. Where the brief's model
and the repository disagreed, the repository decided — twice, and both are recorded
below (§8.1 and §8.2).

| Provider family | Real adapter | Tester before | Identity endpoint available | Auth method | Expected identity evidence | Gap |
|---|---|---|---|---|---|---|
| Compute — Proxmox VE | `ProxmoxComputeProvider` + `ProxmoxConnection` | none | yes — `/api2/json/version`, then `/api2/json/nodes` | `PVEAPIToken=user@realm!name=secret` header | a `data` envelope carrying `version` **and** `release`/`repoid`; a `/nodes` collection | tester missing |
| Backup — via Proxmox VE to a PBS datastore | `ProxmoxBackupProvider` (uses `ProxmoxConnection`) | none | yes — same, then `/api2/json/storage?type=pbs` | same | Proxmox identity, plus at least one `pbs`-type storage | tester missing |
| Hosting — cPanel/WHM | `CpanelHostingProvider` + `WhmConnection` | none | yes — `/json-api/version?api.version=1` | `whm user:token` header | `metadata.command === 'version'` with a `metadata.result` | tester missing |
| Hosting — DirectAdmin | `DirectAdminHostingProvider` + `DirectAdminConnection` | none | weakly — `/CMD_API_SYSTEM_INFO` | HTTP Basic, login key as password | url-encoded pairs, not HTML, carrying a documented field | tester missing |
| DNS — Cloudflare | `CloudflareDnsProvider` + `CloudflareConnection` | none | yes — `/client/v4/user/tokens/verify` | `Authorization: Bearer` | the `success`/`errors`/`messages` envelope, and `result.status` | tester missing |
| Reverse DNS — Cloudflare | `CloudflareReverseDnsProvider` | none | yes — same | same | same | tester missing |
| BMC — Redfish | `RedfishDedicatedProvider` + `BmcConnection` | none | yes — `/redfish/v1/` (unauthenticated per DSP0266) | HTTP Basic | `RedfishVersion` beside an `@odata.id` | tester missing |
| BMC — HPE iLO | `IloDedicatedProvider` | none | yes — same, plus vendor | HTTP Basic | Redfish identity **and** HPE named in `Oem`/`Vendor`/`Manufacturer` | tester missing |
| BMC — IPMI | `IpmiDedicatedProvider` (ipmitool, UDP) | none | yes — `mc info` | RMCP+ session, user and password in argv | `Device ID` and `Firmware Revision` from the specification's own response | tester missing |
| Payment — Stripe | `StripePaymentProvider` (official SDK) | none | yes — `GET /v1/account`, `GET /v1/balance` | secret or restricted key | `object === 'account'` with an `acct_` id; `livemode` matching the row | tester missing |
| Registrar — `.sy` | `SyRegistryProvider` (placeholder; every method refuses) | none | **no** | not documented to this project | none exists | `NOT_IMPLEMENTED` — see §7 |
| Email — SMTP relay | framework mailer | none | **no** — not an endpoint this platform dials | n/a | none exists | untestable — see §7 |
| Payment — MyFatoorah | none (enum entry only) | none | n/a | n/a | n/a | `NOT_IMPLEMENTED`, and not catalogued, so out of scope |

Existing architecture extended rather than duplicated: `ProviderInstance`,
`ProviderCapability`, `ConnectionTest`, `CredentialReference`,
`ControllerEnvironmentSecretResolver`, `ConnectionTesterFactory`, `TestConnection`,
`ConnectionTestController`, `ProviderCatalogue`, `ProviderReadiness`, `EndpointPolicy`,
and the six existing enums. No second provider model, no duplicate Admin screen, no
duplicate credential system, no duplicate readiness engine, no duplicate capability
registry, no duplicate audit system, no second endpoint policy.

## 3. The hazard this gap exists to close

Phase 30B.0 recorded, from this project's own sandbox, that the egress gateway returns
a **trusted** certificate for any SNI — including
`this-host-does-not-exist-9f3a2b.invalid` — with `Verify return code: 0 (ok)`, issued by
`O = Anthropic, CN = Egress Gateway SDS Issuing CA (production)`.

So three things a connection test is most tempted to treat as success are not evidence
that the destination exists at all:

- TCP connect accepted → **not** evidence
- TLS handshake completed → **not** evidence
- certificate verified against the trust store → **not** evidence

This is not a sandbox curiosity. A TLS-inspecting corporate proxy, a captive portal, a
cloud load balancer with no backend, a default vhost on the wrong machine, and a typo
that lands on somebody else's HTTPS service all produce the same three "successes".

Only the application layer discriminates, and only by recognising something the product
itself would say and an impostor would not.

## 4. How the protection was made structural rather than advisory

A comment saying "don't trust a 200" is not protection. The shape of
`HttpIdentityTester` is:

1. **The base class issues the identity request.** A subclass declares the path and
   interprets the `Response`; it does not choose whether to ask. There is no code path
   in which a tester claims an identity without one having been fetched.
2. **`classify()` is unreachable without a matched proof.** The only method a subclass
   can return `Connected` or `ConnectedReadOnly` from is called on exactly one branch,
   and that branch is behind `$proof->matched`. There is no branch in the base class
   where an unproven response becomes a usable state, so there is none in any tester.
3. **Redirects end the test.** `allow_redirects` is false and a 3xx is refused by
   `Probe::get()` rather than interpreted: a `Location` header is chosen by whatever
   answered, so following one both re-sends the credential to a host somebody else named
   and lets that host borrow a real product's response to pass the identity check.
4. **Certificate verification is not configurable.** No constructor flag, no config key,
   no per-row override. A cluster with a private CA is onboarded by adding that CA to the
   controller's trust store. `verify_tls=false` is not normalised as a solution anywhere
   in this gap.
5. **`Probe` has no `post()`.** A connection test never changes anything, which is what
   makes it safe to offer as a button and safe to run against a machine classified
   do-not-touch.
6. **One request builder, `final protected`.** A discovery cannot end up on softer terms
   than the test that preceded it.

`IdentityProof` has exactly three answers, and the third is separate on purpose:

| answer | meaning | resulting state |
|---|---|---|
| `of()` | this is the product, and it accepted the credential | whatever `classify()` establishes |
| `credentialRejected()` | this is the product, and it refused the credential | `AuthFailed` |
| `notThisProduct()` | whatever answered, it is not this product | `IdentityMismatch` |

`AUTH_FAILED` and `IDENTITY_MISMATCH` are not collapsed. The first sends an operator to
rotate a key; the second tells them the endpoint is wrong. Rotating a key against a
captive portal produces a second wrong answer an hour later, and the operator now
distrusts the credential centre as well.

## 5. Two states added to `ConnectionState`

| case | reached? | usable? | blocker | why it is not `AuthFailed` |
|---|---|---|---|---|
| `identity_mismatch` | yes | no | `blocked_configuration` | the credential was never judged, because the platform cannot say what answered |
| `credential_malformed` | no | no | `blocked_credentials` | nothing was dialled, so no endpoint refused anything; the credential is wrong in the credential centre, not at the provider |

Both carry English and Arabic labels and a `danger` tone.
`EveryStateAScreenShowsIsTranslatedTest` is the gate that required them.

## 6. What each tester proves, and what it refuses to claim

Capabilities are `Unknown` by default and that is the honest answer for most of this
platform's capability list, because most of it is writes. Reading a Proxmox node list
proves the platform can read a node list; it proves nothing about whether the token may
create a virtual machine, and a test that created one would not be a test.

| driver | identity proof | authorisation read | `Supported` from a read | left `Unknown` |
|---|---|---|---|---|
| `proxmox` | `data.version` **and** `release`/`repoid`; `/nodes` exists | `/access/permissions` | `create`/`start`/`stop`/`reboot`/`suspend`/`unsuspend`/`console`/`resize` from held privileges, `task_polling` | `reinstall`, `templates`, `gpu_passthrough` |
| `proxmox_backup` | Proxmox identity; `/nodes` exists; `pbs`-type storage attached | `/storage?type=pbs` | — | `create`, `restore`, `delete`, `retention` when a datastore exists |
| `cpanel` | `metadata.command === 'version'` | `/myprivs` | `create_account`, `terminate`, `suspend`, `unsuspend`, `change_package`, `usage` from held ACLs | `sso`, and anything whose ACL key this platform does not recognise |
| `directadmin` | url-encoded, non-HTML, documented field present | `/CMD_API_SHOW_USERS` | `usage` | everything that changes an account |
| `cloudflare` | `success`/`errors`/`messages` envelope, `result.status` active | `/zones?per_page=1` | `reconcile` | `create_zone`, `delete_zone`, `records` |
| `cloudflare_rdns` | same | same | — | `set_ptr`, `clear_ptr` (both writes) |
| `redfish` | `RedfishVersion` + `@odata.id` at `/redfish/v1/` | `/redfish/v1/Systems`, then the first member | `inventory`, `power_state`, `firmware` | `power_control`, `boot_override` |
| `ilo` | Redfish identity **and** HPE named | same | same | same |
| `ipmi` | `Device ID` and `Firmware Revision` from `mc info` | `chassis power status` | `inventory`, `power_state` | `power_control`, `boot_override` |
| `stripe` | `object === 'account'`, `acct_` id, `livemode` matching the row | `GET /v1/balance` | `currencies` when `charges_enabled` | `charge`, `refund`, `webhook` |

### 6.1 Product-specific failures each tester tells apart

- **cPanel and DirectAdmin licences.** An unlicensed node answers with its own licence
  error — cPanel with a web page, DirectAdmin with `error=1` and licence text — and both
  are reported as `LicenceMissing`, not as an outage or a bad credential. Nobody fixes
  this by rotating a token, and an operator sent to do so loses a day. This is what the
  Licence Center exists for.
- **DirectAdmin's login page.** DirectAdmin answers an API request it will not
  authenticate with its own HTML login screen, and that screen carries the product's
  name. So an HTML body naming DirectAdmin is proof of identity *and* proof the
  credential was refused — `AuthFailed`. An HTML body that does not name DirectAdmin is
  `IdentityMismatch`, because the platform does not know whose login page it is looking
  at.
- **Cloudflare's 200 for an expired token.** Cloudflare returns HTTP 200 with
  `success: true` and then reports `result.status` as `expired` or `disabled`. A tester
  reading the status code calls that Connected; one reading `success` agrees. Both are
  wrong, and the customer finds out when a zone stops updating.
- **Proxmox VE versus Proxmox Backup Server.** Both answer `/api2/json/version` with the
  same envelope. Only PVE has `/nodes`. A Backup Server registered as a compute provider
  is reported as a mismatch naming the right driver, rather than onboarded as a cluster
  that will refuse every virtual machine asked of it.
- **A Redfish controller that is not an iLO.** `IloDedicatedProvider` reads firmware from
  `/redfish/v1/Managers/1/UpdateService/FirmwareInventory`, which is HPE's path and not
  the standard's. A Dell or Supermicro controller registered under `ilo` would pass a
  naive test and fail every operation that matters. The check is positive-evidence-only:
  a controller that names a non-HPE manufacturer is a mismatch; one that names no
  manufacturer at all is not, because an absent property is not evidence.
- **Stripe live mode versus test mode.** A Stripe test account and the live account
  behind it are *different accounts* with different balances and different customers. A
  live key in staging charges real cards from a system nobody is watching; a test key in
  production takes orders that are never paid for and reports each one as settled.
  `CredentialReference::mayBeTried()` governs which *reference* may be used and cannot see
  which world the value behind it belongs to. So the key's declared mode is checked
  offline and a mismatch is refused **without contacting Stripe at all** — the safest
  thing to do with a live key that should not be here is not to send it — and Stripe's own
  `livemode` is then checked again, because a prefix is a claim and the API's answer is
  the fact.
- **IPMI's genuine ambiguity, not resolved by guessing.** ipmitool prints "Unable to
  establish IPMI v2 / RMCP+ session" for a wrong password, a disabled LAN interface, a
  blocked port and a machine that is not plugged in. An explicit authentication marker is
  `AuthFailed`; an explicit routing marker is `NetworkFailed`; the generic session error
  is `NeedsReview`, which says a person has to look. Every one of the four sends an
  operator somewhere different, and a confident answer that is right half the time is
  worse than an honest one.
- **IPMI has no false-positive problem at all**, and that is worth stating in a phase
  about false positives. IPMI 2.0 over LAN is UDP: there is no TCP connection to accept,
  no certificate for a gateway to present, nothing for a proxy to impersonate. An RMCP+
  session is established *by the credential*, so a successful `mc info` is proof of
  identity and of the credential in one answer.

## 7. What deliberately has no tester

Recorded in `EveryRealDriverHasAnIdentityTesterTest::UNTESTABLE`, which fails if either
excuse stops being true — a catalogue entry that has since gained a tester, or a driver
that has left the catalogue.

- **`smtp`** — there is no endpoint to test. The relay is a deployment setting
  (`MAIL_HOST`) reached over SMTP by the framework's own mailer, and in some deployments
  it is the log driver. A tester would have to send a message, which is a write, and it
  would establish something about the mailer rather than about a provider row.
- **`sy_registry`** — `BLOCKED_LICENSE` in the sense that matters here: the `.sy`
  registry's technical contract is not available to this project. Its adapter is a
  deliberate placeholder whose every method refuses, so there is no endpoint, no
  authentication mechanism and no documented response shape to identify. A tester written
  from guesses would be fiction, and the one thing worse than no registrar is a registrar
  the platform believes in.

## 8. Findings this pass produced

### 8.1 A live SSRF bypass in `EndpointPolicy::assertMachineAddress` — fixed

Found by probing the policy at runtime, not by reading it. Every one of these was
**accepted** as a machine address before this pass:

| address | what it is |
|---|---|
| `169.254.169.254:80` | the cloud metadata service, by address, on the port it answers on |
| `127.0.0.1:8443` | this host, which runs the control plane's own services |
| `[::1]:443` | this host again, over IPv6 |
| `0.0.0.0:1` | the unspecified address |
| `metadata:80` | the metadata service by name |

The mechanism is the kind that survives review. `assertHost()` asked whether the string
was an IP literal; `169.254.169.254:80` is not one, because the colon fails
`FILTER_VALIDATE_IP`. So it fell through to name resolution, which cannot resolve a
string with a port in it either and returned no addresses at all — and the loop that
refuses loopback, link-local, multicast and the metadata literals therefore ran **zero
times**. The address was accepted with no address check having happened. The name
blocklist still caught `localhost:80`; nothing caught the literals, which is the half
that matters, because the metadata service is reached by address.

A BMC on a non-standard port is an ordinary thing to have, so the fix parses the port
rather than forbidding one, validates it as a port in its own right, and brackets a bare
IPv6 literal instead of splitting on its last colon. The positive twin
(`a_machine_on_a_non_standard_port_is_still_allowed`) exists so that a future fix cannot
close the bypass by making the platform unable to reach a BMC on 8443 — which is most of
them behind a management proxy, and would have been reverted.

### 8.2 The brief's model of the backup provider did not match the repository

`ProxmoxBackupProvider` builds a `ProxmoxConnection` and calls `/nodes/{node}/vzdump`,
`/nodes/{node}/qemu` and `/nodes/{node}/storage/{datastore}/content` — all Proxmox **VE**
endpoints. The datastore itself lives on a Backup Server and is declared and applied by
the `pbs` Ansible role, not by this platform. A tester written against a Backup Server's
own API would have been testing something the platform never calls: a credential could
pass and every backup still fail. The repository decided.

### 8.3 A BMC reaches the same tester through two doors with different endpoint shapes

`TestConnection::forServer()` passes a managed server's bare BMC address, checked by
`assertMachineAddress`. `TestConnection::forProvider()` passes a BMC provider row's
endpoint, which `assertProviderEndpoint` requires to be a full HTTPS URL. Both are real
paths in this platform, so both are accepted, rather than one being declared wrong. The
negative matrix exercises the bare form and the leak test exercises the URL form.

### 8.4 A cross-product false positive, found by the matrix rather than by review

The first Proxmox tester identified **WHM** as Proxmox. WHM answers its own version
command with `{"metadata":{…},"data":{"version":"11.126.0.4"}}` — a `data` object
carrying a `version` that matched the pattern — so a cPanel node registered under the
`proxmox` driver was identified as a cluster and reported `ConnectedReadOnly`. The fix is
to require what Proxmox also reports and WHM does not: the build the answer came from,
`release` or `repoid`. A version inside a `data` key is a shape, not an identity.

### 8.5 A parsing bug found by the positive twin

The DirectAdmin body parser kept only string values, and DirectAdmin's list commands
answer `list[]=alice&list[]=bob`, which `parse_str` turns into a nested array. So a
perfectly good account list read as an empty body, and an administrator login key was
reported as not being an administrator. The negative matrix could never have caught this:
it only asserts refusals. This is what a positive twin is for.

### 8.6 A third guard on the fake tester

The fake refused to be *constructed* in production and was only *registered* outside it.
Neither control can see a **production provider row** tested from a staging deployment —
and that row is the one the readiness engine consults before a product goes on sale. So
the environment of the thing being tested is now checked too. One existing test had to
move to the other side of the same asymmetry (a staging machine with a production
credential rather than the reverse); `DeploymentEnvironment::satisfies()` is an exact
match, so either direction proves the property that test is about.

### 8.7 A catalogue claim that had become false

`TheProviderRegistryRefusesToGoLiveOnHopeTest` asserted that `proxmox` and `cloudflare`
were **not** testable, with a comment reading "the honest state of this build: adapters
exist for these, and no connection tester does." That is no longer the honest state. The
assertion was inverted and the comment rewritten to record what changed and what is still
untestable — rather than the test being deleted.

## 9. Secret and response-body discipline

Nothing that is sent and nothing that is said is kept.

- The credential is resolved at the last moment into a `TestTarget` that redacts itself
  in `__debugInfo`, is put into a header, and is gone when the method returns.
- Every step detail, every `detail` string and every capability row is built from fixed
  sentences written in the tester's own source, plus values that passed
  `IdentityProof::token()` — a conservative character class truncated to 40 characters.
  A Proxmox release, a WHM version, a Redfish version and a Stripe account id pass it
  unchanged; an HTML error page, a `Set-Cookie` value and a JWT do not.
- Transport failure messages are **matched and discarded**: cURL writes the endpoint, and
  sometimes a resolved address, into them.
- Stripe exception *classes* are used and their messages never are:
  `ApiErrorException` carries the failed request's body, which is one of the commonest
  ways an API key reaches a log file.
- ipmitool's output and command line are never rendered: `-P` puts the password in argv.
- No tester has a logging path at all.
- No `access token`, `password`, `API secret`, `Authorization` header, `session cookie`,
  `private key`, raw upstream response or credential-bearing URL is persisted.
- The frontend never receives a secret in order to test a connection: the request is
  `POST /api/admin/providers/{provider}/connection-test` with no body, and the value is
  resolved server-side from the credential reference.

`AConnectionTestKeepsNeitherTheSecretNorTheAnswerTest` asserts this by running the whole
action — resolver, tester, record, audit — against an upstream that quotes a
credential-shaped sentinel back in its error body, then searching the connection test's
steps and detail, the provider row, every audit entry's context, and the log.

## 10. Deliberate breakage results

Each was applied, the suite run, and the change reverted.

| # | breakage | gate that caught it | result |
|---|---|---|---|
| A | Proxmox tester returns a matched proof for any HTTP 200 | `AConnectionIsNotHealthyBecauseASocketOpenedTest` | **19 of 98** cases failed (79 passed) |
| B | Cloudflare tester accepts any JSON as its own product | same, plus the positive identity file | **12** cases failed across both drivers it serves |
| C | `cpanel` registration removed from the tester registry | `EveryRealDriverHasAnIdentityTesterTest` + the matrix's own coverage gate | **14** failures; the coverage gate named `cpanel` explicitly |
| D | base class quotes `$response->body()` into the identity step and detail | `AConnectionTestKeepsNeitherTheSecretNorTheAnswerTest` | **8 of 9** failed — every driver at once |

Breakage D is the one worth noting: quoting the upstream body into the detail is the most
natural thing in the world to write, and it fails every driver simultaneously.

All four restored; the suites return green (§11).

## 11. Verification

Run locally at the commit this document is part of.

| check | command | result |
|---|---|---|
| New gates | `--filter='EveryRealDriverHasAnIdentityTester\|AConnectionIsNotHealthyBecauseASocketOpened\|EachProviderIsIdentifiedByWhatOnlyItSays\|AConnectionTestKeepsNeitherTheSecretNorTheAnswer'` | 148 tests, 505 assertions, passed |
| Providers, endpoint policy, infrastructure | `--filter='Providers\|EndpointPolicy\|AnEndpointIsNotAWayIntoTheNetwork'` | 269 tests, 1,012 assertions, passed |
| Code style | `vendor/bin/pint --test` | passed |
| Static analysis | `tools/phpstan/vendor/bin/phpstan analyse -c tools/phpstan/phpstan.neon` | 0 errors |
| Frontend types | `npx tsc -b` | clean |
| Frontend unit | `npx vitest run` | 81 files, 443 tests, passed |

The negative matrix is 98 cases: eight HTTPS drivers against thirteen impostor
responses, minus the cases where an impostor is the driver's own product. It is driven by
`ConnectionTesterFactory::drivers()` rather than by a list written in the test, so a
tester registered later inherits the whole matrix on the day it is registered — and a
second gate (`every_https_driver_in_the_registry_is_covered_by_this_matrix`) fails if one
is registered without a target, so the file cannot quietly cover less than it did
yesterday.

## 12. Exit criteria this gap addresses

| criterion | status | evidence |
|---|---|---|
| Existing 30B-P architecture PRESERVED | yes | no model, screen, credential system, readiness engine, capability registry, audit system or endpoint policy duplicated; two enum cases and one registry map added |
| New duplicate Admin architecture ZERO | yes | no frontend files added; two labels and two tones added to existing catalogues |
| Provider identity testers COMPLETE for every implemented real adapter | yes | 10 drivers registered; 2 recorded as untestable with reasons (§7) |
| TCP-only health proofs ZERO | yes | no tester can reach a usable state without a matched proof; breakage A quantified it |
| TLS-only health proofs ZERO | yes | same, and the handshake step says so in the operator's own step list on every test |
| Wrong-schema health proofs ZERO | yes | 98 negative cases including each product's answer served to every other product's driver |

Not addressed by this gap, and not started: unified `infra:preflight` with SIMULATION and
READ_ONLY_REAL modes, reference topology, naming standard, simulator contract-gap
assessment, write-capable preflight.

## 13. Truthful classification

- `CODE_COMPLETE` — the testers, the base-class protection, the two states, the
  registration, the gates.
- `TESTED` — 148 tests specific to this gap; 269 across the provider, endpoint-policy and
  infrastructure surfaces; four deliberate breakages each caught by a named gate.
- `RUNTIME_VERIFIED` — the SSRF bypass in §8.1 was found and its fix confirmed by
  executing `EndpointPolicy` against fifteen addresses, not by reading it. Every tester's
  behaviour is verified against faked responses at the HTTP, SDK and process layers.

Not claimed, and deliberately:

- `REAL_INFRA_VERIFIED` — **NONE.** No real Proxmox cluster, Backup Server, hosting node,
  BMC or chassis was contacted. 30B.0 recorded `BLOCKED_CREDENTIALS`, `BLOCKED_HARDWARE`
  and `BLOCKED_NETWORK` and none of those has changed.
- `REAL_PAYMENT_VERIFIED` — **NONE.** No Stripe account was contacted; the SDK's own HTTP
  seam was substituted in tests.
- `REAL_REGISTRAR_VERIFIED` — **NONE.**
- `REAL_HOSTING_VERIFIED` — **NONE.**
- `READY_TO_SELL` — **NONE.**

A tester that identifies a product correctly is not evidence that a product exists to be
identified. What this gap establishes is that when a real endpoint is finally available,
the platform will not claim to have reached it on the strength of a socket opening — and
that the sandbox which answers a trusted handshake for every hostname on earth can no
longer make this platform believe in an estate that is not there.

---

## Appendix — files

**New** (`apps/control-plane/src/Modules/Providers/`)

| file | lines |
|---|---|
| `Domain/DTOs/IdentityProof.php` | 140 |
| `Infrastructure/Testers/HttpIdentityTester.php` | 376 |
| `Infrastructure/Testers/Probe.php` | 74 |
| `Infrastructure/Testers/RedirectRefused.php` | 35 |
| `Infrastructure/Testers/ProxmoxConnectionTester.php` | 382 |
| `Infrastructure/Testers/ProxmoxBackupConnectionTester.php` | 170 |
| `Infrastructure/Testers/CpanelConnectionTester.php` | 292 |
| `Infrastructure/Testers/DirectAdminConnectionTester.php` | 334 |
| `Infrastructure/Testers/CloudflareConnectionTester.php` | 246 |
| `Infrastructure/Testers/RedfishConnectionTester.php` | 494 |
| `Infrastructure/Testers/IpmiConnectionTester.php` | 446 |
| `Infrastructure/Testers/StripeConnectionTester.php` | 324 |

**New tests**

- `tests/Architecture/EveryRealDriverHasAnIdentityTesterTest.php`
- `tests/Feature/Providers/AConnectionIsNotHealthyBecauseASocketOpenedTest.php`
- `tests/Feature/Providers/EachProviderIsIdentifiedByWhatOnlyItSaysTest.php`
- `tests/Feature/Providers/AConnectionTestKeepsNeitherTheSecretNorTheAnswerTest.php`

**Modified**

- `src/Modules/Providers/Domain/Enums/ConnectionState.php` — two cases
- `src/Modules/Providers/Infrastructure/ProvidersServiceProvider.php` — the registry
- `src/Modules/Providers/Infrastructure/Testers/FakeConnectionTester.php` — third guard
- `src/Modules/Shared/Domain/Services/EndpointPolicy.php` — port-bypass fix
- `tests/Unit/Shared/AnEndpointIsNotAWayIntoTheNetworkTest.php` — bypass regressions
- `tests/Feature/Providers/TheProviderRegistryRefusesToGoLiveOnHopeTest.php` — corrected claim
- `tests/Feature/Infrastructure/OnboardingAServerRefusesToSkipTheLookTest.php` — environment flip
- `apps/web/src/i18n/locales/{en,ar}.json`, `apps/web/src/lib/statusVocabulary.ts`
