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
        (monitoring / name).write_text(body)

    return infra


def run(**kwargs) -> tuple[int, str, str]:
    with tempfile.TemporaryDirectory() as tmp:
        infra = build(Path(tmp), **kwargs)
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = validator.main(["validate-monitoring.py", str(infra)])
        return code, out.getvalue(), err.getvalue()


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

    total = len(CASES) + 1
    print(f"\n{total - failures}/{total} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
