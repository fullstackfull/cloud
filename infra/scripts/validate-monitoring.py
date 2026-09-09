#!/usr/bin/env python3
"""Keep the alert rules pointed at metrics that exist.

An alert on a metric nobody emits never fires. It looks like coverage in a
review and is silence in an incident, which is the worst of both. This script
reads the metric families the control plane actually exports out of the PHP
source and fails on any rule expression naming something else.

It also checks that every rule carries a runbook annotation pointing at a file
that exists, because "page somebody at 4am with no instructions" is not
monitoring.

Exit status 0 when the configuration is consistent, 1 otherwise.
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

METRIC_IN_SOURCE = re.compile(r"lynomia_[a-z0-9_]+")
METRIC_IN_EXPR = re.compile(r"lynomia_[a-z0-9_]+")


def exported_metrics(repo_root: Path) -> set[str]:
    source = repo_root / "apps" / "control-plane" / "src"
    found: set[str] = set()
    for path in source.rglob("*.php"):
        found.update(METRIC_IN_SOURCE.findall(path.read_text(errors="replace")))
    return found


def main(argv: list[str]) -> int:
    infra_root = Path(argv[1]) if len(argv) > 1 else Path(__file__).resolve().parent.parent
    repo_root = infra_root.parent

    exported = exported_metrics(repo_root)
    if not exported:
        print("found no lynomia_* metrics in the control plane source", file=sys.stderr)
        return 1

    problems: list[str] = []
    rules_dir = infra_root / "monitoring" / "prometheus" / "rules"
    rule_files = sorted(rules_dir.glob("*.yml"))
    if not rule_files:
        print(f"no rule files under {rules_dir}", file=sys.stderr)
        return 1

    total_rules = 0
    for path in rule_files:
        document = yaml.safe_load(path.read_text()) or {}
        for group in document.get("groups", []):
            for rule in group.get("rules", []):
                name = rule.get("alert") or rule.get("record") or "<unnamed>"
                total_rules += 1

                expr = str(rule.get("expr", ""))
                for metric in set(METRIC_IN_EXPR.findall(expr)):
                    if metric not in exported:
                        problems.append(
                            f"{path.name}: {name} alerts on {metric}, which the "
                            f"control plane does not export"
                        )

                annotations = rule.get("annotations") or {}
                runbook = annotations.get("runbook")
                if not runbook:
                    problems.append(f"{path.name}: {name} has no runbook annotation")
                elif not (repo_root / runbook).exists():
                    problems.append(
                        f"{path.name}: {name} points at {runbook}, which does not exist"
                    )

                if not rule.get("labels", {}).get("severity"):
                    problems.append(f"{path.name}: {name} has no severity label")

    # Every config file must parse, and none may carry an inline secret.
    secretish = re.compile(
        r"(?i)^\s*[a-z_]*(password|token|secret|api_key)\s*:\s*(?!$)(?!\$\{)(?!null)\S",
        re.MULTILINE,
    )
    for path in sorted((infra_root / "monitoring").rglob("*.yml")):
        try:
            yaml.safe_load(path.read_text())
        except yaml.YAMLError as error:
            problems.append(f"{path.relative_to(infra_root)}: does not parse — {error}")
        if secretish.search(path.read_text()):
            problems.append(
                f"{path.relative_to(infra_root)}: has an inline credential; use a "
                f"*_file field or an environment placeholder"
            )

    print(
        f"{len(rule_files)} rule file(s), {total_rules} rule(s), "
        f"{len(exported)} exported metric families"
    )
    for problem in problems:
        print(f"  FAIL {problem}", file=sys.stderr)

    return 1 if problems else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
