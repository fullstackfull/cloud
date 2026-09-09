#!/usr/bin/env python3
"""Keep the alert rules pointed at metrics that exist.

An alert on a metric nobody emits never fires. It looks like coverage in a
review and is silence in an incident, which is the worst of both.

A lynomia_* metric legitimately comes from one of two places, so this checks
both before calling a rule dead:

  1. The control plane's own /metrics endpoint, discovered by reading the
     metric names out of the PHP source.
  2. A textfile collector on a machine that has no Prometheus endpoint of its
     own — PBS, for instance. Those have no code in this repository at all, so
     their contract is declared in infrastructure/monitoring/README.md and
     parsed from there.

It also checks that every rule says where the operator should look — a
`runbook` path inside this repository, which must exist, or a `runbook_url`
pointing outside it, which is taken on trust — because "page somebody at 4am
with no instructions" is not monitoring.

Finally it checks that the directories infrastructure/README.md claims exist
actually do. That check lives here because the first thing it caught was a
declared textfile collector whose directory was never created, which is exactly
how six backup alerts came to be incapable of firing.

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
    """Metric families the control plane's own endpoint exports."""
    source = repo_root / "apps" / "control-plane" / "src"
    found: set[str] = set()
    for path in source.rglob("*.php"):
        found.update(METRIC_IN_SOURCE.findall(path.read_text(errors="replace")))
    return found


def collector_metrics(infra_root: Path) -> set[str]:
    """Metric families a textfile collector promises, per the declared contract.

    These have no PHP behind them by design. The README is the contract, so the
    README is what this reads.
    """
    readme = infra_root / "monitoring" / "README.md"
    if not readme.exists():
        return set()
    return set(METRIC_IN_SOURCE.findall(readme.read_text(errors="replace")))


def collector_homes(infra_root: Path) -> list[str]:
    """Where the monitoring README says each textfile collector lives.

    A collector contract is a promise that something writes those series. The
    promise is only worth anything if the thing exists, so the README is made to
    name a directory and this checks that the directory is there.
    """
    readme = infra_root / "monitoring" / "README.md"
    if not readme.exists():
        return []
    return sorted(set(re.findall(r"belongs to\s+`infrastructure/([a-z_]+)`", readme.read_text())))


def declared_directories(infra_root: Path) -> list[str]:
    """Directory names infrastructure/README.md's tree block claims exist.

    Top-level entries only: a line beginning with a tree connector at column
    zero. Nested entries are prefixed with a vertical bar and belong to the
    directory above them, which is checked on its own line.
    """
    readme = infra_root / "README.md"
    if not readme.exists():
        return []

    names: set[str] = set()
    for line in readme.read_text().splitlines():
        match = re.match(r"^(?:\u251c\u2500\u2500|\u2514\u2500\u2500)\s+(.*)$", line)
        if not match:
            continue
        # One line may list several siblings: "proxmox/ pbs/ pxe/ ...".
        names.update(re.findall(r"\b([a-z_]+)/", match.group(1)))
    return sorted(names)


def main(argv: list[str]) -> int:
    infra_root = Path(argv[1]) if len(argv) > 1 else Path(__file__).resolve().parent.parent
    repo_root = infra_root.parent

    exported = exported_metrics(repo_root)
    if not exported:
        print("found no lynomia_* metrics in the control plane source", file=sys.stderr)
        return 1
    declared = collector_metrics(infra_root)
    emitted = exported | declared

    problems: list[str] = []
    unimplemented: list[str] = []
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
                # A recording rule computes a series; it never pages anybody, so
                # it has no runbook and no severity, and asking for either is a
                # bug in this check rather than a gap in the configuration.
                is_alert = "alert" in rule

                expr = str(rule.get("expr", ""))
                for metric in set(METRIC_IN_EXPR.findall(expr)):
                    if metric not in emitted:
                        problems.append(
                            f"{path.name}: {name} alerts on {metric}, which nothing "
                            f"exports and no collector contract declares"
                        )

                if not is_alert:
                    continue

                annotations = rule.get("annotations") or {}
                runbook = annotations.get("runbook")
                runbook_url = annotations.get("runbook_url")
                if not runbook and not runbook_url:
                    problems.append(
                        f"{path.name}: {name} has neither a runbook nor a runbook_url"
                    )
                elif runbook and not (repo_root / runbook).exists():
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

    # A directory the README promises and does not have is how a collector
    # contract ends up with nothing implementing it.
    for name in declared_directories(infra_root):
        if not (infra_root / name).is_dir():
            problems.append(
                f"README.md names infrastructure/{name}/, which does not exist"
            )

    # A collector contract with nothing implementing it is a set of alerts that
    # can never fire, which reads as coverage and is not. The gap may stand —
    # some collectors need hardware nobody has yet — but it may not stand
    # silently: the README has to say so in as many words, next to the contract.
    monitoring_readme = infra_root / "monitoring" / "README.md"
    readme_text = monitoring_readme.read_text() if monitoring_readme.exists() else ""
    for name in collector_homes(infra_root):
        if (infra_root / name).is_dir():
            continue
        acknowledged = re.search(
            rf"NOT IMPLEMENTED[^\n]*`?infrastructure/{name}`?"
            rf"|`?infrastructure/{name}`?[^\n]*NOT IMPLEMENTED",
            readme_text,
        )
        if acknowledged:
            unimplemented.append(name)
        else:
            problems.append(
                f"monitoring/README.md declares a textfile collector belonging to "
                f"infrastructure/{name}, which does not exist, and does not say so. "
                f"Every alert on those series is incapable of firing; mark the "
                f"contract NOT IMPLEMENTED next to it, with the blocker."
            )

    print(
        f"{len(rule_files)} rule file(s), {total_rules} rule(s), "
        f"{len(exported)} exported by the control plane, "
        f"{len(declared - exported)} declared by a collector contract"
    )
    for name in unimplemented:
        print(
            f"  NOTE the textfile collector for infrastructure/{name} is declared "
            f"and not implemented; alerts on its series cannot fire"
        )
    for problem in problems:
        print(f"  FAIL {problem}", file=sys.stderr)

    return 1 if problems else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
