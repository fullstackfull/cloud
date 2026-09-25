# Handoff — where this stopped, and the prompt to restart it

**Written:** 2026-09-25 · **Integration branch:** `claude/relaxed-turing-nh8ybf`
· **Repo:** `fullstackfull/cloud`

---

## ملخّص بالعربية

توقّف العمل لأن **حدّ الاستخدام الأسبوعي للـ API انتهى** (يُعاد ضبطه في
1 أكتوبر، الساعة 2 صباحًا UTC). لم يتوقّف العمل بسبب خطأ في الكود.

كل الوكلاء (agents) الذين كانوا يعملون تمّ إنهاؤهم في منتصف عملهم:

- **F-15 الجولة السابعة** — كانت قد أنجزت الإصلاحين، وماتت وهي تعيد تشغيل
  الطفرات. العمل **محفوظ** في commit عليه تحذير واضح أنه غير مُتحقَّق منه.
- **F-46 الجولة الأولى** — أنجزت تعديلات في ١٩ ملفًا وماتت قبل أي إثبات،
  و**لم تسلّم الإحصاء (census)** الذي كان أول ما طُلب منها. محفوظ كذلك.
- **F-17 الجولة الأولى** — ماتت وهي **تحمل طفرة حيّة**: سطران محذوفان من
  `routes/v1/team.php`. تمّت إعادتهما وشجرة العمل نظيفة الآن.

**الحالة:** 43 مغلقة · 3 جزئية · 1 مفتوحة. `SOFTWARE_CODE_COMPLETE = NO`
وكل حالات `REAL_*` و`READY_TO_SELL` = `NONE`، ولم تتحرّك ولن تتحرّك هنا.

كل شيء مدفوع إلى الفرع. القسم الأخير من هذا الملف هو **النص الكامل** الذي
تُلصقه في المحادثة الجديدة.

---

## 1. Why it stopped

A weekly API rate limit, at 2026-09-25. Not a code failure, not a blocked
step. Twelve background agents were terminated with HTTP 429 mid-run. The
limit resets **Oct 1, 02:00 UTC**.

Everything the coordinator had committed was already pushed. The three live
agents' uncommitted work has since been preserved (see §3).

## 2. State of the 47 findings

**43 CLOSED · 3 PARTIAL · 1 OPEN.**

| | findings |
|---|---|
| **OPEN** | **F-15** — round six REJECTED, round seven in flight |
| **PARTIAL** | **F-17** (round one dispatched), **F-46** (round one dispatched), **F-42** (measured unanswerable at obtainable sample sizes; not a code gap) |
| CLOSED | the other 43 |

Frozen and unchanged, as required:

```
SOFTWARE_CODE_COMPLETE  = NO
30B.0-E                 = NOT READY
REAL_INFRA_VERIFIED     = NONE
REAL_PAYMENT_VERIFIED   = NONE
REAL_REGISTRAR_VERIFIED = NONE
REAL_HOSTING_VERIFIED   = NONE
READY_TO_SELL           = NONE
```

## 3. The three killed agents, and exactly what survived

| worktree | branch | state now |
|---|---|---|
| `/home/user/worktrees/f15m` | `remediation/f15m` | WIP committed at **`626d31e`** |
| `/home/user/worktrees/f46a` | `remediation/f46a` | WIP committed at **`8095e9b`** and **`0fe470e`** |
| `/home/user/worktrees/f17a` | `remediation/f17a` | **restored to clean** — it died holding a live mutation |

### f17a died holding a live mutation

It had deleted both `->middleware('throttle:team-invitations')` lines from
`routes/v1/team.php` — the measurement its brief told it to take first — and
died before restoring them. **Found and restored**; the file carries both
lines again. Nothing was committed, so round one of F-17 has produced no
deliverable and starts again from the measurement.

**Treat this as the standing hazard of a killed agent.** Check the other two
trees for the same before trusting them.

### f15m (`626d31e`) — real work, unverified

From reading the diff only, not from any measurement:

- Raw-SQL window widened `{0,200}` → **`{0,20000}`** (Red 1's fix). The
  verification measured zero false positives up to width 6,000; **20,000 is
  past what was swept**, so its cost is not established.
- A window-size assertion that parses `{0,N}` out of the shape and fails if it
  is absent or too small — the half round six lacked, since `{0,30}` used to
  leave all nine tests green.
- `census()` given an injectable `?callable $reader`, with a new test driving
  the whole-file property through **`census()`** rather than `censusOf()`
  (Red 2's fix).
- ~70 lines added to `AdminSurfaceTest` — where the owed `routes/` assertion
  was to go.

No band run, no Pint, no proof of any kind.

### f46a (`8095e9b`, `0fe470e`) — work without its justification

Nineteen files changed and **the census was never handed back**. The brief
required the census *first*: one row per declared notification type, with
where it is declared, whether both locales carry it, which file and line
produces it or the word `nothing`, and whether that producer is **reachable**.

So the next session has a diff whose reasoning is missing. **Rebuild the
census before reading the diff**, not after — reading the diff first tells you
what the answer was supposed to be.

## 4. What was finished in this session

- **F-47 CLOSED** (`73c0b8e`) — integrate `remediation/f47g` @ `dfadcd1`.
  Four of five reservations corrected in the tree. The one that mattered was a
  *false instruction*: the enum's integrator banner told a reader two queries
  were broken when both are correct.
- **F-14 CLOSED** (`125c307`) — integrate `remediation/f14t` @ `0a94264`.
  Five corrections, including a **fifth** citation error introduced by the
  paragraph correcting the fourth.
- **F-41 CLOSED** (`81a5aa3`) — integrate `remediation/f41g` @ `de9da2b`.
  Its verification recommended opening a new finding, which the F-01..F-47
  namespace forbids; the two questions showed the recommendation wrong for
  that item and the code moved instead. The repair found **two** copies of the
  defective idiom where the verification had found one.
- **F-15 round six REJECTED** and round seven briefed and dispatched.
- **F-17 and F-46 round one** briefed and dispatched (both died).
- **Four CI validator self-tests** written (`7bc9ca2`); writing them found the
  same empty-subject hole in all three untested validators.
- **The ledger gate gained a third half** (`7ab4248`) after F-45's cell was
  found reading `OPEN — CLOSED`, which also exempted it from the closed-row
  check.
- **The integration manifest** (`48db7cd`), derived from the commit graph, with
  a validator and self-test. 36 tips, no forks, 0 of 36 rows disagree.
- **The contention recount** (`dca6d70`): the old figure counted diffs, not
  conflicts. 31 two-way merges, **nine** that are real work, all registries.
- **The integration dry run** (`dab669d`) and **the re-audit brief**
  (`03a9623`).

## 5. The integration survey, and its suite result

`integration/round-2-survey` @ **`0d44f82`**, in `/home/user/worktrees/intg`.
**30 of 37 tips merged clean; six conflicted and were skipped; F-15 excluded.**

**This is a survey, not a candidate.** It is missing seven findings. Do not
merge it.

Conflicting: **F-19** (7 files — the real one, a `ServiceController` refactor
onto `EndOfService` against F-12's added `retire()`), **F-34** (3), **F-38**
(2), **F-39** (2), **F-27** (1), **F-41** (1). F-38's and F-39's conflicts are
against the coordinator's own CI self-test work, which was written on the
integration branch in F-38's own files.

Its full suite, measured:

```
5,202 tests · 5,194 passed · 8 failing testcases · 607 testsuites
skipped = 0 everywhere · ARTISAN-TEST EXIT=2
Pint green at tree scope · migrate:fresh clean
```

The eight fall into three families, and **two of them are a predicted,
documented success**:

1. **F-47 × F-12, two tests** —
   `the_only_writer_of_the_retired_state_is_the_test_factory` and
   `no_excuse_outlives_the_state_it_excuses`. F-12 now retires machines in
   production, so F-47's census and F-23's excuse test both fire **correctly**.
   F-47's verification predicted this to the line number (`:135`). F-47's own
   docblock says what to do and what not to do: **rewrite the census test; do
   NOT add `RetireDedicatedServer` to the expected list**, because the sentence
   it holds has stopped being true and patching the list turns a claim about
   the platform into a running total.
2. **Five `CheckoutRejectedException` errors** in
   `TheHostingPackageBehindAPlanIsChosenNotStumbledOnTest` — almost certainly
   caused by **F-27 being absent from the survey** (`CheckoutRejectedException`
   is contended between F-04 and F-27, and F-27 is one of the six skipped).
   Verify rather than assume.
3. **`DevelopmentFixturesTest::every_seeded_hosting_plan_can_be_quoted_and_bought`**
   — 422 instead of 201, same family, same suspicion.

So six of the eight are plausibly artefacts of the missing findings, and two
are the system working. **Do not "fix" any of them on the survey branch.**

## 6. What remains, in order

1. **Finish F-15 round seven** — verify `626d31e`, or redo it. Then an
   independent verification. It is the last OPEN finding.
2. **Finish F-17 round one** — from the measurement, since nothing was
   committed. Then verify.
3. **Finish F-46 round one** — census first, then judge the WIP diff. Then
   verify.
4. **Resolve F-42's PARTIAL** — it is not a code gap; decide whether PARTIAL is
   the honest final state and say why.
5. **Build the real integration candidate** — all 37 tips, the six conflicts
   resolved by hand, the seven migrations renumbered under F-32's scheme, the
   nine registry files resolved **by union with the entry count checked**.
6. **Green the candidate**, including the two F-47 × F-12 tests, which are
   rewrites and not patches.
7. **Dispatch the re-audit** — `docs/round-2-briefs/final-re-audit.md`, five
   bands plus two cross-cutting assignments, against the candidate's sha.
8. **Write `docs/final-independent-re-audit-after-round-2.md`.**
9. **Adjudicate `SOFTWARE_CODE_COMPLETE`.**

## 7. Owed before integration

- **PHPStan has never run in this programme.**
  `apps/control-plane/tools/phpstan/vendor` is **82 directories and zero
  files** — an aborted install that reads like a working toolchain. The check
  is `test -x apps/control-plane/tools/phpstan/vendor/bin/phpstan`, not a
  directory listing. Running it needs `composer install`, which is forbidden.
- **F-24 is red by design** and mergeable only with **F-04 and F-45 both
  present** — measured at five points.
- **Verification evidence** lives on four `v`-branches (`v14n`, `v34b`, `v38b`,
  `v40b`) at three different paths and is dropped by the merge rule. Keep it as
  one directory, chosen deliberately, or drop it deliberately.
- **The ledger quotes four of the seven migration filenames.** Those quotes
  move in the same commit as the rename.

## 8. Standing rules that must survive the handoff

- **Namespace: F-01 … F-47 only.** No F-48, no new phases, no new numbering.
  An unnumbered observation stays unnumbered.
- **No finding agent may close its own finding.** Every closure needs an
  independent verifier.
- **Isolation:** no two mutation agents share a worktree, branch, database,
  Redis namespace, queue namespace or scratch directory.
- **Only the coordinator integrates.**
- **No real infrastructure**, ever: no providers, credentials, payments,
  registrars, no apply, no deploy, no Docker. Simulators only.
- Never `git stash`; never `composer install`/`dump-autoload`; never edit
  `vendor/` in place; never `pkill` what you did not start.
- **Never a bare `php artisan migrate:fresh`** — `.env` has `APP_ENV=local`
  and `DB_DATABASE=lynomia`, so it lands on the **application** database.
  Export `DB_DATABASE` and check
  `config('database.connections.pgsql.database')` first.
- **Three proofs** for anything called green: JSON with no `skipped` key and
  `tests == passed`; `--log-junit` with every `testsuite` at `skipped="0"`;
  `EXIT=0` taken with `${PIPESTATUS[0]}`.
- Test counts add across paths. **Assertion totals do not.**
- **A path handed to an agent is absolute or it is not handed over.**
- **A claim that a sentence was corrected carries the grep that proves it.**
  Four consecutive verifications found one that was not.

## 9. Key files

| path | what it is |
|---|---|
| `docs/round-2-remediation-ledger.md` | the record; one row per finding plus the narrative sections |
| `docs/integration-manifest.md` | what to merge, derived from the graph, with the conflict and contention tables |
| `docs/round-2-briefs/adjudication-rule.md` | the two questions; read before judging any sentence |
| `docs/round-2-briefs/final-re-audit.md` | the re-audit brief, written and not dispatched |
| `docs/round-2-briefs/f15-round-seven.md` | both reds with their exact reproductions |
| `docs/round-2-briefs/f17.md`, `f46.md` | the two PARTIAL rounds |
| `docs/final-independent-multi-agent-audit-round-1.md` | the original audit — the specification |
| `infrastructure/scripts/validate-ledger-rows.py` | the ledger gate, three halves, with a self-test |
| `infrastructure/scripts/validate-integration-manifest.py` | graph vs prose, with a self-test |
| `<session scratchpad>/mkworktree.sh` | the worktree provisioner; pool is Redis 2–14, 15 excluded |

Run `make ledger-validate` after any ledger edit. It has refused ten commits
of mine, always for the same habit, and has never once been wrong.

---

# 10. THE PROMPT — paste this into the new conversation

```
You are the MASTER REMEDIATION COORDINATOR for the Lynomia Cloud round-two
remediation programme, repo fullstackfull/cloud, branch
claude/relaxed-turing-nh8ybf.

FIRST, READ THESE, IN THIS ORDER, IN FULL:
  /home/user/cloud/docs/HANDOFF-next-session.md      <- start here
  /home/user/cloud/docs/round-2-remediation-ledger.md
  /home/user/cloud/docs/integration-manifest.md
  /home/user/cloud/docs/round-2-briefs/adjudication-rule.md

The previous session stopped on a weekly API rate limit, not a defect. Three
agents were killed mid-run. Their state, and what must be checked before any
of it is trusted, is in §3 of the handoff. One of them died holding a live
mutation, which was found and restored -- assume the other two may hold one
until you have proved otherwise.

YOUR TASK, in this order:

  1. Finish F-15 round seven. Its WIP is committed at 626d31e on
     remediation/f15m and is UNVERIFIED. Reproduce both reds against 0f7151c
     first, as its brief requires, then judge the WIP against them. Re-measure
     the false-positive cost of the {0,20000} window over the 1,356-file
     corpus -- the verification only swept to 6,000. Then dispatch an
     independent verification.
  2. Finish F-17 round one. Nothing was committed; start from the measurement
     its brief specifies (delete the middleware from one route, then both, and
     report whether the suite stays green each time). Then verify.
  3. Finish F-46 round one. REBUILD THE CENSUS BEFORE READING THE WIP DIFF at
     8095e9b/0fe470e -- the diff exists and its justification does not, and
     reading it first tells you what the answer was supposed to be. Then
     verify.
  4. Resolve F-42's PARTIAL: decide whether PARTIAL is the honest final state
     and say why in the ledger.
  5. Build the real integration candidate from the manifest -- all 37 tips,
     the six conflicts resolved by hand, the seven migrations renumbered under
     F-32's scheme, the nine registry files resolved BY UNION WITH THE ENTRY
     COUNT CHECKED against the sum of what each side added.
  6. Green the candidate. Two of the eight known failures are a predicted,
     documented success (F-47's census and F-23's excuse test firing because
     F-12 now retires machines in production) -- they are REWRITES, not
     patches, and F-47's docblock says explicitly what not to do.
  7. Dispatch the re-audit from docs/round-2-briefs/final-re-audit.md against
     the candidate's sha, five bands plus the two cross-cutting assignments.
  8. Write docs/final-independent-re-audit-after-round-2.md.
  9. Adjudicate SOFTWARE_CODE_COMPLETE, with the evidence, and do not round up.

RULES THAT DO NOT BEND:
  - Findings are F-01..F-47 ONLY. Never invent F-48, a new phase, a new
    roadmap or a new numbering scheme. An unnumbered observation stays
    unnumbered.
  - No finding agent may declare its own finding closed. Every closure needs
    an INDEPENDENT verifier in its own worktree.
  - SOFTWARE_CODE_COMPLETE = NO, 30B.0-E = NOT READY, and every REAL_* and
    READY_TO_SELL = NONE. No local test, simulator, inventory row, UI page or
    fake provider changes any of them.
  - Never promote WordPress to eliminate a finding (F-45's rule).
  - Never invent a real provider contract. Insufficient evidence means say so,
    not guess.
  - A finding is not closed because a test was rewritten to expect current
    behaviour.
  - No real infrastructure: no providers, credentials, payments, registrars,
    no apply, no deploy, no Docker. Simulators only.
  - Isolation is mandatory: no two mutation agents share a worktree, branch,
    database, Redis namespace, queue namespace or scratch directory. Provision
    with the session scratchpad's mkworktree.sh (pool is Redis 2-14; 15 is
    excluded on purpose).
  - Never git stash. Never composer install or dump-autoload. Never edit
    vendor/ in place. Never pkill anything you did not start.
  - NEVER a bare `php artisan migrate:fresh`: .env has APP_ENV=local and
    DB_DATABASE=lynomia, so from apps/control-plane it wipes the APPLICATION
    database. Export DB_DATABASE and check
    config('database.connections.pgsql.database') before running it.
  - Three proofs for anything called green: a JSON summary with no `skipped`
    key and tests == passed; --log-junit with every testsuite at skipped="0";
    and EXIT=0 taken with ${PIPESTATUS[0]}. Run ./vendor/bin/pint --test at
    TREE scope from apps/control-plane, which is CI's working-directory.
  - Test counts add across paths. Assertion totals do not.
  - Every path you hand an agent is ABSOLUTE.
  - A claim that a sentence was corrected carries the grep that proves it.
  - Maintain docs/round-2-remediation-ledger.md. Allowed statuses: CLOSED,
    OPEN, PARTIAL, BLOCKED_BY_EXISTING_FINDING, NOT_APPLICABLE_WITH_PROOF.
    Never "mostly done", "probably fixed", "looks good". Run
    `make ledger-validate` after every ledger edit and fix what it refuses by
    writing the round down, never by weakening the check.
  - Do not ask me to pick the next finding. Work through the list above
    automatically, and do not set SOFTWARE_CODE_COMPLETE = YES after any
    individual wave.

FINAL DELIVERABLES:
  docs/round-2-remediation-ledger.md
  docs/final-independent-re-audit-after-round-2.md
  and the SOFTWARE_CODE_COMPLETE adjudication, with its evidence.
```
