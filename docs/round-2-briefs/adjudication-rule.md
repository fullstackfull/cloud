# The adjudication rule, in one place

Every dispatch in round two quotes this file rather than restating it. That is
the point of the file: the rule it holds was, for eleven briefs, retyped from
memory into each dispatch, and a load-bearing rule with no single source drifts
into two incompatible readings without anybody noticing. It did. See the ledger
section *"One word was doing two jobs, and I decided two findings opposite ways
under it"*.

## The blocking test

**A reservation is blocking when it falsifies a claim the shipped code makes
about itself.**

A **missing oracle** blocks — a gate that cannot be made red by the failure its
name promises, a test that passes both before and after the fix, a scan that
reports success for a reason unrelated to the thing measured.

A **stale sentence** does not block, on its own. To tell which one you have, use
the two questions.

## The two questions

Apply them in order. They are independent and both are needed.

**1. Does the code currently satisfy the claim the sentence makes?**

- **Yes** → the sentence is describing the wrong thing. **The sentence moves.**
- **No** → go to question 2.

**2. Is the claim achievable *by the code that carries it*?**

- **Yes** → the gap is closeable. **The code moves.**
- **No** → **the sentence moves**, narrowed to what the code can hold.

A claim fails question 2 two different ways, and both are real:

- **Unbounded.** No implementation could deliver it — every wrong phrasing,
  every dynamic callee, an unbounded blacklist.
- **Out of reach.** The claim is perfectly achievable, but not *here*: it
  describes a property of a layer the code carrying the sentence does not
  control. A simulator's docblock claiming a platform-wide guarantee is the
  standard case. The fake can make the guarantee observable in simulation; it
  cannot make it true in production, however it is written, because the
  production path does not run through it.

The second form was added after F-24, where a docblock on a test double said it
*"implements an invariant `RedactedJsonCast`'s docblock already promises"* — a
promise whose subject is the production handler. Read question 2 as "achievable"
in the abstract and that blocks, demanding a fix the round was forbidden to
make; read it as "achievable by this code" and it is a sentence, which is the
right answer and the one the verifier reached independently.

**The scope of a claim must match the scope of the code carrying it.** A
sentence in one file asserting a property of the whole platform is a category
error before it is a falsehood, and the repair is always the sentence — plus,
where the property genuinely matters, a finding that owns it at the layer that
can hold it.

**A claim of reach that fails 1 and passes 2 is blocking.** That is the whole
of it: shipped code asserting a guarantee it does not provide, in a case where
providing it is possible. Everything else is a correction.

## What is not the rubric

**Reachability is not the rubric.** "No current caller can reach it" does not
downgrade a falsified self-claim, because which arguments today's callers happen
to pass is not a property of the code. A property that holds only by the
accident of present usage fails question 1.

**Novelty is not the rubric.** A defect inherited from an earlier round, or from
before the programme, still blocks the round that ships the sentence asserting
it is fixed.

**Cheapness is not the rubric**, in either direction. A one-clause fix blocks if
it fails 1 and passes 2; an expensive one does not block if the claim is
unachievable.

## Worked examples from this programme

| Finding | Claim | Q1 | Q2 | Outcome |
|---|---|---|---|---|
| F-47 | *"No production code enters this state"* | **passes** — nothing does, by any of four routes tried | — | sentence stands |
| F-47 | *"The state cannot acquire a writer while the declaration says it has none"* | fails — a raw `'retired'` scalar in a reachable action passes the whole band | passes — widen the classifier | **blocking** |
| F-34 | *"An address held for the platform belongs to no customer"* | fails — the query walks past a customer-less reservation to an older cycle | passes — one clause | **blocking** |
| F-38 | *"The guard covers all 37 files"* | **passes** — it does, regardless of what schedules them | — | sentence moves |
| F-38 | *"A workspace that loses its scripts fails instead of vanishing"* | fails — it still vanishes when the directory moves | passes — two lines of shell | **blocking** |
| F-23 | a docblock drawing a boundary at nested literals | fails | **fails** — the set of nested literal shapes is not enumerable | sentence moves, narrowed |
| F-29 | a docblock drawing a boundary at a dynamic callee | fails | passes — PHP's callee kinds are a closed grammatical set | code moves |

## The companion cost, which is why question 2 exists at all

Making a round's docblock load-bearing invites the wrong repair: an implementer
can always satisfy a blocking finding by narrowing the sentence instead of
widening the code. That is §27's family — closing a finding by rewriting the
expectation. Question 2 is what stops it: the sentence may only be narrowed when
no implementation could have delivered the claim. If the claim is achievable, a
narrowed sentence is not a fix and must be rejected as one.

## The superseded form — recognise it, do not apply it

> *"when the sentence and the code disagree, the code moves if the property is
> closed, and the sentence moves only if it is not"*

This appears in eleven round-two dispatches and in several adjudications. It is
defective: **closed** means *a closed, enumerable set* in its original worked
examples and *the claim currently holds* in every later use, and those point
opposite ways. If you meet it in a brief, apply the two questions instead and
say in your hand-back that you did.
