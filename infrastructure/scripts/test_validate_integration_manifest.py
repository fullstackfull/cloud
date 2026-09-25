#!/usr/bin/env python3
"""Proof that the manifest validator refuses each disagreement it names.

Every case builds a throwaway repository with real branches and real commits,
because this validator's entire subject is the commit graph and a stub would
prove nothing about it. In particular: `git merge-base --is-ancestor` on a
shallow or empty repository answers the same way for everything, and a check
that answers the same way for everything is not a check.

Run: python3 infrastructure/scripts/test_validate_integration_manifest.py
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
    "validate_integration_manifest", HERE / "validate-integration-manifest.py"
)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

HEADER = """| Finding | Severity | Class | Status | Narrative |
|---|---|---|---|---|
"""


def scenario(build, rows: str) -> tuple[int, str, str]:
    """`build(git)` shapes the repository; `rows` may use $round1, $round2, $side."""
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
        git("commit", "--quiet", "-m", "base")
        shas = build(git, root)

        body = rows
        for key, value in shas.items():
            body = body.replace(f"${key}", value)
        ledger = root / "ledger.md"
        ledger.write_text(HEADER + body)

        out, err = io.StringIO(), io.StringIO()
        cwd = Path.cwd()
        try:
            os.chdir(root)
            with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
                code = gate.main(["validate-integration-manifest.py", str(ledger)])
        finally:
            os.chdir(cwd)
        return code, out.getvalue(), err.getvalue()


def commit(git, root, name: str, message: str) -> str:
    (root / name).write_text(message + "\n")
    git("add", name)
    git("commit", "--quiet", "-m", message)
    return git("rev-parse", "--short=7", "HEAD")


def two_rounds(git, root):
    """remediation/f07 -> round one; remediation/f07b -> round one, then two."""
    git("checkout", "--quiet", "-b", "remediation/f07")
    one = commit(git, root, "a", "round one")
    git("checkout", "--quiet", "-b", "remediation/f07b")
    two = commit(git, root, "b", "round two")
    git("checkout", "--quiet", "main")
    return {"round1": one, "round2": two}


def forked(git, root):
    """Two f-branches for one finding, neither reaching the other."""
    git("checkout", "--quiet", "-b", "remediation/f07")
    left = commit(git, root, "a", "left")
    git("checkout", "--quiet", "-B", "remediation/f07b", "main")
    right = commit(git, root, "b", "right")
    git("checkout", "--quiet", "main")
    return {"round1": left, "round2": right}


def verifier_only(git, root):
    """The f-tip, plus a v-branch carrying a commit the f-tip does not reach."""
    git("checkout", "--quiet", "-b", "remediation/f07")
    one = commit(git, root, "a", "round one")
    git("checkout", "--quiet", "-B", "remediation/v07b", "main")
    side = commit(git, root, "c", "verification evidence")
    git("checkout", "--quiet", "main")
    return {"round1": one, "side": side}


CASES: list[tuple[str, object, str, str | None]] = [
    (
        "a row naming the latest round's tip passes",
        two_rounds,
        "| F-07 | High | GAP | **`CLOSED`** — round two (`$round2`), round one (`$round1`) | both rounds |\n",
        None,
    ),
    (
        "a row naming only the OLD round is refused",
        two_rounds,
        "| F-07 | High | GAP | **`CLOSED`** — round one (`$round1`) | round one |\n",
        "the graph says integrate",
    ),
    (
        "a row naming a sha the tip does not reach is refused",
        verifier_only,
        "| F-07 | High | GAP | **`CLOSED`** — (`$round1`) and (`$side`) | both |\n",
        "would NOT bring in",
    ),
    (
        "a verification branch alone does not make a finding integrable",
        # The f-tip is round one; the v-branch's commit is simply not named, and
        # that is the normal case, not a failure.
        verifier_only,
        "| F-07 | High | GAP | **`CLOSED`** — round one (`$round1`) | round one |\n",
        None,
    ),
    (
        "a genuinely forked finding is reported rather than resolved",
        forked,
        "| F-07 | High | GAP | **`CLOSED`** — (`$round1`) | round one |\n",
        "have forked",
    ),
    (
        "a finding with no branch at all is not this check's business",
        two_rounds,
        "| F-07 | High | GAP | **`CLOSED`** — round two (`$round2`) | round two |\n"
        "| F-08 | High | GAP | **`CLOSED`** — already on the integration branch | pre-regime |\n",
        None,
    ),
]


def main() -> int:
    failures = 0
    for name, build, rows, expected in CASES:
        code, out, err = scenario(build, rows)
        if expected is None:
            ok = code == 0 and not err.strip()
        else:
            ok = code == 1 and expected in err
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got exit {code}\n      stdout: {out.strip()}\n      stderr: {err.strip()}")

    # And the empty-subject case, which needs no ledger shaping: a repository
    # with no remediation branch at all must refuse rather than pass.
    def bare(git, root):
        return {}

    code, out, err = scenario(bare, "| F-07 | High | GAP | **`CLOSED`** — x | y |\n")
    ok = code == 1 and "no remediation/f* branch has any commit" in err
    print(f"{'PASS' if ok else 'FAIL'}  a tree with no remediation branches is refused, not passed")
    if not ok:
        failures += 1
        print(f"      got exit {code}, stderr: {err.strip()}")

    total = len(CASES) + 1
    print(f"\n{total - failures}/{total} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
