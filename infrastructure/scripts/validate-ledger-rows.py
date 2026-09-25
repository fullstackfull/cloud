#!/usr/bin/env python3
"""Fail when a remediation-ledger row's status cell names something its own narrative does not.

Why this exists
---------------
The ledger's findings table is written one row per finding, and a row grows by
having each round's narrative appended. The status cell, by contrast, is
REPLACED each round. So a row whose narrative stops being updated keeps
advertising a current status over a stale history -- and because the oldest
narrative sits at the top, a reader who skims the row gets the WRONG round by
default. The row is ordered against the way it is read.

This is not hypothetical. Two agents in one wave were dispatched with briefs
written from a row's narrative:

  * F-15's verifier was told round four was "fourteen commits answering a
    rejection on two grounds". Round four is ONE commit; the fourteen-commit
    stretch ends at an ancestor of round three's tip. The verifier caught it.
  * F-11's implementer was told "the row states the five blocking items". The
    row's narrative ends at "Round eight dispatched", and the words `f11e`,
    `v11t`, `round nine` and `five blocking` appear in it zero times. The
    implementer caught it, looked for the record everywhere else, and could not
    find it. That verification is simply lost.

Both were caught by the agent rather than by me, which is exactly one layer of
luck too many. Hence a check.

What it checks
--------------
Two conditions, and they fail in opposite directions.

CONSISTENCY. Every worktree slug (`f11e`, `v04j`) and short sha (`ac7bbe2`)
quoted in a row's STATUS cell must also appear somewhere in that row's
NARRATIVE cell. That is a deliberately weak condition -- it cannot tell whether
the narrative is ACCURATE, only whether the round the status advertises was
ever written down. A weak check that runs beats a strong one that does not.

COMPLETENESS. A row whose status is CLOSED must name at least one sha that
resolves to a commit in this repository. This was added after the consistency
half had been green for days over EIGHT closed rows that named no sha at all --
because a check that asks "is what you said consistent?" cannot ask "did you
say anything?". A CLOSED row without a sha does not say what to integrate, and
the cost of finding out later is not symmetric: three of those eight have a
branch NAMED after the finding whose tip carries none of the finding's work
(`remediation/f32c` is a coordinator checkpoint; F-32's round three is on
`remediation/f32`). Integrating by branch name would merge the wrong thing and
look like success.

Slugs are deliberately NOT accepted here. A slug names a worktree, not a
commit, and the whole point of the failure above is that the two can disagree.

A finding closed BEFORE the branch-per-finding regime has nothing to integrate,
and must say so in the words "already on the integration branch" rather than by
saying nothing. That is not an escape hatch: the phrase is only accepted when
no `remediation/f<NN>*` branch exists, so a row that has a branch cannot use it
to avoid naming a sha.

Usage: validate-ledger-rows.py [path-to-ledger]
Exit 0 when every row is consistent, 1 otherwise.
"""
from __future__ import annotations

import pathlib
import re
import subprocess
import sys

DEFAULT = pathlib.Path(__file__).resolve().parents[2] / "docs" / "round-2-remediation-ledger.md"

# A worktree slug (f11e, v04j, calib) or a short sha. Both are written in
# backticks in the status cell by convention, which is what makes them findable
# without guessing at prose.
TOKEN = re.compile(r"`([a-z]\d{2}[a-z]?|[0-9a-f]{7,40})`")
ROW = re.compile(r"^\| (F-\d\d) \| ([^|]*)\| ([^|]*)\| ([^|]*)\| (.*)\|\s*$")
CLOSED = re.compile(r"\**`?CLOSED`?")
SHA_SHAPED = re.compile(r"[0-9a-f]{7,40}")
ALREADY = re.compile(r"already on the integration branch")


def has_branch(finding: str) -> bool:
    number = finding.split("-")[1]
    out = subprocess.run(
        ["git", "branch", "--list", f"remediation/f{number}*", "--format=%(refname:short)"],
        capture_output=True,
        text=True,
    ).stdout
    return bool(out.strip())


def is_commit(sha: str) -> bool:
    return subprocess.run(
        ["git", "cat-file", "-e", f"{sha}^{{commit}}"],
        capture_output=True,
    ).returncode == 0


def main(argv: list[str]) -> int:
    path = pathlib.Path(argv[1]) if len(argv) > 1 else DEFAULT
    if not path.is_file():
        print(f"validate-ledger-rows: no ledger at {path}", file=sys.stderr)
        return 1

    rows = 0
    failures: list[tuple[str, list[str]]] = []
    shaless: list[str] = []
    claimed_but_branched: list[str] = []

    for line in path.read_text(encoding="utf-8").splitlines():
        match = ROW.match(line)
        if match is None:
            continue
        rows += 1
        finding, _sev, _cls, status, narrative = match.groups()

        # Completeness: a CLOSED row has to name a commit somebody can check
        # out. `git cat-file -e` rather than a regex, because a plausible-
        # looking hex string that is not in this repository is exactly the
        # answer a hand-written row gives.
        if CLOSED.match(status.strip()):
            shas = [t for t in TOKEN.findall(status) if SHA_SHAPED.fullmatch(t)]
            if not any(is_commit(sha) for sha in shas):
                if ALREADY.search(status) and not has_branch(finding):
                    pass
                elif ALREADY.search(status):
                    claimed_but_branched.append(finding)
                else:
                    shaless.append(finding)
        # A sha may be written short in one cell and long in the other, so
        # compare by prefix in both directions rather than by equality.
        missing = [
            token
            for token in sorted(set(TOKEN.findall(status)))
            if token not in narrative
            and not any(
                token.startswith(other) or other.startswith(token)
                for other in TOKEN.findall(narrative)
            )
        ]
        if missing:
            failures.append((finding, missing))

    if rows == 0:
        print("validate-ledger-rows: matched no rows at all -- the table's shape has changed", file=sys.stderr)
        return 1

    for finding, missing in failures:
        print(
            f"{finding}: status cell names {', '.join(missing)}, absent from this row's narrative",
            file=sys.stderr,
        )

    for finding in shaless:
        print(
            f"{finding}: status is CLOSED and names no sha that resolves to a commit "
            f"-- the row does not say what to integrate",
            file=sys.stderr,
        )

    for finding in claimed_but_branched:
        print(
            f"{finding}: claims to be already on the integration branch, but "
            f"remediation/f{finding.split('-')[1]}* exists -- name the sha instead",
            file=sys.stderr,
        )

    if failures or shaless or claimed_but_branched:
        if failures:
            print(
                f"\n{len(failures)} of {rows} rows advertise a round their narrative never records.",
                file=sys.stderr,
            )
        if shaless:
            print(
                f"{len(shaless)} of {rows} rows are CLOSED without naming an integrable commit.",
                file=sys.stderr,
            )
        if claimed_but_branched:
            print(
                f"{len(claimed_but_branched)} of {rows} rows claim nothing to integrate "
                f"while holding a branch.",
                file=sys.stderr,
            )
        return 1

    print(
        f"validate-ledger-rows: {rows} rows, every status token present in its own "
        f"narrative, every CLOSED row naming a resolvable commit"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
