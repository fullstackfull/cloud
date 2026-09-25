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
Every worktree slug (`f11e`, `v04j`) and short sha (`ac7bbe2`) quoted in a
row's STATUS cell must also appear somewhere in that row's NARRATIVE cell. That
is a deliberately weak condition -- it cannot tell whether the narrative is
ACCURATE, only whether the round the status advertises was ever written down.
A weak check that runs beats a strong one that does not.

Usage: validate-ledger-rows.py [path-to-ledger]
Exit 0 when every row is consistent, 1 otherwise.
"""
from __future__ import annotations

import pathlib
import re
import sys

DEFAULT = pathlib.Path(__file__).resolve().parents[2] / "docs" / "round-2-remediation-ledger.md"

# A worktree slug (f11e, v04j, calib) or a short sha. Both are written in
# backticks in the status cell by convention, which is what makes them findable
# without guessing at prose.
TOKEN = re.compile(r"`([a-z]\d{2}[a-z]?|[0-9a-f]{7,40})`")
ROW = re.compile(r"^\| (F-\d\d) \| ([^|]*)\| ([^|]*)\| ([^|]*)\| (.*)\|\s*$")


def main(argv: list[str]) -> int:
    path = pathlib.Path(argv[1]) if len(argv) > 1 else DEFAULT
    if not path.is_file():
        print(f"validate-ledger-rows: no ledger at {path}", file=sys.stderr)
        return 1

    rows = 0
    failures: list[tuple[str, list[str]]] = []

    for line in path.read_text(encoding="utf-8").splitlines():
        match = ROW.match(line)
        if match is None:
            continue
        rows += 1
        finding, _sev, _cls, status, narrative = match.groups()
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

    if failures:
        print(
            f"\n{len(failures)} of {rows} rows advertise a round their narrative never records.",
            file=sys.stderr,
        )
        return 1

    print(f"validate-ledger-rows: {rows} rows, every status token present in its own narrative")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
