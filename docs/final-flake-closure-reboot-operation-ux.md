# Final flake closure — the reboot operation UX contract

Branch `claude/relaxed-turing-nh8ybf`. Starting HEAD `f0d89fb`.

One thing was carried into this patch and one thing was found inside it. The
carried one is the browser race that Gap 6 measured, Gap 7 listed, Gap 8 wrongly
declared closed, and CI run 196 then fired. The found one is a one-second
wall-clock race in a backend test, which failed run 197 and appears in no
carried-flake record anywhere — because nobody had seen it before.

Both are closed here, and neither is closed with a sleep.

## 1. The original race

Gap 6 §31 recorded it in full, from the run that first produced it:

```
getByRole('region', { name: 'Updates' }).getByText(/Reboot requested/)
```

Three of 364 browser tests failed on that one assertion, in `wave-4.e2e.ts`
(through its `reboot()` helper), `wave-5-recovery.e2e.ts`, and
`mobile/what-is-happening.e2e.ts`. In the same run, in the same process, against
the same database, the identical assertion passed three other times. An
assertion that passes three times and fails three times in one process is
timing.

Gap 7 §33 listed it as `KNOWN FLAKE — CARRIED`. Gap 8 migrated the desktop and
recovery specs to a lifecycle assertion and then wrote `KNOWN FLAKES = 0` — and
the mobile spec had been missed, so the number was wrong from the moment it was
written. Run 196 failed on exactly that spec: 1 of 364.

## 2. Root cause

Not a defect. The design, working as designed, observed by a test that assumed
something the design never promised.

`WatchedOperationsProvider::watch()` announces the acknowledgement under the
operation's own id:

```
toasts.announce({ id: receipt.id, tone: 'info', title: t('operations.requested', …) })
```

and `announceTerminal()` announces the outcome under **that same id**. The toast
channel replaces by id, so the outcome takes the acknowledgement's place rather
than queueing behind it. That is the right design — two messages for one reboot
is worse than one — and it means the interval during which the words "Reboot
requested" exist in the DOM is exactly one HTTP round trip: the watcher's first
read of `/operations/{id}`.

The browser suite runs the queue inline (`QUEUE_CONNECTION: sync`, with the
reason written in `playwright.config.ts`), so the work is already finished when
that first read lands. The window is as small as it can be, and whether a
browser observes it is a coin toss decided by the runner's load.

There is a second timer in the same channel, and it matters to any test that
asserts against it: an `info` or `success` message clears itself six seconds
after it is announced (`TRANSIENT_MS` in `Toasts.tsx`). A `warning` or `danger`
message does not, because bad news has to be read.

## 3. The old contract, and why it was invalid

The old assertion said: *after a reboot is requested, the words "Reboot
requested" are visible.*

The application never guaranteed that. It guarantees the acknowledgement is
**announced**; it does not guarantee it is **observed**, because a terminal
state that arrives first supersedes it — correctly, since the outcome is
stronger information than the receipt.

Making that assertion true would have required one of two things, and both are
the product changing shape to suit a test:

- hold the acknowledgement for a minimum visible interval, which is a timer-based
  UX contract and delays the truth to preserve a toast; or
- announce the outcome as a second message, which leaves two statements about
  one machine on screen.

Neither was done.

## 4. The contract that replaced it

> A customer who presses Reboot sends exactly one reboot request. From that
> moment until the channel is dismissed or clears itself, the channel says where
> that operation stands, in the lifecycle's own words, under one identity. Which
> of those words the customer sees depends on how fast the work finished, and
> every one of them is correct. The outcome supersedes the acknowledgement and is
> never superseded by it.

Read off against the brief's questions:

| | |
|---|---|
| Transient acknowledgement mandatory | **NO** — announced always, observable only if the work outlives one read |
| Terminal state precedence | The outcome replaces the acknowledgement under the same id, and `announced.current` stops a later read re-announcing the same state |
| Exactly one mutation | One press, one `POST /vps/{id}/power`, one operation id, one watcher |
| Failure rendering | `failed` → "Reboot did not finish" with retry advice; never the success sentence |
| `needs_review` / `indeterminate` | Neither is a failure; both point at support |
| Layout independence | The contract is in `WatchedOperations.tsx`, above the routes; nothing about it is per-viewport |

Nothing in the application changed. The contract was already the one implemented
— what changed is that the tests now assert it instead of asserting a timing
accident.

## 5. What changed

Four files, no application code.

### `src/features/operations/__tests__/watching-what-was-started.test.tsx`

Three tests added, so that both orderings and the failure path are pinned where
the read is a controlled fake and the clock belongs to the test:

| Test | Ordering | What it holds |
|---|---|---|
| `replaces the acknowledgement with the outcome when the work finishes later` | slow: `processing`, `processing`, `succeeded` | "Reboot requested" is visible first; after the reads, "Reboot completed" is visible and "Reboot requested" is gone — one message, not two |
| `reports the outcome when the work is already over before the first read` | fast: `succeeded` immediately | "Reboot completed" is visible, "Reboot requested" was never observed, and that is correct |
| `reports a terminal failure as a failure, not as a generic success` | `failed` | "Reboot did not finish" is visible and "Reboot completed" is not |

The third is worth naming separately. "Reboot did not finish" was asserted in
four places before this patch and in every one of them **negatively** — as a
thing that must not appear. Nothing proved the portal could say it at all. A
failure rendered as the success sentence is the worst outcome this channel has,
because the customer believes a machine came back that did not.

### `e2e/support/helpers.ts`

`expectOperationReported()` already matched every message the channel can carry
for an action. Its negative twin did not exist, so the mobile spec asserted the
dismissal against `/Reboot requested/` alone — which passes without the dismiss
button doing anything at all, on every run where the channel was showing the
outcome instead. The same race, mirrored, and failing *open*.

The pattern is now one function, `everyLifecycleMessageFor()`, used by both
directions:

```ts
export async function expectOperationNoLongerReported(page: Page, action: string): Promise<void> {
  await expect(operationsChannel(page).getByText(everyLifecycleMessageFor(action))).toHaveCount(0)
}
```

### `e2e/mobile/what-is-happening.e2e.ts`

The spec that failed run 196. It is a layout test — it exists to prove the panel
does not cover the controls it is about — and it was asserting the
acknowledgement sentence on the way past.

It now asserts the lifecycle contract, counts the mutations the press produced,
and asserts the dismissal against every message rather than one:

```ts
page.on('request', (request) => { … mutations.push(…) })
…
await expectOperationReported(page, 'Reboot')
await expect(dismiss).toBeVisible()
await noSidewaysScroll(page)
await dismiss.click()
await expectOperationNoLongerReported(page, 'Reboot')
expect(mutations, `One press sent: ${mutations.join(', ')}`).toHaveLength(1)
```

The ordering of those lines is deliberate and is commented in the file. The
channel clears itself six seconds after the last announce, so every assertion
that needs the panel present is spending a six-second budget; they are three
round trips taken back to back immediately after the message was seen, rather
than a layout scan followed by a hopeful look for a dismiss button.

### `tests/Feature/SharedHosting/ShowHostingAccountEndpointTest.php`

The second race, found in run 197 and not in any carried record:

```
-'2026-10-19T17:13:53+00:00'
+'2026-10-19T17:13:52+00:00'
```

`a_suspended_account_states_when_its_data_may_be_released` records a suspension
at `now()` and then computes the expected release date from `now()` again. One
second ticking between the two makes them differ by exactly one second. The
claim is "thirty days after suspension"; the wall clock was never part of it, so
the test freezes it.

Seven other test files use `now()` in fixtures and were checked for the same
shape — reading `now()` on the fixture side *and* on the assertion side of one
value. None does: `AttackingWhatThisPhaseBuiltTest`,
`HostingPanelSessionEndpointTest`, `AnAccountKnowsWhatNeedsDoingTest`,
`TheDashboardAndFeedStayBoundedTest`, `AccountSecurityTest`,
`AnUnverifiedCustomerCanLookButNotBuyTest` and
`TheWholeLifeOfACountryCurrencyChangeTest` all use it for fixtures only, or with
day-scale margins, or inside a signed URL whose expiry is part of the signature.

## 6. Deliberate breakage

Six, each applied alone and restored, with the file's contents compared byte for
byte against the snapshot afterwards so a breakage cannot leak into the commit.

| | Breakage | Aimed at | Result |
|---|---|---|---|
| A | The old contract restored: assert "Reboot requested" is visible in the terminal-first test | `watching-what-was-started.test.tsx` | **1 failed / 7 passed.** The old contract is deterministically false when the work was already over |
| B | `announceTerminal` returns without announcing on `succeeded` | the lifecycle tests | **3 failed / 5 passed** |
| C | The outcome announced under `${id}-terminal` instead of the operation's id | the one-message claim | **1 failed / 7 passed** |
| D | The power mutation sent twice from `useVpsPower` | the browser exactly-once count | **1 failed.** `One press sent: POST /api/v1/vps/…/power, POST /api/v1/vps/…/power` |
| E | `failed` announced with the success tone and the success sentence | the failure test | **1 failed / 7 passed** |
| F | The watcher returns before announcing any terminal state — the UI stays on the acknowledgement for ever | the lifecycle tests | **6 failed / 2 passed** |

A deserves its own paragraph, because it is the whole argument of this patch in
one result.

Restoring the old assertion **in the browser** — `getByText(/Reboot requested/)`
in the mobile spec — was also run, and it **passed**: `1 passed (10.2s)`. That
is not a refutation, it is the demonstration. A racing assertion passes most of
the time; that is what makes it a flake rather than a failure, and it is why the
browser is the wrong place to hold that sentence. Moved into the deterministic
test, where the reads are a fake and the ordering is chosen, the same assertion
fails every time, which is what a proof looks like.

C fails only once rather than twice for a reason worth recording: in the slow
ordering the acknowledgement has already cleared itself by the time the test
looks (ten simulated seconds against a six-second lifetime), so the duplicate
message is invisible there and visible in the fast one. The gate still holds;
the arithmetic is just narrower than it first appears.

## 7. What still protects the exact sentences

Moving an assertion out of the browser is only safe if it lands somewhere. Every
sentence this channel can produce is asserted exactly, by name, in a
deterministic test:

| Sentence | Where |
|---|---|
| Reboot requested | `watching-what-was-started.test.tsx`, three tests |
| Reboot completed | same file, three tests |
| Reboot did not finish | same file, positively in one and negatively in three |
| Reboot stopped and we are looking at it | same file |
| We could not confirm the result of Reboot | same file |
| Never a bare "Success…" | same file, and `expectOperationReported` in every browser spec |
| The dismiss button has a name and works from the keyboard | `what-a-screen-reader-hears-happening.test.tsx` |
| Good news clears itself, bad news does not | same file, under fake timers |

What the browser keeps is what only a browser can answer: that the channel
exists on the page, that it reports the operation, that it does not cover the
controls at 393 and 360 pixels, that the dismiss button is reachable with a
thumb, and that one press sends one request.

## 8. Repetition

The brief asks for a minimum of twenty and prefers fifty. Fifty, of the whole
spec rather than the one test, on the phone project, with no sleeps and no
retries (`retries: 0` is the suite's own setting):

```
playwright test --project=customer-mobile --repeat-each=50 e2e/mobile/what-is-happening.e2e.ts

  150 passed (8.3m)
```

| | |
|---|---|
| Repetitions | 50 |
| Tests per repetition | 3 |
| Passed | 150 |
| Failed | 0 |
| Duration | 8.3 minutes (500s wall clock including server start) |

Fifty passes is not a proof that a race is gone — it is a proof that this
assertion does not depend on which side of it lands first, which is a different
and stronger claim, because the race itself is still there and is still correct.

## 9. The other failure in run 197, and what it was

Run 197 failed two jobs, not one. The second was Security checks:

```
npm warn audit 400 Bad Request - POST https://registry.npmjs.org/-/npm/v1/security/audits/quick
{ statusCode: 400, error: 'Bad Request',
  message: 'Invalid package tree, run  npm install  to rebuild your package-lock.json' }
```

It reads like a broken lockfile and is not one. Three things say so:

1. No manifest changed between run 193, which passed this job, and run 197:
   `git log 8935be2..f0d89fb --name-only -- '*package.json' '*package-lock.json'`
   is empty.
2. `npm audit --audit-level=high` on this exact tree, on the same npm 10.9.7 and
   Node 22, exits 0 here with two moderate advisories and no error.
3. The same log line says the endpoint it hit is being retired. npm reaches for
   `/security/audits/quick` only as a fallback when the bulk advisory endpoint
   fails, and the retired endpoint cannot represent a workspace tree — so a
   transient failure of the first produces a hard error from the second.

It was not patched around, because the honest fix for a transient fallback is to
observe whether it recurs. It did not: the same job on the next push, run 198,
passed in one second.

## 10. The full browser suite

Run as CI runs it, on a database rebuilt by the suite's own `globalSetup`, with
`retries: 0`:

```
  14 skipped
  364 passed (30.0m)
```

| | |
|---|---|
| Passed | 364 |
| Failed | 0 |
| Skipped | 14 |
| Duration | 30.0 minutes |

Every one of the 14 skips is `captures.e2e.ts`, the visual-record specs, which
carry `test.skip(process.env.CAPTURES !== '1')`. That is the suite's only
conditional skip and it accounts for all 14 — so nothing was skipped that this
patch touches, and the three reboot specs really ran.

## 11. A mistake of mine worth recording

The first local run of the full backend suite reported **126 failures** across
Billing, Console, DNS and more — modules this patch does not touch. None of them
was real.

I had run `php artisan test --env=testing`, and the `.env.testing` on this
machine is not the file CI uses. CI copies `.env.testing.example` and runs
`php artisan test` with `APP_ENV=testing`; the local file was a copy of `.env`,
carrying `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` where `phpunit.xml`
declares `array` and `sync`. Against a real Redis, `$this->travel()` moves
Carbon's clock and does not move a cache entry's TTL, so a test that travels
past a sixty-second console permit finds it still there — hence "an expired
permit is refused" failing with the permit accepted, and a rate limiter counting
across tests that `array` would have isolated.

Flushing Redis did not fix it, which is what ruled out leftover state and
pointed at the driver. Rebuilding `.env.testing` from `.env.testing.example`
did: the same class went from 9/11 to 11/11 without a line of code changing.

It is written down because the first instinct on seeing 126 red tests is to
believe them, and the second is to dismiss them — and both would have been
wrong. What settled it was running one failing class three times: as it was,
after a Redis flush, and after matching CI's environment.

## 12. CI

| | |
|---|---|
| Run | 199 |
| Run id | 35466073629 |
| SHA | `78355a8f89fb81663fb21cf319f75c1c43c2919b` |
| Attempt | 1 |
| Conclusion | **success** |
| Jobs | **9 / 9 successful** |

| Job | Result |
|---|---|
| Backend (PHP 8.4, PostgreSQL 16) | success, tests 10m27s |
| Backend (PHP 8.4, PostgreSQL 18) | success, tests 10m14s |
| Browser end-to-end | success, suite 30m15s, **first attempt, no re-run** |
| Static analysis (PHPStan) | success |
| Frontend (tsc, eslint, unit, build) | success |
| API description | success |
| Security checks | success |
| Infrastructure validation | success |
| Production guards | success |

The browser row is the one that matters here, and it is worth stating plainly:
**green on the first attempt, with no job re-run.** Gap 6's exact-SHA run needed
a second attempt of that job and said so; this one did not.

Run 198, on the functional commit `6c4d342` alone, was **cancelled** — by my own
push of the documentation commit, because `ci.yml` carries
`concurrency: cancel-in-progress: true` per ref. That is a mistake in how I
sequenced the pushes, not a CI failure, and it is recorded rather than quietly
replaced by the run that did finish. `78355a8` is `6c4d342` plus this document
and nothing else.
