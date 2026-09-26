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

F-22 added three more: where each alert goes (a walk of Alertmanager's route
tree over PyYAML, with the drift page pinned to the on-call), a series that
must never page, and a Loki ruler wired with nothing to evaluate. The routing
cases include the shapes that fooled a hand-written YAML reader in PHP five
times over — flow style, and an inline comment on a quoted matcher — which a
real YAML parser makes disappear.

A real YAML parser does not make the walk right, though: the walk is a model of
Alertmanager, and a model can be wrong in ways the file never is. Its first
version read `prov\\w+` as `provw+` and `severity='critical'` as `critical`, and
in both the drift page went somewhere other than where it said. So the walk's
semantics are held two ways here. The tree cases each tell the right code from
a plausible wrong one — `continue: true` with a later sibling that also
receives, a legacy `match:` for another component that must leave the page
alone — and the golden tables at the bottom are Alertmanager v0.28.1's own
answers, recorded from its matcher parser (compat.Matchers, fallback mode) and
from Go's regexp, for inputs chosen because a reader could get them wrong.
They were recorded, not derived: rerun the recording if MODELLED_ALERTMANAGER
moves.

Nor is agreeing on the parse the same as agreeing on the route. A YAML merge
key parses identically in go-yaml and PyYAML, and Alertmanager then decodes
the route field by field and builds a different one from it; the merge-key
cases record two such routes, measured against Alertmanager v0.28.1, that sent
the page somewhere the walk did not say.

The walk also stands in for Prometheus, which decides the labels Alertmanager is
given, and the Loki check for Loki's reading of its own command line. A rule
label set to '' is deleted by Prometheus v3.6.0, which then lets an external
label of that name through. A rule file with a key written twice is one
Prometheus refuses to load. A ruler wired by -ruler.alertmanager-url is wired as
surely as one wired in loki-config.yml. Each of those passed the gate once, and
each has a case here. None of these tables is complete: they record what attack
has found so far.

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
    "ResourceDriftOpen": {
        "receiver": "pagerduty-critical",
        "reads": "lynomia_resource_drift_open",
        "expr": 'lynomia_resource_drift_open{severity="critical"} > 0',
        "for": "15m",
    },
    "DriftQueueUnworked": {
        "receiver": "platform-team",
        "reads": "lynomia_open_drift_total",
        "expr": "sum(lynomia_open_drift_total) > 0",
        "for": "24h",
    },
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

LOKI_RULE_FILE = (
    "groups:\n  - name: logs\n    rules:\n      - alert: X\n"
    "        expr: 'sum(count_over_time({job=\"a\"}[5m])) > 0'\n"
)

COMPOSE_LOKI = """
services:
  loki:
    volumes:
      - ./loki/loki-config.yml:/etc/loki/loki-config.yml:ro
      - loki-data:/loki
"""

COMPOSE_ALERTMANAGER = """
services:
  alertmanager:
    image: prom/alertmanager:v0.28.1
    command:
      - --config.file=/etc/alertmanager/alertmanager.yml
      - --enable-feature=classic-mode
"""

PROMETHEUS_RELABELLING = """
alerting:
  alertmanagers:
    - static_configs:
        - targets: [alertmanager:9093]
  alert_relabel_configs:
    - source_labels: [component]
      regex: provisioning
      target_label: component
      replacement: backups
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
        # "Critical only": without the selector, every info and warning drift
        # pages, and the page still reads the right series and reaches the
        # right receiver.
        "the page widened past critical drift is refused",
        drift(rules=DRIFT_RULES.replace(
            'lynomia_resource_drift_open{severity="critical"} > 0', "lynomia_resource_drift_open > 0")),
        "ResourceDriftOpen's expression is 'lynomia_resource_drift_open > 0'",
    ),
    (
        "a pinned expression reformatted only in its whitespace still passes",
        drift(rules=DRIFT_RULES.replace(
            'lynomia_resource_drift_open{severity="critical"} > 0',
            'lynomia_resource_drift_open{severity="critical"}   >   0')),
        None,
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
        "pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "the same diversion written in flow style is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "      receiver: pagerduty-critical\n      routes:\n        - matchers:\n            - component = backups\n          receiver: pagerduty-critical-backups\n          continue: false\n",
            "      receiver: pagerduty-critical\n      routes: [{matchers: ['component =~ \"backups|provisioning\"'], receiver: pagerduty-critical-backups}]\n",
        )),
        "pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "a diversion behind an inline comment on a quoted matcher is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n",
            "            - 'component =~ \"backups|provisioning\"'  # see runbook 'backup-verify'\n",
        )),
        "pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "a diversion by a legacy `match:` block is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n",
            "        - match:\n            component: provisioning\n",
        )),
        "pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "a `continue: true` sub-route with no later match still takes the page away",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n          receiver: pagerduty-critical-backups\n          continue: false\n",
            "            - component = provisioning\n          receiver: pagerduty-critical-backups\n          continue: true\n",
        )),
        "pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        # Tells a walk that honours `continue: true` from one that stops at the
        # first match: only the first walk reaches the second sibling.
        "a `continue: true` copy followed by a sibling that also receives leaves the page in place",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n          receiver: pagerduty-critical-backups\n          continue: false\n",
            "            - component = provisioning\n          receiver: pagerduty-critical-backups\n          continue: true\n"
            "        - matchers:\n            - component = provisioning\n          receiver: pagerduty-critical\n",
        )),
        None,
    ),
    (
        # Tells a walk that reads `match:` from one that ignores it: ignored,
        # this route has no matchers and catches the page.
        "a legacy `match:` route for another component leaves the page in place",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n",
            "        - match:\n            component: backups\n",
        )),
        None,
    ),
    (
        "a diversion by a legacy `match_re:` block is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n",
            "        - match_re:\n            component: 'backups|prov.*'\n",
        )),
        "pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    # Where the walk once read a matcher differently from Alertmanager, and the
    # page went somewhere the walk did not say.
    (
        "a backslash Alertmanager keeps in a regex is kept, and the diversion refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n",
            '            - component =~ "backups|prov\\w+"\n',
        )),
        "pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "a single-quoted value keeps its quotes, and the page that then reaches nobody is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - severity = critical\n", "        - severity='critical'\n", 1,
        )),
        "is routed to platform-team, and is pinned to 'pagerduty-critical'",
    ),
    (
        "a regex Go's RE2 reads differently from Python's re is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n",
            '            - component =~ "backups|prov[[:alpha:]]+"\n',
        )),
        "Go's RE2 and Python's re might not read alike",
    ),
    (
        "a route key written twice is refused, as Alertmanager refuses it",
        drift(alertmanager=ALERTMANAGER.replace(
            "          receiver: pagerduty-critical-backups\n",
            "          receiver: pagerduty-critical-backups\n          receiver: pagerduty-critical\n",
        )),
        "is written twice",
    ),
    (
        # go-yaml parses this exactly as PyYAML does: the second route's
        # matchers are `component = provisioning`. Alertmanager then decodes
        # the route field by field and appends the explicit matchers to the
        # merged ones, so the route needs backups AND provisioning, matches
        # nothing, and the page falls to the next sibling -- here, the
        # storage team's. Measured against Alertmanager v0.28.1.
        "a route that merges another route's settings is refused, as Alertmanager adds the matchers",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n"
            "          receiver: pagerduty-critical-backups\n          continue: false\n",
            "        - &backups\n          matchers:\n            - component = backups\n"
            "          receiver: pagerduty-critical-backups\n          continue: false\n"
            "        - <<: *backups\n          matchers:\n            - component = provisioning\n"
            "          receiver: pagerduty-critical\n"
            "        - matchers:\n            - component =~ \"backups|provisioning\"\n"
            "          receiver: pagerduty-critical-backups\n",
        )),
        "a merge key `<<`",
    ),
    (
        # A key before the merge: Alertmanager applies the merge where it
        # stands, so the merged receiver overwrites the one written above it,
        # and the merged `severity = critical` joins `component = provisioning`.
        # PyYAML keeps the written receiver. Alertmanager v0.28.1 sends the
        # page to the storage team; the walk, reading PyYAML, to the on-call.
        "a key written before a merge is refused, as Alertmanager overwrites it",
        drift(alertmanager=ALERTMANAGER.replace(
            "      receiver: monitoring-team\n",
            "      receiver: monitoring-team\n      routes:\n"
            "        - &storage\n          matchers:\n            - severity = critical\n"
            "          receiver: pagerduty-critical-backups\n",
        ).replace(
            "      receiver: pagerduty-critical\n      routes:\n",
            "      receiver: pagerduty-critical\n      routes:\n"
            "        - receiver: pagerduty-critical\n          <<: *storage\n"
            "          matchers:\n            - component = provisioning\n",
        )),
        "a merge key `<<`",
    ),
    (
        "a merge key spelled with an explicit !!merge tag is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n"
            "          receiver: pagerduty-critical-backups\n          continue: false\n",
            "        - &backups\n          matchers:\n            - component = backups\n"
            "          receiver: pagerduty-critical-backups\n          continue: false\n"
            "        - !!merge \"<<\": *backups\n          matchers:\n            - component = provisioning\n"
            "          receiver: pagerduty-critical\n",
        )),
        "a merge key `<<`",
    ),
    (
        # PyYAML merges on the tag whatever the key says; go-yaml merges only
        # a key reading `<<`, so Alertmanager sees an unknown field and
        # refuses the file the walk would otherwise have read.
        "a !!merge tag on a key that is not `<<` is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n"
            "          receiver: pagerduty-critical-backups\n          continue: false\n",
            "        - &backups\n          matchers:\n            - component = backups\n"
            "          receiver: pagerduty-critical-backups\n          continue: false\n"
            "        - !!merge settings: *backups\n          receiver: pagerduty-critical\n",
        )),
        "the key 'settings', tagged !!merge",
    ),
    (
        # An anchor and an alias with no merge key are a copy, in go-yaml and
        # in PyYAML alike; refusing merge keys must not refuse these.
        "a route repeated by an alias, with no merge key, is read as a copy",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n",
            "        - &backups\n          matchers:\n            - component = backups\n",
        ).replace(
            "          continue: false\n", "          continue: false\n        - *backups\n",
        )),
        None,
    ),
    (
        # Tells a walk that stops at the first matching sibling from one that
        # carries on: the second finds the on-call on the later sibling, and
        # the pin, which asks whether the on-call is among the receivers,
        # would pass.
        "a diversion by the first matching sibling is not undone by a later one",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n          receiver: pagerduty-critical-backups\n          continue: false\n",
            "            - component = provisioning\n          receiver: pagerduty-critical-backups\n"
            "        - matchers:\n            - component = provisioning\n          receiver: pagerduty-critical\n",
        )),
        "is routed to pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "a sub-route with no matchers catches everything under it, the page included",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n          receiver: pagerduty-critical-backups\n",
            "        - receiver: pagerduty-critical-backups\n",
        )),
        "is routed to pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "a sub-route that names no receiver delivers to its parent's",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n          receiver: pagerduty-critical-backups\n",
            "            - component = provisioning\n",
        )),
        None,
    ),
    (
        "a receiver inherited from the storage team's route takes the page away",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n          receiver: pagerduty-critical-backups\n          continue: false\n",
            "            - component =~ \"backups|provisioning\"\n          receiver: pagerduty-critical-backups\n"
            "          routes:\n            - matchers:\n                - component = provisioning\n",
        )),
        "is routed to pagerduty-critical-backups, and is pinned to 'pagerduty-critical'",
    ),
    (
        "a pinned alert that waits longer than its pin is refused",
        drift(rules=DRIFT_RULES.replace("        for: 15m\n", "        for: 1h\n")),
        "ResourceDriftOpen waits `for: 1h`, and PINNED_ROUTES pins it to `for: 15m`",
    ),
    (
        "a pinned alert that no longer waits at all is refused",
        drift(rules=DRIFT_RULES.replace("        for: 24h\n", "")),
        "DriftQueueUnworked has no `for:`, and PINNED_ROUTES pins it to `for: 24h`",
    ),
    (
        "a `continue` go-yaml and PyYAML type differently is refused",
        drift(alertmanager=ALERTMANAGER.replace("          continue: false\n", "          continue: n\n", 1)),
        "is not a boolean",
    ),
    (
        "a legacy `match:` value that is not a string is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n",
            "        - match:\n            component: yes\n",
        )),
        "non-string True",
    ),
    (
        "matchers on the root route are refused, as Alertmanager refuses them",
        drift(alertmanager=ALERTMANAGER.replace(
            "route:\n  receiver: platform-team\n",
            "route:\n  receiver: platform-team\n  matchers:\n    - severity = critical\n",
        )),
        "the root route has matchers",
    ),
    (
        "`continue: true` on the root route is refused, as Alertmanager refuses it",
        drift(alertmanager=ALERTMANAGER.replace(
            "route:\n  receiver: platform-team\n",
            "route:\n  receiver: platform-team\n  continue: true\n",
        )),
        "the root route has `continue: true`",
    ),
    (
        # model.LabelNameRE: Alertmanager refuses the file at load. Read as a
        # matcher instead, the walk would report a label the rule does not
        # set, which is a different and wrong reason.
        "a legacy `match:` naming a label Alertmanager refuses is refused",
        drift(alertmanager=ALERTMANAGER.replace(
            "        - matchers:\n            - component = backups\n",
            "        - match:\n            component-name: backups\n",
        )),
        "`match` names a label Alertmanager refuses: 'component-name'",
    ),
    (
        "a route on a label the pinned rule does not set cannot pin the page",
        drift(alertmanager=ALERTMANAGER.replace(
            "            - component = backups\n", '            - instance =~ "pve-.*"\n',
        )),
        "a route matching on instance decides where it goes",
    ),
    (
        "a templated label on a pinned rule is not a label the walk knows",
        drift(
            rules=DRIFT_RULES.replace(
                "severity: critical\n          component: provisioning",
                "severity: critical\n          component: '{{ $labels.component }}'",
            ),
        ),
        "a route matching on component decides where it goes",
    ),
    (
        # Prometheus deletes a label whose value is empty (labels.Builder.Set)
        # and then adds each external label the alert lacks, so `cluster: ''`
        # reaches Alertmanager as whatever prometheus.yml's external_labels
        # say. Read as a known empty value, the route below never matches and
        # the page looks pinned; Prometheus v3.6.0 sends it to the storage team.
        "an empty-valued label on a pinned rule is not a label the walk knows",
        drift(
            rules=DRIFT_RULES.replace(
                "severity: critical\n          component: provisioning",
                "severity: critical\n          component: provisioning\n          cluster: ''",
            ),
            alertmanager=ALERTMANAGER.replace(
                "      receiver: pagerduty-critical\n      routes:\n",
                "      receiver: pagerduty-critical\n      routes:\n"
                "        - matchers:\n            - cluster = lynomia\n"
                "          receiver: pagerduty-critical-backups\n",
            ),
        ),
        "a route matching on cluster decides where it goes",
    ),
    (
        # rulefmt decodes with yaml.v3, which refuses a key written twice;
        # PyYAML keeps the last one. A merge-conflict leftover like this one
        # reads, to PyYAML, as the page routed to the on-call, and Prometheus
        # loads none of the file's rules.
        "a rule label written twice is refused, as Prometheus refuses the file",
        drift(rules=DRIFT_RULES.replace(
            "severity: critical\n          component: provisioning",
            "severity: critical\n          component: backups\n          component: provisioning",
        )),
        "the key 'component' is written twice",
    ),
    (
        # yaml.v3 compares a key by its text, so quoting one spelling does not
        # make it a second key.
        "a rule label written twice, once quoted, is refused",
        drift(rules=DRIFT_RULES.replace(
            "severity: critical\n          component: provisioning",
            "severity: critical\n          component: backups\n          \"component\": provisioning",
        )),
        "the key 'component' is written twice",
    ),
    (
        "alert relabelling in prometheus.yml leaves no pin checkable",
        drift(extra_yml={"prometheus/prometheus.yml": PROMETHEUS_RELABELLING}),
        "prometheus.yml relabels alerts",
    ),
    (
        "the Alertmanager the walk models, run as modelled, passes",
        drift(extra_yml={"docker-compose.monitoring.yml": COMPOSE_ALERTMANAGER}),
        None,
    ),
    (
        "an Alertmanager version the walk does not model is refused",
        drift(extra_yml={"docker-compose.monitoring.yml": COMPOSE_ALERTMANAGER.replace(
            "v0.28.1", "v0.29.0")}),
        "the route walk models",
    ),
    (
        "Alertmanager run with only its UTF-8 matcher parser is refused",
        drift(extra_yml={"docker-compose.monitoring.yml": COMPOSE_ALERTMANAGER.replace(
            "      - --config.file=/etc/alertmanager/alertmanager.yml\n",
            "      - --config.file=/etc/alertmanager/alertmanager.yml\n"
            "      - --enable-feature=utf8-strict-mode\n")}),
        "utf8-strict-mode",
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
        # Loki's local store reads <directory>/<tenant>/<file>: a file
        # directly in the directory is not a tenant, and is never read.
        "rule files directly in the ruler's directory, where Loki never reads them, are refused",
        {"extra_yml": {
            "loki/loki-config.yml": LOKI_WITH_RULER,
            "loki/rules/drift.yml": LOKI_RULE_FILE,
            "docker-compose.monitoring.yml": COMPOSE_LOKI.replace(
                "      - loki-data:/loki\n",
                "      - loki-data:/loki\n      - ./loki/rules:/loki/rules:ro\n"),
        }},
        "mounts no rule files at /loki/rules/<tenant>/",
    ),
    (
        # ... and a directory inside a tenant's is skipped.
        "rule files a directory below the tenant's are refused",
        {"extra_yml": {
            "loki/loki-config.yml": LOKI_WITH_RULER,
            "loki/rules/fake/logs/drift.yml": LOKI_RULE_FILE,
            "docker-compose.monitoring.yml": COMPOSE_LOKI.replace(
                "      - loki-data:/loki\n",
                "      - loki-data:/loki\n      - ./loki/rules:/loki/rules:ro\n"),
        }},
        "mounts no rule files at /loki/rules/<tenant>/",
    ),
    (
        "one tenant's directory mounted at its place under the ruler's directory passes",
        {"extra_yml": {
            "loki/loki-config.yml": LOKI_WITH_RULER,
            "loki/tenant-rules/drift.yml": LOKI_RULE_FILE,
            "docker-compose.monitoring.yml": COMPOSE_LOKI.replace(
                "      - loki-data:/loki\n",
                "      - loki-data:/loki\n      - ./loki/tenant-rules:/loki/rules/fake:ro\n"),
        }},
        None,
    ),
    (
        "a Loki config with no ruler passes",
        {"extra_yml": {"loki/loki-config.yml": "auth_enabled: false\n",
                       "docker-compose.monitoring.yml": COMPOSE_LOKI}},
        None,
    ),
    (
        # Loki applies its command-line flags after its config file
        # (pkg/util/cfg DynamicUnmarshal), so the flag wires the ruler as
        # surely as `ruler.alertmanager_url` does.
        "a Loki ruler wired by its command-line flag with no rules is refused",
        {"extra_yml": {
            "loki/loki-config.yml": "auth_enabled: false\n",
            "docker-compose.monitoring.yml": COMPOSE_LOKI.replace(
                "    volumes:\n",
                '    command: ["-config.file=/etc/loki/loki-config.yml", '
                '"-ruler.alertmanager-url=http://alertmanager:9093"]\n    volumes:\n'),
        }},
        "the loki service's -ruler.alertmanager-url flag wires the ruler to http://alertmanager:9093",
    ),
    (
        # Go's flag package takes one dash or two, and a value after `=` or
        # as the next argument; Compose splits a string command like a shell.
        "the same flag with two dashes and its value as the next argument is refused",
        {"extra_yml": {
            "loki/loki-config.yml": LOKI_WITH_RULER.replace(
                "  alertmanager_url: http://alertmanager:9093\n", ""),
            "docker-compose.monitoring.yml": COMPOSE_LOKI.replace(
                "    volumes:\n",
                "    command: -config.file=/etc/loki/loki-config.yml --ruler.alertmanager-url http://am:9093\n"
                "    volumes:\n"),
        }},
        "flag wires the ruler to http://am:9093 and mounts no rule files at /loki/rules/<tenant>/",
    ),
    (
        "a ruler wired by its flag, with rule files mounted at its directory, passes",
        {"extra_yml": {
            "loki/loki-config.yml": LOKI_WITH_RULER.replace(
                "  alertmanager_url: http://alertmanager:9093\n", ""),
            "loki/rules/fake/drift.yml": LOKI_RULE_FILE,
            "docker-compose.monitoring.yml": COMPOSE_LOKI.replace(
                "      - loki-data:/loki\n",
                "      - loki-data:/loki\n      - ./loki/rules:/loki/rules:ro\n",
            ).replace(
                "    volumes:\n",
                '    command: ["-ruler.alertmanager-url=http://alertmanager:9093"]\n    volumes:\n'),
        }},
        None,
    ),
]


# -- Alertmanager's own answers (recorded) --------------------------------------
#
# Recorded from Alertmanager v0.28.1 -- matcher/compat.Matchers after
# compat.InitFromFlags with no feature flags, as cmd/alertmanager starts -- and
# from Go's regexp compiling ^(?:pattern)$, as labels.NewMatcher does.

# Lines Alertmanager reads, and what it reads them as. The walk must read each
# the same way: refusing one of these is a false red, and is a failure here.
MATCHERS_READ_ALIKE: list[tuple[str, list[tuple[str, str, str]]]] = [
    ("severity = critical", [("severity", "=", "critical")]),
    ('severity="critical"', [("severity", "=", "critical")]),
    ("severity!=info", [("severity", "!=", "info")]),
    ('{severity="critical", component="backups"}', [("severity", "=", "critical"), ("component", "=", "backups")]),
    ("component = backups, severity = critical", [("component", "=", "backups"), ("severity", "=", "critical")]),
    ('component =~ "backups|payments"', [("component", "=~", "backups|payments")]),
    ("component=~backups|payments", [("component", "=~", "backups|payments")]),
    ('instance =~ "pve-\\\\d+"', [("instance", "=~", "pve-\\d+")]),
    ('team = ""', [("team", "=", "")]),
    ('x:y = "a,b"', [("x:y", "=", "a,b")]),
    ('a = "multi\\nline"', [("a", "=", "multi\nline")]),
    ('a = "q\\"uote"', [("a", "=", 'q"uote')]),
    ("a = b\\", [("a", "=", "b\\")]),
    ("a=b,", [("a", "=", "b")]),
    ("  severity = critical  ", [("severity", "=", "critical")]),
    # A backslash before anything but `"`, `\` or `n` is kept.
    ('component =~ "backups|prov\\w+"', [("component", "=~", "backups|prov\\w+")]),
    ('component = "a\\b"', [("component", "=", "a\\b")]),
    ("component = a\\b", [("component", "=", "a\\b")]),
    ('component = "a\\\\b"', [("component", "=", "a\\b")]),
    # A single quote is not a quote.
    ("severity='critical'", [("severity", "=", "'critical'")]),
    ("component =~ 'backups|provisioning'", [("component", "=~", "'backups|provisioning'")]),
    # Go's regexp `\s` is [\t\n\f\r ], without the vertical tab Python's has.
    ("severity = critical\v,team = x", [("severity", "=", "critical\v"), ("team", "=", "x")]),
    ("severity =\vcritical", [("severity", "=", "\vcritical")]),
    # strings.TrimSpace keeps U+001F, which str.strip() removes.
    ("severity = critical\x1f", [("severity", "=", "critical\x1f")]),
]

# Lines the walk must refuse: Alertmanager refuses the first group; it reads
# the second only through its UTF-8 parser, which the walk does not port.
# The lone surrogate is a value only a YAML escape (`\ud800`) can produce,
# and go-yaml refuses that escape, so Alertmanager never loads the file.
MATCHERS_REFUSED: list[str] = [
    "component backups", 'a = "unterminated', 'a = b"c', "a=b,,c=d", ",a=b", 'a =~ "(unbalanced"',
    "a = \ud800",
    "1a = b", '"quoted name" = x', " {a=b}", "severity\v= critical",
]

# Regexes on which Go and Python agree, with Go's verdict on each string. The
# walk must match every string as Go does.
REGEX_VERDICTS: list[tuple[str, dict[str, bool]]] = [
    ("backups|prov\\w+", {"provisioning": True, "provw": True, "backups": True, "prov_1": True, "prové": False}),
    ("pve-\\d+", {"pve-01": True, "pve-": False, "pve-٣": False}),
    ("[a-z0-9_-]+", {"a-b_1": True, "A": False, "é": False}),
    ("[^\\W]+", {"abc": True, "a-b": False}),
    ("(?:ab|c)*d{2,3}", {"ababdd": True, "cddd": True, "dddd": False, "d": False}),
    ("a.c", {"abc": True, "a\nc": False, "a\rc": True}),
    ("[!-,-]x", {"!x": True, ",x": True, "-x": True, ".x": False}),
    ("(a|)+b", {"b": True, "aab": True, "ba": False}),
    ("x{0}y", {"y": True, "xy": False}),
    ("\\.\\*\\[\\]", {".*[]": True, "a*[]": False}),
]

# Regexes the walk must refuse: each is read differently by the two engines,
# or exists in only one of them. (Go / Python, in brief.)
REGEXES_REFUSED: list[str] = [
    "[[:alpha:]]+",   # POSIX class / a set, then a literal `]`
    "[x[:alpha:]|[a]",  # the same, and Python compiles it without a warning
    "a{,3}",          # literal / zero to three a's
    "a{01}",          # literal / exactly one a
    "a)|(b",          # re-anchors the wrapped pattern / an error
    "\\s",            # excludes \v / includes it
    "\\pL",           # a Unicode class / an error
    "(?i)a",          # Unicode case folding / ASCII-or-Unicode, differently
    "^a$",            # anchors inside the wrapper differ at a trailing newline
    "a\\z", "\\Q.\\E", "\\x{41}",     # Go only
    "a(?=b)", "(a)\\1", "a*+", "\\u00e9", "\\Z",  # Python only
    "(a{600}){2}",    # past Go's nested repeat limit / accepted
    "[a-c-e]", "[a&&b]", "[\\b]", "[]a]", "a{2}{3}",
    "[!--]",          # a range today in both; Python warns `--` may become set difference
]


def _as_matcher(name: str, op: str, value: str) -> str:
    """A classic-parser line whose value reads back as `value`."""
    return f'{name} {op} "' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'


def golden_failures() -> list[tuple[str, list[str]]]:
    checks: list[tuple[str, list[str]]] = []

    wrong = []
    for line, expected in MATCHERS_READ_ALIKE:
        try:
            got = [tuple(m) for m in validator.parse_matchers({"matchers": [line]})]
        except validator.RouteError as error:
            got = f"refused: {error}"
        if got != expected:
            wrong.append(f"{line!r}: Alertmanager reads {expected!r}, the walk {got!r}")
    checks.append(("each recorded matcher Alertmanager reads, the walk reads the same", wrong))

    wrong = []
    for line in MATCHERS_REFUSED:
        try:
            got = validator.parse_matchers({"matchers": [line]})
            wrong.append(f"{line!r}: read as {got!r}")
        except validator.RouteError:
            pass
    checks.append(("each recorded matcher the walk cannot read as Alertmanager does, it refuses", wrong))

    wrong = []
    for pattern, verdicts in REGEX_VERDICTS:
        try:
            parsed = validator.parse_matchers({"matchers": [_as_matcher("x", "=~", pattern)]})
        except validator.RouteError as error:
            wrong.append(f"{pattern!r}: refused ({error})")
            continue
        if parsed != [("x", "=~", pattern)]:
            wrong.append(f"{pattern!r}: read back as {parsed!r}")
            continue
        for text, go in verdicts.items():
            if validator.matches(parsed, {"x": text}) != go:
                wrong.append(f"{pattern!r} on {text!r}: Go says {go}")
    checks.append(("each recorded portable regex matches as Go's regexp matches", wrong))

    wrong = []
    for pattern in REGEXES_REFUSED:
        try:
            got = validator.parse_matchers({"matchers": [_as_matcher("x", "=~", pattern)]})
            wrong.append(f"{pattern!r}: read as {got!r}")
        except validator.RouteError:
            pass
    checks.append(("each recorded regex the two engines read differently is refused", wrong))

    return checks


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

    checks = golden_failures()
    for name, wrong in checks:
        print(f"{'PASS' if not wrong else 'FAIL'}  {name}")
        if wrong:
            failures += 1
            for line in wrong:
                print(f"      {line}")

    total = len(CASES) + 2 + len(checks)
    print(f"\n{total - failures}/{total} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
