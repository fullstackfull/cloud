#!/usr/bin/env python3
"""CI validates infrastructure; a person applies it.

A pipeline that can reimage a node on merge is a pipeline that eventually will,
on a branch nobody meant to merge. This parses the workflow files and inspects
what the steps actually run, so it cannot be fooled by — or trip over — a
pattern that merely appears in a comment or in this check's own configuration.

Exit status 0 when no workflow applies infrastructure, 1 otherwise.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover
    print("PyYAML is required: pip install pyyaml", file=sys.stderr)
    raise SystemExit(2)

# Commands that change real infrastructure rather than describing it.
APPLYING = [
    (re.compile(r"\b(tofu|terraform)\s+apply\b"), "applies OpenTofu"),
    (re.compile(r"\b(tofu|terraform)\s+destroy\b"), "destroys OpenTofu resources"),
    (re.compile(r"scripts/apply\.sh"), "runs the apply verb"),
    (re.compile(r"reimage-node\.yml"), "runs the reimage playbook"),
    (re.compile(r"deploy-control-plane\.yml"), "runs the deploy playbook"),
    # A check-mode run is a plan and is fine; a bare ansible-playbook is not.
    (re.compile(r"ansible-playbook(?![^\n]*--(check|syntax-check))"), "runs a playbook outside check mode"),
]

# This script is itself named in the step that runs it. That is not an apply.
SELF = "check-ci-cannot-apply.py"


def steps_of(workflow: dict):
    for job_name, job in (workflow.get("jobs") or {}).items():
        for step in job.get("steps") or []:
            yield job_name, step


def main(argv: list[str]) -> int:
    root = Path(argv[1]) if len(argv) > 1 else Path.cwd()
    workflows = sorted((root / ".github" / "workflows").glob("*.yml"))
    if not workflows:
        print("no workflow files found", file=sys.stderr)
        return 1

    problems: list[str] = []
    checked = 0

    for path in workflows:
        workflow = yaml.safe_load(path.read_text()) or {}
        for job_name, step in steps_of(workflow):
            command = step.get("run")
            if not command or SELF in command:
                continue
            checked += 1
            # Strip comment lines: a comment explaining why we do not apply is
            # not an apply. Then rejoin shell line-continuations, because a
            # command split over three lines with backslashes is still one
            # command, and reading it as three is how `ansible-playbook ... \
            # --syntax-check` gets mistaken for a real run.
            body = "\n".join(
                line for line in command.splitlines() if not line.strip().startswith("#")
            )
            body = re.sub(r"\\\n\s*", " ", body)
            for pattern, what in APPLYING:
                if pattern.search(body):
                    problems.append(
                        f"{path.name}: job '{job_name}', step "
                        f"'{step.get('name', '<unnamed>')}' {what}"
                    )

    print(f"{len(workflows)} workflow file(s), {checked} run step(s) inspected")
    for problem in problems:
        print(f"  FAIL {problem}", file=sys.stderr)
    if problems:
        print(
            "CI validates infrastructure; a person applies it from the "
            "deployment controller.",
            file=sys.stderr,
        )

    return 1 if problems else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
