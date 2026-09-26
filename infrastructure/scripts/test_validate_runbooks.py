#!/usr/bin/env python3
"""Proof that the runbook gate refuses a command that does not exist.

Why this exists
---------------
Same reason as `test_check_ci_cannot_apply.py`: this validator ran as a CI gate
with nothing establishing that it can go red. It reads artisan signatures out
of the PHP source and fails on any `php artisan <thing>` in operator
documentation that names something else -- and every part of that sentence is a
place it could quietly stop working. The signature regex could stop matching
and leave `defined` empty; the target list could stop finding the runbooks; the
comparison could be against the wrong set.

The first of those the validator already guards (an empty `defined` is a
refusal). The others are what this measures.

Run: python3 infrastructure/scripts/test_validate_runbooks.py
Exit 0 when every case behaves, 1 otherwise.
"""

from __future__ import annotations

import contextlib
import importlib.util
import io
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent

spec = importlib.util.spec_from_file_location(
    "validate_runbooks", HERE / "validate-runbooks.py"
)
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)

COMMAND_PHP = """<?php

final class SweepStaleJobs extends Command
{
    protected $signature = 'lynomia:sweep-stale-jobs {--dry-run}';
}
"""


# The validator reads three corpora and refuses any one of them being empty
# (F-38), so every case gets a file in each by default -- one that names no
# command, so it changes nothing a case is about. A case that is about a corpus
# being empty passes ABSENT for it.
ABSENT: dict[str, str] = {}
DEFAULT_RUNBOOKS = {"README.md": "Operator runbooks live here.\n"}
DEFAULT_INFRA_DOCS = {"README.md": "The infrastructure tree.\n"}
DEFAULT_PLAYBOOKS = {"site.yml": "---\n- hosts: all\n  tasks: []\n"}


def run(
    *,
    php: dict[str, str] | None = None,
    runbooks: dict[str, str] | None = None,
    infra_docs: dict[str, str] | None = None,
    playbooks: dict[str, str] | None = None,
) -> tuple[int, str]:
    """Build a synthetic repository and run the validator over it."""
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        source = root / "apps" / "control-plane" / "src"
        source.mkdir(parents=True)
        for name, body in (php if php is not None else {"Sweep.php": COMMAND_PHP}).items():
            (source / name).write_text(body)
        (root / "apps" / "control-plane" / "app").mkdir(parents=True)

        infra = root / "infrastructure"
        (infra / "ansible").mkdir(parents=True)
        for name, body in (DEFAULT_INFRA_DOCS if infra_docs is None else infra_docs).items():
            (infra / name).write_text(body)
        for name, body in (DEFAULT_PLAYBOOKS if playbooks is None else playbooks).items():
            (infra / "ansible" / name).write_text(body)

        books = root / "docs" / "runbooks"
        if runbooks is not ABSENT:
            books.mkdir(parents=True)
        for name, body in (DEFAULT_RUNBOOKS if runbooks is None else runbooks).items():
            (books / name).write_text(body)

        err = io.StringIO()
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(err):
            code = validator.main(["validate-runbooks.py", str(infra)])
        return code, err.getvalue()


CASES: list[tuple[str, dict, str | None]] = [
    (
        "a runbook naming a command the source defines passes",
        {"runbooks": {"stale.md": "Run `php artisan lynomia:sweep-stale-jobs` on the box.\n"}},
        None,
    ),
    (
        "a runbook naming a command nothing defines is refused",
        {"runbooks": {"stale.md": "Run `php artisan lynomia:sweep-stale-job` on the box.\n"}},
        "no such command",
    ),
    (
        "the failure names the file and the line",
        {"runbooks": {"stale.md": "first line\nsecond line\n`php artisan lynomia:nope`\n"}},
        "docs/runbooks/stale.md:3",
    ),
    (
        "a framework command nothing in this source defines is still allowed",
        {"runbooks": {"deploy.md": "`php artisan migrate --force`\n"}},
        None,
    ),
    (
        "a command invented near a framework name is not laundered by it",
        {"runbooks": {"deploy.md": "`php artisan migrate:everything`\n"}},
        "no such command",
    ),
    (
        "a dangling command in the infrastructure docs is caught too",
        {"infra_docs": {"README.md": "`php artisan lynomia:not-a-thing`\n"}},
        "no such command",
    ),
    (
        "a dangling command in an ansible playbook is caught too",
        {"playbooks": {"site.yml": "  - shell: php artisan lynomia:also-not-a-thing\n"}},
        "no such command",
    ),
    (
        "a source tree with no command signatures at all is refused",
        {"php": {"Empty.php": "<?php\n\nfinal class Nothing {}\n"}},
        "found no artisan command signatures",
    ),
    (
        "documentation that references no command at all is refused",
        {"runbooks": {"prose.md": "Restart the worker from the deployment controller.\n"}},
        "no `php artisan` invocation anywhere",
    ),
    # F-38. The three corpora are read as one union, and a union stays
    # non-empty while any one of them does: with docs/runbooks moved aside the
    # gate went on printing a green line over the infrastructure tree's single
    # invocation, having read not one runbook. Each subject is asserted on its
    # own.
    (
        "with docs/runbooks moved away the gate refuses rather than reading the rest",
        {
            "runbooks": ABSENT,
            "infra_docs": {"README.md": "`php artisan lynomia:sweep-stale-jobs`\n"},
        },
        "docs/runbooks",
    ),
    (
        "an infrastructure tree with no markdown in it is refused",
        {
            "runbooks": {"stale.md": "`php artisan lynomia:sweep-stale-jobs`\n"},
            "infra_docs": ABSENT,
        },
        "infrastructure/**/*.md",
    ),
    (
        "an ansible tree with no YAML in it is refused",
        {
            "runbooks": {"stale.md": "`php artisan lynomia:sweep-stale-jobs`\n"},
            "playbooks": ABSENT,
        },
        "infrastructure/ansible/**/*.yml",
    ),
]

# The table above is this self-test's subject; emptied, it would print
# `0/0 passed` and exit 0. The count is literal source in this file, maintained
# by whoever edits the table, so adding or removing a case is a deliberate edit
# of this number too.
EXPECTED_CASES = 12


def main() -> int:
    if len(CASES) != EXPECTED_CASES:
        print(
            f"the case table holds {len(CASES)} case(s) and this file says "
            f"{EXPECTED_CASES}; change both together or neither"
        )
        return 1
    failures = 0
    for name, kwargs, expected in CASES:
        code, err = run(**kwargs)
        if expected is None:
            ok = code == 0 and not err.strip()
        else:
            ok = code == 1 and expected in err
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got exit {code} and stderr:\n{err}")
    print(f"\n{len(CASES) - failures}/{len(CASES)} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
