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


CASES: list[tuple[str, dict[str, str] | None, str | tuple[str, ...] | None]] = [
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
    # F-38. The gate used to skip, before inspecting anything, every step whose
    # body merely CONTAINED its own file name -- and it did so before stripping
    # comments, so a comment was enough. The four cases below each went green
    # over an apply while the exemption stood.
    (
        "a comment naming this gate does not exempt the apply below it",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          # guarded by check-ci-cannot-apply.py elsewhere
          tofu apply -auto-approve
"""
        },
        "applies OpenTofu",
    ),
    (
        "a real command that mentions this gate is still inspected",
        {"ci.yml": step("python3 infrastructure/scripts/check-ci-cannot-apply.py . && tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "two applies that each mention this gate are both caught",
        {
            "ci.yml": """
name: ci
on: [push]
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - name: first apply
        run: |
          # check-ci-cannot-apply.py has approved this workflow
          tofu apply -auto-approve
      - name: second apply
        run: python3 check-ci-cannot-apply.py . ; terraform apply
"""
        },
        ("step 'first apply' applies OpenTofu", "step 'second apply' applies OpenTofu"),
    ),
    (
        "a step whose `run:` is empty reaches the empty-subject refusal",
        {
            "ci.yml": """
name: ci
on: [push]
jobs:
  hollow:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
      - name: nothing to run
        run: ""
"""
        },
        "inspected no run steps",
    ),
    # F-38. Actions runs `.yaml` exactly as it runs `.yml`, and the gate used
    # to glob only the latter: a `deploy.yaml` that applied infrastructure was
    # a file this check never opened, beside a `ci.yml` it passed.
    (
        "an apply in a `.yaml` workflow is caught",
        {"ci.yml": GOOD, "deploy.yaml": step("tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a tree whose only workflow is `.yaml` is inspected, not refused as empty",
        {"ci.yaml": GOOD},
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


# The table above is this self-test's subject, and a self-test over an emptied
# table prints `0/0 passed` and exits 0 -- the very shape the gate it proves
# exists to refuse. The count is literal source in this file, maintained by
# whoever edits the table, so adding or removing a case is a deliberate edit
# of this number too.
EXPECTED_CASES = 21


def main() -> int:
    if len(CASES) != EXPECTED_CASES:
        print(
            f"the case table holds {len(CASES)} case(s) and this file says "
            f"{EXPECTED_CASES}; change both together or neither"
        )
        return 1
    failures = 0
    for name, workflows, expected in CASES:
        code, err = bare() if workflows is None else run(workflows)
        if expected is None:
            ok = code == 0 and not err.strip()
        else:
            wanted = (expected,) if isinstance(expected, str) else expected
            ok = code == 1 and all(each in err for each in wanted)
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got exit {code} and stderr:\n{err}")
    print(f"\n{len(CASES) - failures}/{len(CASES)} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
