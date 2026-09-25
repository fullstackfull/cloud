#!/usr/bin/env python3
"""Proof that the ledger gate refuses each shape it was written for.

Why this exists
---------------
`validate-ledger-rows.py` was written after two agents were dispatched with
briefs drawn from a row's narrative that no longer matched its status, and its
completeness half was added after eight CLOSED rows were found naming no
integrable commit. Both halves were then trusted on the strength of running
green -- which is the reasoning that produced the eight rows in the first
place.

It is also the awkward case among the validators here, because it asks git
questions. That makes it the one most likely to go quietly wrong in a way
nothing notices: in a shallow clone `git cat-file -e` answers "no" for every
older sha, and a check that answers "no" to everything is as useless as one
that answers "yes". So every case below runs against a real throwaway
repository with real commits and real branches, rather than against a stub.

Run: python3 infrastructure/scripts/test_validate_ledger_rows.py
Exit 0 when every case behaves, 1 otherwise.
"""

from __future__ import annotations

import contextlib
import importlib.util
import io
import os
import subprocess
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent

spec = importlib.util.spec_from_file_location(
    "validate_ledger_rows", HERE / "validate-ledger-rows.py"
)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

HEADER = """| Finding | Severity | Class | Status | Narrative |
|---|---|---|---|---|
"""

# A sha that is the right shape and is in no repository. Built by hand rather
# than taken from anywhere, so it cannot accidentally become resolvable.
ABSENT = "0" * 40


def repository(rows: str, *, branches: tuple[str, ...] = ()) -> tuple[int, str, str]:
    """Run the gate over `rows` inside a throwaway repository, and return
    (exit code, stdout, stderr). The repository's own first commit's sha is
    substituted for the token REAL_SHA anywhere in `rows`."""
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        env = {
            **os.environ,
            "GIT_AUTHOR_NAME": "t", "GIT_AUTHOR_EMAIL": "t@example.invalid",
            "GIT_COMMITTER_NAME": "t", "GIT_COMMITTER_EMAIL": "t@example.invalid",
        }
        def git(*args: str) -> str:
            return subprocess.run(
                ["git", "-C", str(root), *args],
                capture_output=True, text=True, env=env, check=True,
            ).stdout.strip()

        git("init", "--quiet", "-b", "main")
        (root / "seed").write_text("seed\n")
        git("add", "seed")
        git("commit", "--quiet", "-m", "seed")
        real = git("rev-parse", "--short=7", "HEAD")
        for branch in branches:
            git("branch", branch)

        ledger = root / "ledger.md"
        ledger.write_text(HEADER + rows.replace("REAL_SHA", real))

        out, err = io.StringIO(), io.StringIO()
        cwd = Path.cwd()
        try:
            os.chdir(root)
            with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                code = gate.main(["validate-ledger-rows.py", str(ledger)])
        finally:
            os.chdir(cwd)
        return code, out.getvalue(), err.getvalue()


CASES: list[tuple[str, dict, str | None]] = [
    (
        "a CLOSED row naming a resolvable sha its narrative also names passes",
        {"rows": "| F-01 | High | GAP | **`CLOSED`** — round one (`REAL_SHA`) upheld | Round one at `REAL_SHA` did the thing. |\n"},
        None,
    ),
    (
        "an OPEN row needs no sha at all",
        {"rows": "| F-02 | High | GAP | `OPEN` — dispatched | Nothing delivered yet. |\n"},
        None,
    ),
    (
        "a status token the narrative never mentions is refused",
        {"rows": "| F-03 | High | GAP | `OPEN` — verified in `v03b` | Round one was dispatched. |\n"},
        "status cell names v03b, absent from this row's narrative",
    ),
    (
        "a CLOSED row naming no sha at all is refused",
        {"rows": "| F-04 | High | GAP | **`CLOSED`** — upheld by `v04a` | Verified in `v04a`, all good. |\n"},
        "names no sha that resolves to a commit",
    ),
    (
        "a CLOSED row naming a plausible sha that is in no repository is refused",
        {"rows": f"| F-05 | High | GAP | **`CLOSED`** — round one (`{ABSENT}`) | Round one at `{ABSENT}` did the thing. |\n"},
        "names no sha that resolves to a commit",
    ),
    (
        "a slug is not accepted in place of a sha",
        {"rows": "| F-06 | High | GAP | **`CLOSED`** — round one (`f06a`) | Round one in `f06a` did the thing. |\n"},
        "names no sha that resolves to a commit",
    ),
    (
        "a pre-branch-regime row may say so in the agreed words",
        {"rows": "| F-07 | High | GAP | **`CLOSED`** — already on the integration branch | Landed before the branch-per-finding regime. |\n"},
        None,
    ),
    (
        "those words are refused when the finding does hold a branch",
        {
            "rows": "| F-08 | High | GAP | **`CLOSED`** — already on the integration branch | Landed before the regime, supposedly. |\n",
            "branches": ("remediation/f08",),
        },
        "claims to be already on the integration branch, but",
    ),
    (
        "a branch for a DIFFERENT finding does not block the phrase",
        {
            "rows": "| F-09 | High | GAP | **`CLOSED`** — already on the integration branch | Landed before the regime. |\n",
            "branches": ("remediation/f08",),
        },
        None,
    ),
    (
        "a short sha in the status matches a long one in the narrative",
        {"rows": "| F-10 | High | GAP | **`CLOSED`** — round one (`REAL_SHA`) | Round one at `REAL_SHA` did the thing. |\n"},
        None,
    ),
    (
        "a cell naming two statuses is refused",
        {"rows": "| F-14 | High | GAP | `OPEN` \u2014 **`CLOSED`** \u2014 round one (`REAL_SHA`) | Round one at `REAL_SHA`. |\n"},
        "names 2 statuses",
    ),
    (
        "and it is refused even though it would otherwise pass as CLOSED",
        # The point of the check. This cell's sha resolves and its narrative
        # names it, so both other halves are satisfied -- and the completeness
        # half would never have run, because it anchors on the first word.
        {"rows": "| F-15 | High | GAP | `PARTIAL` \u2014 **`CLOSED`** \u2014 (`REAL_SHA`) | Round one at `REAL_SHA`. |\n"},
        "names 2 statuses",
    ),
    (
        "a cell naming no status at all is refused",
        {"rows": "| F-16 | High | GAP | round one landed | Round one landed. |\n"},
        "names none of the five allowed statuses",
    ),
    (
        "the word closed in a sentence is not a second status",
        {"rows": "| F-17 | High | GAP | **`CLOSED`** \u2014 (`REAL_SHA`), both limbs closed | Round one at `REAL_SHA` closed both. |\n"},
        None,
    ),
    (
        "a table whose shape has changed is refused rather than passed",
        {"rows": "F-11 | High | GAP | CLOSED | no pipes at the ends\n"},
        "matched no rows at all",
    ),
    (
        "one bad row among good ones still fails the run",
        {"rows": (
            "| F-12 | High | GAP | **`CLOSED`** — round one (`REAL_SHA`) | Round one at `REAL_SHA`. |\n"
            "| F-13 | High | GAP | **`CLOSED`** — upheld | Nothing integrable named. |\n"
        )},
        "names no sha that resolves to a commit",
    ),
]


def main() -> int:
    failures = 0
    for name, kwargs, expected in CASES:
        code, out, err = repository(**kwargs)
        if expected is None:
            ok = code == 0 and not err.strip()
        else:
            ok = code == 1 and expected in err
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got exit {code}\n      stdout: {out.strip()}\n      stderr: {err.strip()}")
    print(f"\n{len(CASES) - failures}/{len(CASES)} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
