#!/usr/bin/env python3
"""Proof that the monitoring gate refuses each thing its docstring claims.

Why this exists
---------------
Third of the three CI validators that ran as gates with no self-test. This one
is the widest of them: it makes seven distinct assertions -- a metric nothing
exports, a collector contract, a missing runbook, a dangling runbook path, a
missing severity, an inline credential, a directory the README promises -- and
"the job is green" is evidence for none of them individually. A single one of
the seven silently ceasing to fire would look exactly like today.

Every case below builds a synthetic infrastructure tree that differs from a
known-good one in one respect, and requires the validator to name that respect.

F-22 added three more: where each alert goes (a real walk of Alertmanager's
route tree over PyYAML, with the drift page pinned to the on-call), a series
that must never page, and a Loki ruler wired with nothing to evaluate. The
routing cases include the shapes that fooled a hand-written YAML reader in PHP
five times over — flow style, an inline comment on a quoted matcher, a legacy
`match:` block, `continue: true` — because those are what a real parser makes
disappear, and a regression to a hand-written reader would bring them back.

Run: python3 infrastructure/scripts/test_validate_monitoring.py
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
    "validate_monitoring", HERE / "validate-monitoring.py"
)
validator = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validator)

SOURCE_PHP = """<?php

final class ProvisioningMetrics
{
    public function render(): string
    {
        return 'lynomia_provisioning_jobs_pending lynomia_provisioning_jobs_failed';
    }
}
"""

RULES = """
groups:
  - name: provisioning
    rules:
      - alert: ProvisioningBacklog
        expr: lynomia_provisioning_jobs_pending > 50
        labels:
          severity: warning
        annotations:
          runbook: docs/runbooks/provisioning-backlog.md
"""

INFRA_README = """
infrastructure/
├── monitoring/
└── scripts/
"""

MONITORING_README = "No textfile collectors are declared here.\n"


def build(
    root: Path,
    *,
    php: str = SOURCE_PHP,
    rules: dict[str, str] | None = None,
    infra_readme: str = INFRA_README,
    monitoring_readme: str | None = MONITORING_README,
    extra_yml: dict[str, str] | None = None,
    runbook_files: tuple[str, ...] = ("docs/runbooks/provisioning-backlog.md",),
) -> Path:
    source = root / "apps" / "control-plane" / "src"
    source.mkdir(parents=True)
    (source / "ProvisioningMetrics.php").write_text(php)

    for relative in runbook_files:
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("# a runbook\n")

    infra = root / "infrastructure"
    (infra / "scripts").mkdir(parents=True)
    (infra / "README.md").write_text(infra_readme)

    monitoring = infra / "monitoring"
    rules_dir = monitoring / "prometheus" / "rules"
    rules_dir.mkdir(parents=True)
    for name, body in (rules if rules is not None else {"provisioning.yml": RULES}).items():
        (rules_dir / name).write_text(body)
    if monitoring_readme is not None:
        (monitoring / "README.md").write_text(monitoring_readme)
    for name, body in (extra_yml or {}).items():
        (monitoring / name).parent.mkdir(parents=True, exist_ok=True)
        (monitoring / name).write_text(body)

    return infra


def run(*, pinned: dict | None = None, never_pages: dict | None = None, **kwargs) -> tuple[int, str, str]:
    # The repository's own pins name alerts a synthetic tree does not have, so
    # each case states the pins it is about and every other case has none.
    with tempfile.TemporaryDirectory() as tmp:
        infra = build(Path(tmp), **kwargs)
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = validator.main(
                ["validate-monitoring.py", str(infra)],
                pinned=pinned or {},
                never_pages=never_pages or {},
            )
        return code, out.getvalue(), err.getvalue()


DRIFT_PHP = SOURCE_PHP.replace(
    "'lynomia_provisioning_jobs_pending lynomia_provisioning_jobs_failed'",
    "'lynomia_provisioning_jobs_pending lynomia_resource_drift_open lynomia_open_drift_total'",
)

DRIFT_RULES = """
groups:
  - name: platform.drift
    rules:
      - alert: ResourceDriftOpen
        expr: lynomia_resource_drift_open{severity="critical"} > 0
        for: 15m
        labels:
          severity: critical
          component: provisioning
        annotations:
          runbook: docs/runbooks/drift.md
      - alert: DriftQueueUnworked
        expr: sum(lynomia_open_drift_total) > 0
        for: 24h
        labels:
          severity: warning
          component: provisioning
        annotations:
          runbook: docs/runbooks/drift.md
"""

ALERTMANAGER = """
route:
  receiver: platform-team
  routes:
    - matchers:
        - component = monitoring
      receiver: monitoring-team
    - matchers:
        - severity = critical
      receiver: pagerduty-critical
      routes:
        - matchers:
            - component = backups
          receiver: pagerduty-critical-backups
          continue: false
    - matchers:
        - severity = warning
      receiver: platform-team
receivers:
  - name: platform-team
  - name: monitoring-team
  - name: pagerduty-critical
  - name: pagerduty-critical-backups
"""

DRIFT_PINS = {
    "ResourceDriftOpen": {"receiver": "pagerduty-critical", "reads": "lynomia_resource_drift_open"},
    "DriftQueueUnworked": {"receiver": "platform-team", "reads": "lynomia_open_drift_total"},
}

NEVER_PAGES = {"lynomia_open_drift_total": "is cleared by acknowledging"}

LOKI_WITH_RULER = """
ruler:
  storage:
    type: local
    local:
      directory: /loki/rules
  alertmanager_url: http://alertmanager:9093
"""

COMPOSE_LOKI = """
services:
  loki:
    volumes:
      - ./loki/loki-config.yml:/etc/loki/loki-config.yml:ro
      - loki-data:/loki
"""


def drift(
    *,
    rules: str = DRIFT_RULES,
    alertmanager: str | None = ALERTMANAGER,
    pinned: dict | None = None,
    extra_yml: dict[str, str] | None = None,
) -> dict:
    files = {"alertmanager/alertmanager.yml": alertmanager} if alertmanager is not None else {}
    files.update(extra_yml or {})
    return {
        "php": DRIFT_PHP,
        "rules": {"provisioning.yml": RULES, "platform.yml": rules},
        "runbook_files": ("docs/runbooks/provisioning-backlog.md", "docs/runbooks/drift.md"),
        "extra_yml": files,
        "pinned": DRIFT_PINS if pinned is None else pinned,
        "never_pages": NEVER_PAGES,
    }


def rules_with(**replacements: str) -> dict[str, str]:
    body = RULES
    for old, new in replacements.items():
        body = body.replace(old.replace("__", " "), new)
    return {"provisioning.yml": body}


CASES: list[tuple[str, dict, str | None]] = [
    ("a consistent configuration passes", {}, None),
    (
        "an alert on a metric nothing exports is refused",
        {"rules": {"provisioning.yml": RULES.replace(
            "lynomia_provisioning_jobs_pending", "lynomia_jobs_that_nothing_emits")}},
        "which nothing exports and no collector contract declares",
    ),
    (
        "a metric a collector contract declares is accepted",
        {
            "rules": {"provisioning.yml": RULES.replace(
                "lynomia_provisioning_jobs_pending", "lynomia_pbs_last_backup_seconds")},
            "monitoring_readme": "The PBS collector writes lynomia_pbs_last_backup_seconds.\n",
        },
        None,
    ),
    (
        "an alert with neither runbook nor runbook_url is refused",
        {"rules": {"provisioning.yml": RULES.replace(
            "        annotations:\n          runbook: docs/runbooks/provisioning-backlog.md\n",
            "")}},
        "has neither a runbook nor a runbook_url",
    ),
    (
        "a runbook path that does not exist is refused",
        {"rules": {"provisioning.yml": RULES.replace(
            "docs/runbooks/provisioning-backlog.md", "docs/runbooks/never-written.md")}},
        "which does not exist",
    ),
    (
        "a runbook_url outside the repository is taken on trust",
        {"rules": {"provisioning.yml": RULES.replace(
            "runbook: docs/runbooks/provisioning-backlog.md",
            "runbook_url: https://example.invalid/runbooks/backlog")}},
        None,
    ),
    (
        "an alert with no severity label is refused",
        {"rules": {"provisioning.yml": RULES.replace(
            "        labels:\n          severity: warning\n", "")}},
        "has no severity label",
    ),
    (
        "a recording rule needs neither a runbook nor a severity",
        {"rules": {"provisioning.yml": """
groups:
  - name: provisioning
    rules:
      - record: job:lynomia_provisioning_jobs_pending:sum
        expr: sum(lynomia_provisioning_jobs_pending)
"""}},
        None,
    ),
    (
        "a recording rule on a metric nothing exports is still refused",
        {"rules": {"provisioning.yml": """
groups:
  - name: provisioning
    rules:
      - record: job:invented:sum
        expr: sum(lynomia_never_emitted_at_all)
"""}},
        "which nothing exports and no collector contract declares",
    ),
    (
        "an inline credential in a monitoring file is refused",
        {"extra_yml": {"alertmanager.yml": "receivers:\n  - name: pager\n    api_key: sk-live-0123456789\n"}},
        "has an inline credential",
    ),
    (
        "a *_file indirection is not an inline credential",
        {"extra_yml": {"alertmanager.yml": "receivers:\n  - name: pager\n    api_key_file: /run/secrets/pager\n"}},
        None,
    ),
    (
        "an environment placeholder is not an inline credential",
        {"extra_yml": {"alertmanager.yml": "receivers:\n  - name: pager\n    api_key: ${PAGER_KEY}\n"}},
        None,
    ),
    (
        "a monitoring file that does not parse is refused",
        {"extra_yml": {"broken.yml": "receivers:\n  - name: pager\n   bad: indentation\n"}},
        "does not parse",
    ),
    (
        "a directory the infrastructure README promises and does not have is refused",
        {"infra_readme": "infrastructure/\n├── monitoring/\n├── scripts/\n└── pxe/\n"},
        "which does not exist",
    ),
    (
        "a collector whose home does not exist and is not acknowledged is refused",
        {"monitoring_readme": "The PBS series lynomia_pbs_last_backup_seconds belongs to `infrastructure/pbs`.\n"},
        "and does not say so",
    ),
    (
        "the same collector, marked NOT IMPLEMENTED, passes with a note",
        {"monitoring_readme": (
            "The PBS series lynomia_pbs_last_backup_seconds belongs to `infrastructure/pbs`.\n"
            "NOT IMPLEMENTED: `infrastructure/pbs` needs hardware nobody has yet.\n"
        )},
        None,
    ),
    (
        "a source tree exporting no lynomia_* metric is refused",
        {"php": "<?php\n\nfinal class Nothing {}\n"},
        "found no lynomia_* metrics",
    ),
    (
        "a rules directory with no rule files is refused",
        {"rules": {}},
        "no rule files under",
    ),
    (
        "rule files that between them declare no rule at all are refused",
        {"rules": {"provisioning.yml": "groups: []\n"}},
        "declare no rules at all",
    ),
    # -- Where alerts go (F-22) ------------------------------------------------
    ("the drift alerts, routed as pinned, pass", drift(), None),
    (
        "a pinned alert that no rule defines is refused",
        drift(rules=DRIFT_RULES.replace("alert: ResourceDriftOpen", "alert: SomethingElse")),
        "ResourceDriftOpen is pinned in PINNED_ROUTES and no rule file defines it",
    ),
    (
        "a pinned alert reading the wrong series is refused",
        drift(rules=DRIFT_RULES.replace(
            'lynomia_resource_drift_open{severity="critical"}', "lynomia_provisioning_jobs_pending")),
        "ResourceDriftOpen must read lynomia_resource_drift_open",
    ),
    (
        "a pinned series name inside a longer identifier is not a read of it",
        drift(rules=DRIFT_RULES.replace(
            "sum(lynomia_open_drift_total)", "sum(lynomia_open_drift_total_by_tenant)"
        ), extra_yml=None),
        "DriftQueueUnworked must read lynomia_open_drift_total",
    ),
    (
        "relabelling the page onto another team's sub-route is refused",
        drift(rules=DRIFT_RULES.replace("severity: critical\n          component: provisioning",
                                        "severity: critical\n          component: backups")),
        "is delivered to pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "the same diversion written in flow style is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "      receiver: pagerduty-critical\n      routes:\n        - matchers:\n            - component = backups\n          receiver: pagerduty-critical-backups\n          continue: false\n",
            "      receiver: pagerduty-critical\n      routes: [{matchers: ['component =~ \"backups|provisioning\"'], receiver: pagerduty-critical-backups}]\n",
        )),
        "is delivered to pagerduty-critical-backups",
    ),
    (
        "a diversion behind an inline comment on a quoted matcher is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n",
            "            - 'component =~ \"backups|provisioning\"'  # see runbook 'backup-verify'\n",
        )),
        "is delivered to pagerduty-critical-backups",
    ),
    (
        "a diversion by a legacy `match:` block is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n",
            "        - match:\n            component: provisioning\n",
        )),
        "is delivered to pagerduty-critical-backups",
    ),
    (
        "a copy that takes the page away by `continue: true` is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n          receiver: pagerduty-critical-backups\n          continue: false\n",
            "            - component = provisioning\n          receiver: pagerduty-critical-backups\n          continue: true\n",
        )),
        "is delivered to pagerduty-critical-backups",
    ),
    (
        "an alert routed to a receiver nobody defined is refused",
        drift(alertmanager=ALERTMANAGER.replace("  - name: pagerduty-critical\n", ""), pinned={}),
        "which alertmanager.yml does not define",
    ),
    (
        "a route key Alertmanager does not accept is refused",
        drift(alertmanager=ALERTMANAGER.replace("          continue: false\n",
                                                "          continue: false\n          recevier: typo\n")),
        "has keys Alertmanager does not accept: recevier",
    ),
    (
        "a matcher the walk cannot read is refused rather than skipped",
        drift(alertmanager=ALERTMANAGER.replace("            - component = backups\n",
                                                "            - component backups\n")),
        "a matcher this walk cannot read",
    ),
    (
        "a pinned alert with no alertmanager.yml to walk is refused",
        drift(alertmanager=None),
        "cannot be checked",
    ),
    (
        "a critical rule on a series that must never page is refused",
        drift(rules=DRIFT_RULES + """
      - alert: DriftPagedOnTheWrongSeries
        expr: sum(lynomia_open_drift_total) > 0
        labels:
          severity: critical
          component: provisioning
        annotations:
          runbook: docs/runbooks/drift.md
"""),
        "DriftPagedOnTheWrongSeries pages on lynomia_open_drift_total",
    ),
    # -- The Loki ruler (F-22) -------------------------------------------------
    (
        "a Loki ruler wired to Alertmanager with no rules mounted is refused",
        {"extra_yml": {"loki/loki-config.yml": LOKI_WITH_RULER,
                       "docker-compose.monitoring.yml": COMPOSE_LOKI}},
        "mounts no rule files at /loki/rules",
    ),
    (
        "a mounted rules directory that holds no rule is still refused",
        {"extra_yml": {
            "loki/loki-config.yml": LOKI_WITH_RULER,
            "loki/rules/fake/empty.yml": "groups: []\n",
            "docker-compose.monitoring.yml": COMPOSE_LOKI.replace(
                "      - loki-data:/loki\n",
                "      - loki-data:/loki\n      - ./loki/rules:/loki/rules:ro\n"),
        }},
        "mounts no rule files at /loki/rules",
    ),
    (
        "a Loki ruler with rule files mounted at its directory passes",
        {"extra_yml": {
            "loki/loki-config.yml": LOKI_WITH_RULER,
            "loki/rules/fake/drift.yml": "groups:\n  - name: logs\n    rules:\n      - alert: X\n        expr: 'sum(count_over_time({job=\"a\"}[5m])) > 0'\n",
            "docker-compose.monitoring.yml": COMPOSE_LOKI.replace(
                "      - loki-data:/loki\n",
                "      - loki-data:/loki\n      - ./loki/rules:/loki/rules:ro\n"),
        }},
        None,
    ),
    (
        "a Loki config with no ruler passes",
        {"extra_yml": {"loki/loki-config.yml": "auth_enabled: false\n",
                       "docker-compose.monitoring.yml": COMPOSE_LOKI}},
        None,
    ),
]


def main() -> int:
    failures = 0
    for name, kwargs, expected in CASES:
        code, out, err = run(**kwargs)
        if expected is None:
            ok = code == 0 and not err.strip()
        else:
            ok = code == 1 and expected in err
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got exit {code}\n      stdout: {out.strip()}\n      stderr: {err.strip()}")

    # One positive that is not a refusal: the NOT IMPLEMENTED case has to
    # announce itself on stdout, because a gap that stands silently is the
    # thing this validator's own docstring says it exists to prevent.
    code, out, err = run(monitoring_readme=(
        "The PBS series lynomia_pbs_last_backup_seconds belongs to `infrastructure/pbs`.\n"
        "NOT IMPLEMENTED: `infrastructure/pbs` needs hardware nobody has yet.\n"
    ))
    ok = code == 0 and "declared and not implemented" in out
    print(f"{'PASS' if ok else 'FAIL'}  an acknowledged gap is announced rather than passed over")
    if not ok:
        failures += 1
        print(f"      got exit {code}, stdout: {out.strip()}")

    # The cases above inject their own pins, so none of them notices the
    # shipped tables being emptied. This does: the drift page's destination is
    # the F-22 fix, and deleting its pin would leave the gate green over
    # nothing.
    shipped = validator.PINNED_ROUTES
    ok = (
        shipped.get("ResourceDriftOpen") == DRIFT_PINS["ResourceDriftOpen"]
        and shipped.get("DriftQueueUnworked") == DRIFT_PINS["DriftQueueUnworked"]
        and "lynomia_open_drift_total" in validator.NEVER_PAGES
    )
    print(f"{'PASS' if ok else 'FAIL'}  the shipped pin tables still hold the drift page to the on-call")
    if not ok:
        failures += 1
        print(f"      PINNED_ROUTES: {shipped!r}\n      NEVER_PAGES: {validator.NEVER_PAGES!r}")

    total = len(CASES) + 2
    print(f"\n{total - failures}/{total} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
