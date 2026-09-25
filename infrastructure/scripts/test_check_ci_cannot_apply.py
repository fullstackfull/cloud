#!/usr/bin/env python3
"""Proof that the no-apply gate refuses what its name claims to refuse.

Why this exists
---------------
`check-ci-cannot-apply.py` is one of four validators the Infrastructure job
runs as a gate. Of the four, one had a self-test. The other three -- this one,
`validate-monitoring.py` and `validate-runbooks.py` -- were trusted on the
strength of being green, which is the exact reasoning F-38 was opened about: a
gate that scans an empty subject passes silently and reads, in review, exactly
like a gate that found nothing wrong.

Being green is not evidence. This drives the validator over synthetic
workflow trees and checks it goes RED for each failure its name promises, and
stays GREEN for each legitimate shape that superficially resembles one --
because a gate that cannot tell `ansible-playbook --check` from
`ansible-playbook` is a gate somebody will disable.

Run: python3 infrastructure/scripts/test_check_ci_cannot_apply.py
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
    "check_ci_cannot_apply", HERE / "check-ci-cannot-apply.py"
)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

# A workflow whose every step describes infrastructure rather than changing it.
GOOD = """
name: ci
on: [push]
jobs:
  infrastructure:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
      - name: OpenTofu is formatted and valid
        run: |
          tofu fmt -check -recursive infrastructure/tofu
          tofu validate
      - name: Ansible syntax
        run: ansible-playbook -i inventories/staging/hosts.yml playbooks/site.yml --syntax-check
"""


def run(workflows: dict[str, str]) -> tuple[int, str]:
    """Build a tree with these workflow files and return (exit code, stderr)."""
    with tempfile.TemporaryDirectory() as tmp:
        directory = Path(tmp) / ".github" / "workflows"
        directory.mkdir(parents=True)
        for name, body in workflows.items():
            (directory / name).write_text(body)
        err = io.StringIO()
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(err):
            code = gate.main(["check-ci-cannot-apply.py", tmp])
        return code, err.getvalue()


def bare() -> tuple[int, str]:
    """No `.github/workflows` at all -- the empty-subject case."""
    with tempfile.TemporaryDirectory() as tmp:
        err = io.StringIO()
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(err):
            code = gate.main(["check-ci-cannot-apply.py", tmp])
        return code, err.getvalue()


def step(run_body: str) -> str:
    """GOOD with one more step carrying `run_body`."""
    return GOOD + f"""      - name: the step under test
        run: {run_body}
"""


CASES: list[tuple[str, dict[str, str] | None, str | None]] = [
    ("a workflow that only validates passes", {"ci.yml": GOOD}, None),
    (
        "`tofu apply` is caught",
        {"ci.yml": step("tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "`terraform apply` is caught under its other name",
        {"ci.yml": step("terraform apply")},
        "applies OpenTofu",
    ),
    (
        "`tofu destroy` is caught",
        {"ci.yml": step("tofu destroy -auto-approve")},
        "destroys OpenTofu resources",
    ),
    (
        "the apply script is caught by path",
        {"ci.yml": step("infrastructure/scripts/apply.sh production")},
        "runs the apply verb",
    ),
    (
        "a bare ansible-playbook is caught",
        {"ci.yml": step("ansible-playbook -i inventories/staging/hosts.yml playbooks/site.yml")},
        "runs a playbook outside check mode",
    ),
    (
        "ansible-playbook --check is not an apply",
        {"ci.yml": step("ansible-playbook -i hosts.yml site.yml --check")},
        None,
    ),
    (
        "an apply in a SECOND file is caught, not just the first",
        {"ci.yml": GOOD, "release.yml": step("tofu apply")},
        "applies OpenTofu",
    ),
    (
        "a pattern inside a comment is not an apply",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          # We deliberately never run `tofu apply` here; a person does that.
          tofu plan
"""
        },
        None,
    ),
    (
        "a comment does not launder the apply on the line below it",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          # tofu apply is forbidden in CI
          tofu apply -auto-approve
"""
        },
        "applies OpenTofu",
    ),
    (
        "a --syntax-check split across a line continuation is not an apply",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          ansible-playbook -i inventories/staging/hosts.yml \\
            playbooks/site.yml \\
            --syntax-check
"""
        },
        None,
    ),
    (
        "a continuation that does NOT reach a check flag is still an apply",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          ansible-playbook -i inventories/staging/hosts.yml \\
            playbooks/site.yml
"""
        },
        "runs a playbook outside check mode",
    ),
    (
        "the step that runs this gate is not itself an apply",
        {"ci.yml": step("python3 infrastructure/scripts/check-ci-cannot-apply.py .")},
        None,
    ),
    (
        "a `uses:` step with no `run:` is skipped rather than crashed on",
        {
            "ci.yml": """
name: ci
on: [push]
jobs:
  only-actions:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
      - uses: opentofu/setup-opentofu@v1
"""
        },
        # Documented below: this is the empty-subject case and the gate must
        # refuse it rather than pass it.
        "inspected no run steps",
    ),
    ("no workflow directory at all is refused", None, "no workflow files found"),
]


def main() -> int:
    failures = 0
    for name, workflows, expected in CASES:
        code, err = bare() if workflows is None else run(workflows)
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
