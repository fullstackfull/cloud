#!/usr/bin/env python3
"""Proof that the runbook-to-rules gate refuses what it claims to refuse.

Why this exists
---------------
F-39: fifteen alert names were cited in runbooks and defined in no rule file.
Every one of them was a page's "What you are seeing" naming an alert that could
never arrive. Nothing noticed, because every check in this directory walked one
way only -- rules to runbooks -- and `validate-runbook-alerts.py` is the walk
the other way.

A gate is only evidence once it has been seen to go red, and this one has more
ways to go quietly green than most: the citation shape could stop matching, the
escape hatch could be used to excuse an invented name, the derived counts could
be compared against themselves, and the register of pages with no alert could
be edited to agree with anything. Every case below builds a synthetic tree that
differs from a known-good one in one respect and requires the gate to name it.

The zero-and-two drift cases are the finding itself: a tree with no invented
name passes, and a tree with two fails naming both.

The threshold is pinned from both sides. `NodeDown` is the shortest alert the
rules define, at exactly eight characters, so the eight-character screen has no
margin: raising it silently ignores real citations (the gate stays green at a
threshold of twenty while reading nothing under twenty), and a seven-letter
alert written tomorrow would be invisible. The first is pinned here; the second
the gate refuses on the real tree.

Run: python3 infrastructure/scripts/test_validate_runbook_alerts.py
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
    "validate_runbook_alerts", HERE / "validate-runbook-alerts.py"
)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

RULES = """
groups:
  - name: platform.queue
    rules:
      - alert: QueueBacklogGrowing
        expr: lynomia_queue_depth > 250
        labels:
          severity: warning
        annotations:
          runbook: docs/runbooks/queue-backlog.md
      - alert: QueueBacklogSevere
        expr: lynomia_queue_depth > 2000
        labels:
          severity: critical
        annotations:
          runbook: docs/runbooks/queue-backlog.md
      - alert: CertificateExpired
        expr: probe_ssl_earliest_cert_expiry - time() < 0
        labels:
          severity: critical
        annotations:
          runbook_url: https://docs.example/runbooks/certificates
      - record: lynomia:queue_depth:sum
        expr: sum(lynomia_queue_depth)
"""

QUEUE_PAGE = """# The queue is backing up

## What you are seeing

`QueueBacklogGrowing` or `QueueBacklogSevere`.
"""

DEPLOY_PAGE = """# Deploy

## What you are doing

Putting a named commit onto the machines.
"""

# The derived block, written out by hand once, here, so that its wording and
# its arithmetic are pinned by something other than the function that renders
# it. Three files (two pages and the README), three alerts, two of which name a
# page here, one page named by no alert.
COUNTS = """<!-- counts:begin -->
<!-- Written by `validate-runbook-alerts.py --write` from the tree, and compared on every run. Do not edit by hand. -->
- **3** files here: **2** pages and this README.
- **3** alerts are defined in `infrastructure/monitoring/prometheus/rules/`.
- **2** of them name a page here with `runbook:`; **1** names no page here.
- **1** of the 2 pages is named by no alert, and is listed under *Pages with no alert here* with the reason.
<!-- counts:end -->"""

REGISTER = """## Pages with no alert here

- `deploy.md` — **Procedure.** A deploy is something a person starts.
"""

README = f"""# Runbooks

{COUNTS}

## Alerts with no page here

`CertificateExpired` relies on its `runbook_url`.

{REGISTER}"""

BUDGET_TEST_PHP = "<?php\n\nfinal class MetricsQueryBudgetTest extends TestCase {}\n"


def run(
    *,
    rules: dict[str, str] | None = None,
    pages: dict[str, str] | None = None,
    readme: str | None = README,
    other: dict[str, str] | None = None,
    write: bool = False,
) -> tuple[int, str, str, str | None]:
    """Build a synthetic repository, run the gate, return what it said.

    The fourth element is the README as the gate left it, so `--write` can be
    checked for what it wrote and what it refused to write.
    """
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        infra = root / "infrastructure"
        rules_dir = infra / "monitoring" / "prometheus" / "rules"
        rules_dir.mkdir(parents=True)
        for name, body in (rules if rules is not None else {"platform.yml": RULES}).items():
            (rules_dir / name).write_text(body)

        books = root / "docs" / "runbooks"
        books.mkdir(parents=True)
        chosen = pages if pages is not None else {
            "queue-backlog.md": QUEUE_PAGE,
            "deploy.md": DEPLOY_PAGE,
        }
        for name, body in chosen.items():
            (books / name).write_text(body)
        if readme is not None:
            (books / "README.md").write_text(readme)

        for relative, body in (other or {}).items():
            target = root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(body)

        argv = ["validate-runbook-alerts.py", str(infra)] + (["--write"] if write else [])
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = gate.main(argv)
        after = (books / "README.md").read_text() if (books / "README.md").exists() else None
        return code, out.getvalue(), err.getvalue(), after


def queue_page(*extra_lines: str) -> dict[str, str]:
    return {
        "queue-backlog.md": QUEUE_PAGE + "\n" + "\n".join(extra_lines) + "\n",
        "deploy.md": DEPLOY_PAGE,
    }


HATCH = "<!-- not-an-alert: MetricsQueryBudgetTest - a PHPUnit test class that bounds the query count -->"

CASES: list[tuple[str, dict, str | None]] = [
    # -- the finding, at zero and at two ---------------------------------
    ("a tree whose pages cite only defined alerts passes", {}, None),
    (
        "a page citing an alert no rule file defines is refused, by page and line",
        {"pages": queue_page("`QueueStalled` fires when nothing completes.")},
        "docs/runbooks/queue-backlog.md:7: `QueueStalled` is cited as an alert, and no rule file defines it",
    ),
    (
        "two invented names are both refused (the first)",
        {"pages": queue_page("`QueueStalled` or `FailedJobsRising`.")},
        "`QueueStalled` is cited as an alert",
    ),
    (
        "two invented names are both refused (the second)",
        {"pages": queue_page("`QueueStalled` or `FailedJobsRising`.")},
        "`FailedJobsRising` is cited as an alert",
    ),
    (
        "a truncation of two real alerts is not a citation of either",
        {"pages": queue_page("`QueueBacklog` fires first.")},
        "`QueueBacklog` is cited as an alert, and no rule file defines it",
    ),
    (
        "the README's own citations are checked like any page's",
        {"readme": README.replace("`CertificateExpired`", "`CertificateGone`")},
        "docs/runbooks/README.md:13: `CertificateGone` is cited as an alert",
    ),
    (
        "an alert name inside a fenced block is not read as a citation",
        {"pages": queue_page("```", "grep `NotReadHereAtAll` /var/log", "```")},
        None,
    ),
    # -- the escape hatch, and its four refused abuses -------------------
    (
        "a word declared not-an-alert on the page, and named outside docs/, passes",
        {
            "pages": queue_page("`MetricsQueryBudgetTest` bounds the query count.", HATCH),
            "other": {"apps/control-plane/tests/MetricsQueryBudgetTest.php": BUDGET_TEST_PHP},
        },
        None,
    ),
    (
        "without the declaration, the same word is refused",
        {
            "pages": queue_page("`MetricsQueryBudgetTest` bounds the query count."),
            "other": {"apps/control-plane/tests/MetricsQueryBudgetTest.php": BUDGET_TEST_PHP},
        },
        "`MetricsQueryBudgetTest` is cited as an alert",
    ),
    (
        "a declaration for an invented name nothing outside docs/ names is refused",
        {"pages": queue_page(
            "`ProviderTasksIndeterminate` fires on them.",
            "<!-- not-an-alert: ProviderTasksIndeterminate - it is only prose now, honestly -->",
        )},
        "nothing outside docs/ names it",
    ),
    (
        "naming the invented word in another document does not launder it",
        {
            "pages": queue_page(
                "`ProviderTasksIndeterminate` fires on them.",
                "<!-- not-an-alert: ProviderTasksIndeterminate - it is only prose now, honestly -->",
            ),
            "other": {"docs/some-report.md": "The `ProviderTasksIndeterminate` alert fires.\n"},
        },
        "nothing outside docs/ names it",
    ),
    (
        "a declaration over a defined alert cannot silence a true citation",
        {"pages": queue_page(
            "<!-- not-an-alert: QueueBacklogGrowing - it is prose here and nothing more -->",
        )},
        "`QueueBacklogGrowing` is a defined alert",
    ),
    (
        "a declaration for a word the page no longer cites is refused, so it cannot rot open",
        {
            "pages": queue_page(HATCH),
            "other": {"apps/control-plane/tests/MetricsQueryBudgetTest.php": BUDGET_TEST_PHP},
        },
        "which this page does not cite",
    ),
    (
        "a declaration with a reason under twelve characters is refused",
        {
            "pages": queue_page(
                "`MetricsQueryBudgetTest` bounds the query count.",
                "<!-- not-an-alert: MetricsQueryBudgetTest - a test -->",
            ),
            "other": {"apps/control-plane/tests/MetricsQueryBudgetTest.php": BUDGET_TEST_PHP},
        },
        "a reason shorter than twelve characters",
    ),
    (
        "a declaration the gate cannot parse is refused rather than ignored",
        {"pages": queue_page("<!-- not-an-alert MetricsQueryBudgetTest because -->")},
        "cannot be read",
    ),
    (
        "a declaration quoted in a code span is not a declaration",
        {"pages": queue_page(
            "`QueueStalled` fires.",
            "`<!-- not-an-alert: QueueStalled - quoted here as an example only -->`",
        )},
        "`QueueStalled` is cited as an alert",
    ),
    # -- the shape of a citation -----------------------------------------
    (
        "a rule defining an alert the citation shape cannot see is refused",
        {"rules": {"platform.yml": RULES, "nodes.yml": RULES.replace(
            "QueueBacklogGrowing", "NodeOff").replace("QueueBacklogSevere", "NodeOffline").replace(
            "CertificateExpired", "NodesRebooted").replace("lynomia:queue_depth:sum", "x:y")}},
        "`NodeOff` is defined in nodes.yml",
    ),
    (
        "pages with no alert citation anywhere are refused, not passed",
        {
            "pages": {"queue-backlog.md": "# Queue\n\nNothing cited.\n", "deploy.md": DEPLOY_PAGE},
            "readme": README.replace("`CertificateExpired`", "The certificate alert"),
        },
        "found no alert citations",
    ),
    (
        "a rules directory with no rule files is refused",
        {"rules": {}},
        "no rule files",
    ),
    (
        "rule files that define no alert are refused",
        {"rules": {"recording.yml": "groups:\n  - name: r\n    rules:\n      - record: a:b\n        expr: sum(x)\n"}},
        "define no alerts",
    ),
    # -- the derived counts ------------------------------------------------
    (
        "a README with no counts block is refused",
        {"readme": README.replace(COUNTS, "Three files. Three alerts.")},
        "has no counts block",
    ),
    (
        "a stale counts block is refused, and the failure prints the right text",
        {"readme": README.replace("**3** alerts", "**55** alerts")},
        "- **3** alerts are defined",
    ),
    (
        "one more alert in the rules makes an unchanged block stale",
        {"rules": {"platform.yml": RULES.replace(
            "      - record:",
            "      - alert: QueueBacklogCatastrophic\n        expr: lynomia_queue_depth > 9000\n"
            "        labels:\n          severity: critical\n        annotations:\n"
            "          runbook: docs/runbooks/queue-backlog.md\n      - record:")}},
        "counts block is stale",
    ),
    # -- the register of pages with no alert -------------------------------
    (
        "a README with no register is refused",
        {"readme": README.replace(REGISTER, "")},
        'has no "Pages with no alert here" section',
    ),
    (
        "a page no alert names, missing from the register, is refused until somebody says why",
        {"pages": {"queue-backlog.md": QUEUE_PAGE, "deploy.md": DEPLOY_PAGE, "restore.md": DEPLOY_PAGE},
         "readme": README.replace("**3** files here: **2** pages", "**4** files here: **3** pages").replace(
             "**1** of the 2 pages is named by no alert, and is listed",
             "**2** of the 3 pages are named by no alert, and are listed")},
        "`restore.md` is named by no alert and is not listed",
    ),
    (
        "a register row for a page an alert now names is refused, and says to remove it",
        {"readme": README.replace(
            REGISTER,
            REGISTER + "- `queue-backlog.md` — **Gap.** nothing reads the depth series at all.\n",
        )},
        "which is no longer a page no alert names. Remove the row.",
    ),
    (
        "a register row for a page that does not exist is refused",
        {"readme": README.replace(
            REGISTER,
            REGISTER + "- `vanished.md` — **Gap.** it was deleted at some point.\n",
        )},
        "`vanished.md`, which does not exist. Remove the row.",
    ),
    (
        "a register row with no reason is refused",
        {"readme": README.replace(
            "- `deploy.md` — **Procedure.** A deploy is something a person starts.",
            "- `deploy.md`",
        )},
        "`deploy.md` gives no reason",
    ),
]


def check_threshold() -> list[str]:
    """The screen is pinned at exactly eight, from both sides."""
    problems = []
    if gate.MIN_CITATION_LENGTH != 8:
        problems.append(f"MIN_CITATION_LENGTH is {gate.MIN_CITATION_LENGTH}, not 8")
    for word, expected in (
        ("NodeDown", True),            # the shortest defined alert, at exactly eight
        ("NodeDowns", True),
        ("NodeOff", False),            # seven letters: below the screen
        ("UNKNOWN", False),            # a registrar status the product displays
        ("QueueBacklogGrowing", True),
        ("NodeDiskWillFillIn4Hours", True),
        ("Horizonx", False),           # one capitalised segment
        ("queueBacklog", False),
        ("BLOCKED_LICENCE", False),
    ):
        if gate.is_citation(word) is not expected:
            problems.append(f"is_citation({word!r}) is {not expected}, expected {expected}")
    return problems


def check_write() -> list[str]:
    """`--write` fixes a count and refuses to decide a register row."""
    problems = []

    stale = README.replace("**3** alerts", "**55** alerts")
    code, _, err, after = run(readme=stale, write=True)
    if code != 0 or err.strip() or after != README:
        problems.append(f"--write over a stale block: exit {code}, stderr {err!r}, block rewritten: {after == README}")

    row = "- `queue-backlog.md` — **Gap.** nothing reads the depth series at all.\n"
    both = stale.replace(REGISTER, REGISTER + row)
    code, _, err, after = run(readme=both, write=True)
    if code != 1 or "Remove the row." not in err:
        problems.append(f"--write with a stale register row: expected a refusal, got exit {code}")
    if after is None or row not in after:
        problems.append("--write deleted a register row; that is a person's decision")
    if after is None or "**3** alerts" not in after:
        problems.append("--write did not rewrite the counts block alongside the refusal")
    return problems


def main() -> int:
    failures = 0
    for name, kwargs, expected in CASES:
        code, _, err, _ = run(**kwargs)
        if expected is None:
            ok = code == 0 and not err.strip()
        else:
            ok = code == 1 and expected in err
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got exit {code} and stderr:\n{err}")

    for name, check in (
        ("the citation threshold is exactly eight, pinned from both sides", check_threshold),
        ("--write rewrites the counts and leaves the register to a person", check_write),
    ):
        problems = check()
        print(f"{'PASS' if not problems else 'FAIL'}  {name}")
        for problem in problems:
            print(f"      {problem}")
        failures += bool(problems)

    total = len(CASES) + 2
    print(f"\n{total - failures}/{total} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
