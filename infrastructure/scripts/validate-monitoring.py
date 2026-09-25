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

It walks Alertmanager's route tree for every alert, the way Alertmanager walks
it, and requires each walk to end at a receiver the file defines. A few alerts
are pinned further, in PINNED_ROUTES: their destination is itself the fix for a
finding, so the receiver they reach and the series they read are asserted
rather than merely resolved. A series in NEVER_PAGES may not be read by a
critical rule. This is here, over PyYAML, rather than in a PHP test over a
hand-written YAML reader: a second model of a file can be wrong in ways the
file never is, and Alertmanager's config is parsed by a real YAML parser.

It refuses a Loki ruler wired to an Alertmanager with no rule files mounted at
its rules directory. A ruler pointed at an empty directory looks configured and
evaluates nothing, which is worse than no ruler.

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

# Alerts whose destination is itself a remediation. Resolving to *some*
# receiver is not enough for these: relabelling the drift page to
# `component: backups` still reaches a receiver that exists -- the storage
# team's -- and every generic check here would pass while the on-call never
# hears about a customer paying for a machine the hypervisor does not have.
PINNED_ROUTES: dict[str, dict[str, str]] = {
    # F-22. Critical drift pages the on-call. It reads the series that counts
    # `open` AND `acknowledged` rows, so acknowledging a drift in the operator
    # screen does not silence the page; only resolving it does.
    "ResourceDriftOpen": {
        "receiver": "pagerduty-critical",
        "reads": "lynomia_resource_drift_open",
    },
    # F-22. Drift nobody has looked at, of any severity, reaches the platform
    # channel. This is the one acknowledging clears, by design: it asks for a
    # review, and a review is what acknowledging records.
    "DriftQueueUnworked": {
        "receiver": "platform-team",
        "reads": "lynomia_open_drift_total",
    },
}

# Series no critical rule may read, and why.
NEVER_PAGES: dict[str, str] = {
    "lynomia_open_drift_total": (
        "counts `open` drift only, so a page on it is silenced by clicking "
        "acknowledge; page on lynomia_resource_drift_open instead"
    ),
}

# Every key Alertmanager's `Route` accepts (v0.28). Alertmanager refuses an
# unknown key at load, and so does this: a key the walk does not understand is
# a key that might change where an alert goes.
ROUTE_KEYS = frozenset({
    "receiver", "group_by", "continue", "matchers", "match", "match_re",
    "group_wait", "group_interval", "repeat_interval",
    "mute_time_intervals", "active_time_intervals", "routes",
})

MATCHER = re.compile(
    r"""^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(=~|!~|!=|=)\s*"""
    r"""(?:"((?:[^"\\]|\\.)*)"|'([^']*)'|([^,"'{}]*?))\s*$"""
)


class RouteError(ValueError):
    """A route tree this walk cannot read, and so cannot vouch for."""


def split_matchers(text: str) -> list[str]:
    """One `matchers:` entry into its matchers: braces off, commas outside quotes."""
    text = text.strip()
    if text.startswith("{") and text.endswith("}"):
        text = text[1:-1]
    parts: list[str] = []
    current: list[str] = []
    quote: str | None = None
    escaped = False
    for char in text:
        if escaped:
            escaped = False
        elif char == "\\" and quote == '"':
            escaped = True
        elif quote:
            if char == quote:
                quote = None
        elif char in "\"'":
            quote = char
        elif char == ",":
            parts.append("".join(current))
            current = []
            continue
        current.append(char)
    parts.append("".join(current))
    return [part for part in parts if part.strip()]


def parse_matchers(route: dict) -> list[tuple[str, str, str]]:
    """Every matcher on a route, legacy forms included, as (label, op, value)."""
    parsed: list[tuple[str, str, str]] = []
    for entry in route.get("matchers") or []:
        if not isinstance(entry, str):
            raise RouteError(f"a matcher that is not a string: {entry!r}")
        for text in split_matchers(entry):
            match = MATCHER.match(text)
            if not match:
                raise RouteError(f"a matcher this walk cannot read: {text!r}")
            name, op, double, single, bare = match.groups()
            if double is not None:
                value = re.sub(r"\\(.)", r"\1", double)
            else:
                value = single if single is not None else (bare or "")
            parsed.append((name, op, value))
    for key, op in (("match", "="), ("match_re", "=~")):
        legacy = route.get(key) or {}
        if not isinstance(legacy, dict):
            raise RouteError(f"`{key}` is not a mapping: {legacy!r}")
        parsed.extend((str(name), op, str(value)) for name, value in legacy.items())
    return parsed


def matches(matchers: list[tuple[str, str, str]], labels: dict[str, str]) -> bool:
    for name, op, value in matchers:
        actual = labels.get(name, "")
        if op == "=":
            ok = actual == value
        elif op == "!=":
            ok = actual != value
        else:
            ok = re.fullmatch(value, actual) is not None
            if op == "!~":
                ok = not ok
        if not ok:
            return False
    return True


def check_route(route: object, where: str, is_root: bool = False) -> None:
    if not isinstance(route, dict):
        raise RouteError(f"{where} is not a mapping")
    unknown = sorted(set(route) - ROUTE_KEYS)
    if unknown:
        raise RouteError(f"{where} has keys Alertmanager does not accept: {', '.join(unknown)}")
    if is_root and not route.get("receiver"):
        raise RouteError("the root route has no receiver, so an unmatched alert goes nowhere")
    parse_matchers(route)
    children = route.get("routes") or []
    if not isinstance(children, list):
        raise RouteError(f"{where}.routes is not a list")
    for index, child in enumerate(children):
        check_route(child, f"{where}.routes[{index}]")


def receivers_for(route: dict, labels: dict[str, str], inherited: str | None = None) -> list[str]:
    """Where Alertmanager delivers an alert with these labels.

    Depth first, children in order; a matching child without `continue: true`
    stops the search among its siblings; a route no child matched delivers to
    its own receiver, inherited from its parent when it names none. The root
    matches everything.
    """
    receiver = route.get("receiver") or inherited
    found: list[str] = []
    for child in route.get("routes") or []:
        if not matches(parse_matchers(child), labels):
            continue
        found.extend(receivers_for(child, labels, receiver))
        if not child.get("continue", False):
            break
    return found or [receiver]


def loki_ruler_problems(monitoring: Path) -> list[str]:
    """A Loki ruler wired to Alertmanager must have rule files to evaluate.

    Rule files reach Loki one way in this tree: a bind mount from the
    repository at the ruler's local storage directory. `enable_api` would let
    rules be pushed at runtime, but nothing here pushes any, and a ruler that
    depends on an undocumented manual push is the configured-looking empty
    ruler this refuses.
    """
    config_path = monitoring / "loki" / "loki-config.yml"
    if not config_path.exists():
        return []
    config = yaml.safe_load(config_path.read_text()) or {}
    ruler = config.get("ruler")
    if not isinstance(ruler, dict) or not ruler.get("alertmanager_url"):
        return []

    directory = ((ruler.get("storage") or {}).get("local") or {}).get("directory")
    wired = f"loki/loki-config.yml wires the ruler to {ruler['alertmanager_url']}"
    if not directory:
        return [f"{wired} with no local rules directory; it has nothing to evaluate"]

    compose_path = monitoring / "docker-compose.monitoring.yml"
    compose = yaml.safe_load(compose_path.read_text()) if compose_path.exists() else {}
    loki = ((compose or {}).get("services") or {}).get("loki") or {}
    for volume in loki.get("volumes") or []:
        if not isinstance(volume, str):
            continue
        parts = volume.split(":")
        if len(parts) < 2 or not parts[0].startswith((".", "/")):
            continue  # a named volume starts empty; it is not a source of rules
        target = parts[1].rstrip("/")
        if target != directory.rstrip("/") and not target.startswith(directory.rstrip("/") + "/"):
            continue
        source = (compose_path.parent / parts[0]).resolve()
        rules = 0
        for path in sorted(source.rglob("*.y*ml")) if source.is_dir() else []:
            document = yaml.safe_load(path.read_text()) or {}
            for group in document.get("groups", []) if isinstance(document, dict) else []:
                rules += len(group.get("rules") or [])
        if rules:
            return []
    return [
        f"{wired} and mounts no rule files at {directory}. A ruler pointed at an "
        f"empty directory looks configured and evaluates nothing: ship rule "
        f"files and mount them there, or remove the ruler's wiring."
    ]


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


def main(
    argv: list[str],
    pinned: dict[str, dict[str, str]] | None = None,
    never_pages: dict[str, str] | None = None,
) -> int:
    # The pins describe this repository's alerts. They are parameters only so
    # the self-test can run the validator over synthetic trees that do not
    # contain those alerts; from the command line the real tables apply.
    pinned = PINNED_ROUTES if pinned is None else pinned
    never_pages = NEVER_PAGES if never_pages is None else never_pages
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
    alerts: dict[str, list[tuple[str, dict, str]]] = {}
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

                alerts.setdefault(name, []).append(
                    (path.name, dict(rule.get("labels") or {}), expr)
                )

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

    # A critical rule on a series that must never page.
    for name, definitions in alerts.items():
        for file_name, labels, expr in definitions:
            if labels.get("severity") != "critical":
                continue
            for metric in sorted(set(METRIC_IN_EXPR.findall(expr)) & set(never_pages)):
                problems.append(
                    f"{file_name}: {name} pages on {metric}, which {never_pages[metric]}"
                )

    # Where each alert goes, walked the way Alertmanager walks it.
    alertmanager_path = infra_root / "monitoring" / "alertmanager" / "alertmanager.yml"
    route: dict | None = None
    if alertmanager_path.exists():
        try:
            config = yaml.safe_load(alertmanager_path.read_text()) or {}
            check_route(config.get("route"), "route", is_root=True)
            route = config["route"]
        except yaml.YAMLError:
            pass  # reported below, with every other file that does not parse
        except RouteError as error:
            problems.append(f"alertmanager.yml: {error}; the routing of every alert is unverified")
        if route is not None:
            defined = {
                receiver.get("name")
                for receiver in config.get("receivers") or []
                if isinstance(receiver, dict)
            }
            for name, definitions in sorted(alerts.items()):
                for file_name, labels, _ in definitions:
                    walk_labels = {str(k): str(v) for k, v in labels.items()}
                    walk_labels["alertname"] = name
                    for receiver in receivers_for(route, walk_labels):
                        if receiver not in defined:
                            problems.append(
                                f"{file_name}: {name} routes to receiver {receiver!r}, "
                                f"which alertmanager.yml does not define"
                            )

    # The pinned alerts: present, reading the series they must, and delivered
    # where the pin says.
    for name, pin in sorted(pinned.items()):
        definitions = alerts.get(name)
        if not definitions:
            problems.append(
                f"{name} is pinned in PINNED_ROUTES and no rule file defines it"
            )
            continue
        for file_name, labels, expr in definitions:
            if pin["reads"] not in METRIC_IN_EXPR.findall(expr):
                problems.append(
                    f"{file_name}: {name} must read {pin['reads']}, and its "
                    f"expression does not"
                )
            if route is None:
                problems.append(
                    f"{file_name}: {name} is pinned to {pin['receiver']!r}, and "
                    f"alertmanager.yml is missing or unreadable, so where it goes "
                    f"cannot be checked"
                )
                continue
            walk_labels = {str(k): str(v) for k, v in labels.items()}
            walk_labels["alertname"] = name
            delivered = receivers_for(route, walk_labels)
            if pin["receiver"] not in delivered:
                problems.append(
                    f"{file_name}: {name} is delivered to {', '.join(delivered)}, "
                    f"and is pinned to {pin['receiver']!r}"
                )

    problems.extend(loki_ruler_problems(infra_root / "monitoring"))

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

    # The absent rules directory is already refused above. This is the case in
    # between: files are there, they parse, and not one rule came out of them.
    # A `groups:` key that has been renamed, or a rules file that is now a
    # Prometheus `rule_files:` include rather than the rules themselves, gets
    # exactly this far -- every assertion below iterates an empty list and the
    # job prints a green line. An alerting configuration with no alerts in it
    # is not a configuration this check has approved.
    if total_rules == 0:
        print(
            f"{len(rule_files)} rule file(s) declare no rules at all. Every "
            f"check below this point iterates an empty list, so a green result "
            f"here would mean nothing was examined rather than nothing was "
            f"wrong.",
            file=sys.stderr,
        )
        return 1

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
