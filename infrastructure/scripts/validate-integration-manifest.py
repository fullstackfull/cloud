#!/usr/bin/env python3
"""Fail when the ledger's shas and the commit graph disagree about what to integrate.

Why this exists
---------------
A finding's work lives on a branch, and the ledger's row names a sha. Those are
two records of the same fact, written by different means at different times,
and this programme has already been bitten by them disagreeing: three branches
NAMED after a finding have a tip carrying none of that finding's work, so
integrating by branch name would merge the wrong thing and look like success.
`validate-ledger-rows.py` closed half of that by requiring a CLOSED row to name
a resolvable commit. It cannot ask whether the commit it names is the RIGHT one.

This asks that, and it asks it the only way that means anything: by deriving
the answer from the graph and comparing, rather than by reading the prose
twice.

The rule
--------
A finding's integration tip is the descendant-most commit reached by an
`f`-branch for that finding. Verification branches are never merged -- and that
half needed checking rather than assuming, because six of them carry commits
their finding's f-tip does not reach (four are evidence documents, and three
are the same infrastructure commit cherry-picked, which arrives anyway through
six other findings).

Two conditions, per finding with work:

  NAMED.    The row's status cell names the tip -- by prefix, in either
            direction, since a sha may be written short in one place and long
            in another.
  ANCESTRY. Every resolvable sha the status cell names is an ancestor of that
            tip. A row may legitimately name several rounds; what it may not do
            is name a commit that integrating the tip would not bring in,
            because that is a round whose work is about to be dropped silently.

A finding whose f-branches have forked -- no single commit reaching all the
others -- is reported and not resolved. That needs a human, and guessing which
side to take is exactly the failure this file exists to prevent.

Usage: validate-integration-manifest.py [path-to-ledger] [--base <ref>]
Exit 0 when every row agrees with the graph, 1 otherwise.
"""
from __future__ import annotations

import collections
import pathlib
import re
import subprocess
import sys

DEFAULT = pathlib.Path(__file__).resolve().parents[2] / "docs" / "round-2-remediation-ledger.md"

ROW = re.compile(r"^\| (F-\d\d) \| ([^|]*)\| ([^|]*)\| ([^|]*)\| (.*)\|\s*$")
TOKEN = re.compile(r"`([0-9a-f]{7,40})`")
FBRANCH = re.compile(r"^remediation/f(\d\d)[a-z]?$")


def git(*args: str) -> str:
    return subprocess.run(["git", *args], capture_output=True, text=True).stdout.strip()


def is_commit(sha: str) -> bool:
    return subprocess.run(
        ["git", "cat-file", "-e", f"{sha}^{{commit}}"], capture_output=True
    ).returncode == 0


def is_ancestor(older: str, newer: str) -> bool:
    return subprocess.run(
        ["git", "merge-base", "--is-ancestor", older, newer], capture_output=True
    ).returncode == 0


def tips(base: str) -> tuple[dict[str, str], list[str]]:
    """The integration tip per finding, and the findings that have forked."""
    by_finding: dict[str, dict[str, list[str]]] = collections.defaultdict(dict)
    listing = git("branch", "--list", "remediation/f*", "--format=%(refname:short) %(objectname)")
    for line in listing.splitlines():
        name, obj = line.split()
        match = FBRANCH.match(name)
        if match:
            by_finding[f"F-{match.group(1)}"].setdefault(obj, []).append(name)

    found: dict[str, str] = {}
    forked: list[str] = []
    for finding, commits in by_finding.items():
        live = []
        for obj in commits:
            merge_base = git("merge-base", base, obj)
            if int(git("rev-list", "--count", f"{merge_base}..{obj}") or 0):
                live.append(obj)
        if not live:
            continue  # a branch with no commits yet -- a round in flight
        top = [o for o in live if all(is_ancestor(other, o) for other in live)]
        if len(top) == 1:
            found[finding] = top[0]
        else:
            forked.append(finding)
    return found, forked


def main(argv: list[str]) -> int:
    args = argv[1:]
    base = "HEAD"
    if "--base" in args:
        at = args.index("--base")
        base = args[at + 1]
        args = args[:at] + args[at + 2:]
    path = pathlib.Path(args[0]) if args else DEFAULT
    if not path.is_file():
        print(f"validate-integration-manifest: no ledger at {path}", file=sys.stderr)
        return 1

    found, forked = tips(git("rev-parse", base))
    if not found and not forked:
        # Both, not just `found`. A tree where EVERY finding has forked has an
        # empty `found` and is the opposite of an empty subject -- reporting it
        # as "no branches" would hide the worst state this check can see behind
        # the message for the most harmless one. The self-test caught exactly
        # that, which is the argument for the self-test.
        print(
            "validate-integration-manifest: no remediation/f* branch has any commit "
            "-- either this is the wrong base or the branches are gone, and neither "
            "is a pass",
            file=sys.stderr,
        )
        return 1

    unnamed: list[tuple[str, str, list[str]]] = []
    stranded: list[tuple[str, str, list[str]]] = []
    seen = 0

    for line in path.read_text(encoding="utf-8").splitlines():
        match = ROW.match(line)
        if match is None:
            continue
        finding, _sev, _cls, status, _narrative = match.groups()
        tip = found.get(finding)
        if tip is None:
            continue
        seen += 1
        shas = [s for s in TOKEN.findall(status) if is_commit(s)]
        if not any(s.startswith(tip) or tip.startswith(s) for s in shas):
            unnamed.append((finding, tip, shas))
        strays = [s for s in shas if not is_ancestor(s, tip)]
        if strays:
            stranded.append((finding, tip, strays))

    for finding, tip, shas in unnamed:
        print(
            f"{finding}: the graph says integrate {tip[:7]}, and the status cell names "
            f"{', '.join(s[:7] for s in shas) or 'no resolvable sha'}",
            file=sys.stderr,
        )
    for finding, tip, strays in stranded:
        print(
            f"{finding}: status names {', '.join(s[:7] for s in strays)}, which "
            f"integrating {tip[:7]} would NOT bring in -- that round's work is about "
            f"to be dropped",
            file=sys.stderr,
        )
    for finding in sorted(forked):
        print(
            f"{finding}: its f-branches have forked -- no single commit reaches the "
            f"others, so there is no tip to integrate without a decision",
            file=sys.stderr,
        )

    if unnamed or stranded or forked:
        print(
            f"\n{len(unnamed) + len(stranded) + len(forked)} finding(s) where the "
            f"ledger and the commit graph disagree about what to integrate.",
            file=sys.stderr,
        )
        return 1

    print(
        f"validate-integration-manifest: {seen} findings with work, each naming its "
        f"own integration tip, every sha named an ancestor of it, no forks"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
