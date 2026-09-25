#!/usr/bin/env python3
"""Keep the runbooks pointed at alerts that exist.

A runbook's "What you are seeing" names the alert that brought the operator to
the page. When that alert does not exist, the page describes a warning that can
never arrive, and the operator who trusts it waits for a page that is not
coming. F-39 found fifteen of them. Every other check in this directory walks
one way -- `validate-monitoring.py` and `EveryAlertNamesARunbookThatExistsTest`
go from a rule to the runbook it names -- and nothing walked back. This does.

What counts as a citation
-------------------------
A backticked word on a page in `docs/runbooks/` is read as an alert name when it
is UpperCamelCase -- at least two capitalised segments, letters and digits only
-- and at least MIN_CITATION_LENGTH (eight) characters long. Every such word
must be an alert some rule file defines. Fenced code blocks are not read.

Eight has no margin: `NodeDown`, the shortest alert the rules define, is exactly
eight. So the gate also refuses a rule defining an alert the shape cannot see,
because a page citing it would be read as prose and an invented name that short
would pass unnoticed. Rename the alert or change the convention; do not leave
the two disagreeing.

A backticked word of that shape that is genuinely not an alert -- a test class
named in prose, say -- is declared so on the page that writes it, next to where
it is written, where the person editing the page sees it:

    <!-- not-an-alert: Word - the reason, in at least twelve characters -->

There is no carve-out list in this file, because a list here is invisible to
whoever writes the page. A declaration is refused when:

  - the page does not cite the word, so a declaration cannot outlive its
    reason and rot into a standing exemption;
  - the word is an alert some rule defines, so a declaration cannot silence a
    true citation;
  - nothing outside `docs/` names the word. Something that is not an alert and
    is worth backticking on an operator page -- a class, a status, a command --
    exists in the code or the configuration. An invented alert name exists only
    in prose, and calling it something else does not make it real. `docs/` is
    excluded as a whole because the remediation ledger and its briefs quote
    every invented name this gate was written to catch;
  - the reason is shorter than twelve characters, or the declaration cannot be
    parsed at all. A declaration quoted inside a code span is an example, not a
    declaration.

What else it checks
-------------------
The runbooks README carries a block of counts between `<!-- counts:begin -->`
and `<!-- counts:end -->` -- how many pages, how many alerts, how many of them
name a page here -- and the gate derives that block from the tree and compares
it byte for byte. A hand-typed count there once said 55 alerts when the rules
defined 64, and its arithmetic was self-consistent, which is why nobody noticed.
`--write` rewrites the block; nothing else here writes anything.

The README also lists, under "Pages with no alert here", every page that no
alert names with `runbook:`, each with the reason. The set is derived from the
rule files, not from the pages, and compared as a set: a new page with no alert
fails until somebody says why, and a listed page that an alert has started to
name fails until the row goes. `--write` does not remove that row. A count is a
fact a machine may recompute; a row is a person's claim about why there is no
alert, and deciding that it has stopped mattering is that person's job.

What it does not cover
----------------------
Only `docs/runbooks/`. A document elsewhere that claims an alert exists is not
read -- a convention-shaped sweep of the rest of `docs/` finds hundreds of class
names and one false claim, which F-39 corrected by hand.

Exit status 0 when every citation resolves and the README agrees with the tree,
1 otherwise.
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover
    print("PyYAML is required: pip install pyyaml", file=sys.stderr)
    raise SystemExit(2)

MIN_CITATION_LENGTH = 8
MIN_REASON_LENGTH = 12

CITATION_SHAPE = re.compile(r"(?:[A-Z][a-z0-9]*){2,}")
CODE_SPAN = re.compile(r"(?<!`)`([^`\n]+)`(?!`)")
FENCE = re.compile(r"^\s*(```|~~~)")
DECLARATION = re.compile(r"<!--\s*not-an-alert\b(.*?)-->")
DECLARATION_BODY = re.compile(r"^:\s*([A-Za-z0-9]+)\s+-\s+(.*?)\s*$")

COUNTS_BEGIN = "<!-- counts:begin -->"
COUNTS_END = "<!-- counts:end -->"
REGISTER_HEADING = "## Pages with no alert here"
REGISTER_ROW = re.compile(r"^-\s+`([^`]+)`\s*(?:—|-|:)?\s*(.*?)\s*$")

# Where a declared word is looked for. Top-level `docs/` is excluded on purpose
# (see the module docstring); the rest are dependencies, build output and
# runtime state, none of which is the platform describing itself.
SKIP_DIRS = frozenset({"node_modules", "vendor", "storage", "dist", "build", "coverage", "__pycache__"})
SEARCH_SUFFIXES = frozenset({
    ".php", ".py", ".ts", ".tsx", ".js", ".jsx", ".json", ".yml", ".yaml",
    ".md", ".sh", ".j2", ".tf", ".toml", ".xml", ".neon", ".txt", ".conf", ".ini",
})
# This gate and its self-test name invented words as fixtures; they cannot vouch
# for them.
SELF = frozenset({"validate-runbook-alerts.py", "test_validate_runbook_alerts.py"})


def is_citation(word: str) -> bool:
    """Whether a backticked word on a runbook page is read as an alert name."""
    return len(word) >= MIN_CITATION_LENGTH and CITATION_SHAPE.fullmatch(word) is not None


def read_page(text: str) -> tuple[list[tuple[int, str]], list[tuple[int, str]]]:
    """(line, word) for every citation-shaped code span, and (line, body) for
    every not-an-alert declaration, outside fenced blocks."""
    citations: list[tuple[int, str]] = []
    declarations: list[tuple[int, str]] = []
    fenced = False
    for number, line in enumerate(text.splitlines(), start=1):
        if FENCE.match(line):
            fenced = not fenced
            continue
        if fenced:
            continue
        for word in CODE_SPAN.findall(line):
            if is_citation(word):
                citations.append((number, word))
        for body in DECLARATION.findall(CODE_SPAN.sub("", line)):
            declarations.append((number, body))
    return citations, declarations


def load_alerts(rules_dir: Path) -> tuple[list[tuple[str, str, str | None]], int]:
    """(name, file, runbook) for every alert rule, and the number of rule files."""
    files = sorted(rules_dir.glob("*.yml"))
    alerts: list[tuple[str, str, str | None]] = []
    for path in files:
        document = yaml.safe_load(path.read_text()) or {}
        for group in document.get("groups") or []:
            for rule in group.get("rules") or []:
                if "alert" not in rule:
                    continue
                annotations = rule.get("annotations") or {}
                alerts.append((str(rule["alert"]), path.name, annotations.get("runbook")))
    return alerts, len(files)


def named_outside_docs(repo_root: Path, words: set[str]) -> set[str]:
    """The subset of `words` that some file outside `docs/` contains."""
    found: set[str] = set()
    if not words:
        return found
    for directory, subdirs, files in os.walk(repo_root):
        here = Path(directory)
        subdirs[:] = [
            name for name in subdirs
            if not name.startswith(".")
            and name not in SKIP_DIRS
            and not (here == repo_root and name == "docs")
        ]
        for name in files:
            if name in SELF or Path(name).suffix not in SEARCH_SUFFIXES:
                continue
            try:
                text = (here / name).read_text(errors="replace")
            except OSError:
                continue
            found.update(word for word in words - found if word in text)
            if found == words:
                return found
    return found


def render_counts(pages: int, alerts: int, named: int, no_alert: int) -> str:
    """The README's counts block, from the tree. The self-test pins the wording."""
    return "\n".join([
        COUNTS_BEGIN,
        "<!-- Written by `validate-runbook-alerts.py --write` from the tree, and "
        "compared on every run. Do not edit by hand. -->",
        f"- **{pages + 1}** files here: **{pages}** pages and this README.",
        f"- **{alerts}** alerts are defined in `infrastructure/monitoring/prometheus/rules/`.",
        f"- **{named}** of them name a page here with `runbook:`; **{alerts - named}** "
        f"{'names' if alerts - named == 1 else 'name'} no page here.",
        f"- **{no_alert}** of the {pages} pages {'is' if no_alert == 1 else 'are'} named by "
        f"no alert, and {'is' if no_alert == 1 else 'are'} listed under "
        f"*Pages with no alert here* with the reason.",
        COUNTS_END,
    ])


def counts_span(text: str) -> tuple[int, int] | None:
    begin = text.find(COUNTS_BEGIN)
    end = text.find(COUNTS_END, begin + 1) if begin >= 0 else -1
    if begin < 0 or end < 0:
        return None
    return begin, end + len(COUNTS_END)


def register_rows(text: str) -> list[tuple[str, str]] | None:
    """(page, reason) for each row under the register heading, or None if the
    README has no register."""
    lines = text.splitlines()
    try:
        start = lines.index(REGISTER_HEADING)
    except ValueError:
        return None
    rows: list[tuple[str, str]] = []
    for line in lines[start + 1:]:
        if line.startswith("## "):
            break
        match = REGISTER_ROW.match(line)
        if match:
            rows.append((match.group(1), match.group(2)))
    return rows


def main(argv: list[str]) -> int:
    write = "--write" in argv[1:]
    positional = [arg for arg in argv[1:] if arg != "--write"]
    infra_root = Path(positional[0]) if positional else Path(__file__).resolve().parent.parent
    repo_root = infra_root.parent
    books = repo_root / "docs" / "runbooks"
    readme_path = books / "README.md"

    rules_dir = infra_root / "monitoring" / "prometheus" / "rules"
    alerts, rule_files = load_alerts(rules_dir)
    if rule_files == 0:
        print(f"no rule files under {rules_dir}", file=sys.stderr)
        return 1
    if not alerts:
        print(
            f"{rule_files} rule file(s) define no alerts. There is nothing for a "
            f"citation to resolve to, so a green result would mean nothing was checked.",
            file=sys.stderr,
        )
        return 1

    defined: dict[str, str] = {}
    for name, file_name, _ in alerts:
        defined.setdefault(name, file_name)

    page_paths = sorted(path for path in books.glob("*.md") if path.name != "README.md")
    pages = {path.name for path in page_paths}
    named_pages = {
        runbook[len("docs/runbooks/"):]
        for _, _, runbook in alerts
        if isinstance(runbook, str) and runbook.startswith("docs/runbooks/")
    }
    named = sum(
        1 for _, _, runbook in alerts
        if isinstance(runbook, str) and runbook.startswith("docs/runbooks/")
    )
    no_alert = pages - named_pages

    problems: list[str] = []

    # An alert the citation shape cannot see is an alert a page can cite
    # without this gate reading it.
    for name, file_name in sorted(defined.items()):
        if not is_citation(name):
            problems.append(
                f"`{name}` is defined in {file_name}, and this gate would not read "
                f"it as a citation: it is not UpperCamelCase of at least "
                f"{MIN_CITATION_LENGTH} characters. A page could cite it, or an "
                f"invented name that short, and nothing would check either. Rename "
                f"the alert or change the convention."
            )

    # The README, rewritten first if asked, so everything after reads what will
    # be on disk.
    readme = readme_path.read_text() if readme_path.exists() else ""
    expected_counts = render_counts(len(pages), len(alerts), named, len(no_alert))
    span = counts_span(readme)
    if write and span is not None and readme[span[0]:span[1]] != expected_counts:
        readme = readme[:span[0]] + expected_counts + readme[span[1]:]
        readme_path.write_text(readme)
        print("rewrote the counts block in docs/runbooks/README.md")
        span = counts_span(readme)

    # Every citation on every page, the README included.
    citations = 0
    cited_names: set[str] = set()
    pending: list[tuple[str, int, str, str]] = []  # (page, line, word, reason)
    for path in page_paths + ([readme_path] if readme_path.exists() else []):
        text = readme if path == readme_path else path.read_text()
        where = f"docs/runbooks/{path.name}"
        found, declarations = read_page(text)
        cited_here = {word for _, word in found}

        excused: set[str] = set()
        for line, body in declarations:
            parsed = DECLARATION_BODY.match(body)
            if not parsed:
                problems.append(
                    f"{where}:{line}: a not-an-alert declaration that cannot be read. "
                    f"Write it as <!-- not-an-alert: Word - reason -->."
                )
                continue
            word, reason = parsed.groups()
            if word in defined:
                problems.append(
                    f"{where}:{line}: `{word}` is a defined alert ({defined[word]}), "
                    f"and a declaration cannot make a true citation prose."
                )
            elif word not in cited_here:
                problems.append(
                    f"{where}:{line}: declares `{word}` not an alert, which this page "
                    f"does not cite. Remove the declaration."
                )
            elif len(reason) < MIN_REASON_LENGTH:
                problems.append(
                    f"{where}:{line}: declares `{word}` not an alert with a reason "
                    f"shorter than twelve characters. Say what it is."
                )
            else:
                excused.add(word)
                pending.append((where, line, word, reason))

        for line, word in found:
            citations += 1
            if word in defined:
                cited_names.add(word)
            elif word not in excused:
                problems.append(
                    f"{where}:{line}: `{word}` is cited as an alert, and no rule file "
                    f"defines it. Name the alert that sends the operator here, or say "
                    f"that nothing will."
                )

    # A declaration is only as good as the thing it says the word is.
    corroborated = named_outside_docs(repo_root, {word for _, _, word, _ in pending})
    for where, line, word, _ in pending:
        if word not in corroborated:
            problems.append(
                f"{where}:{line}: declares `{word}` not an alert, and nothing outside "
                f"docs/ names it. A word that exists only in prose is an invented "
                f"alert name however it is described."
            )

    if citations == 0:
        print(
            f"found no alert citations in {len(page_paths)} page(s) under "
            f"docs/runbooks. Either the pages name no alert at all or the citation "
            f"shape has stopped matching them; either way nothing was checked.",
            file=sys.stderr,
        )
        return 1

    # The derived counts.
    if span is None:
        problems.append(
            f"docs/runbooks/README.md has no counts block. Add the two markers "
            f"{COUNTS_BEGIN} and {COUNTS_END} where the counts belong, and run "
            f"this with --write. It will hold:\n{expected_counts}"
        )
    elif readme[span[0]:span[1]] != expected_counts:
        problems.append(
            f"docs/runbooks/README.md: the counts block is stale. Run this with "
            f"--write, or replace it with:\n{expected_counts}"
        )

    # The register, compared as a set.
    rows = register_rows(readme)
    if rows is None:
        problems.append(
            f'docs/runbooks/README.md has no "Pages with no alert here" section. '
            f"These pages are named by no alert, and each needs a row saying why: "
            f"{', '.join(sorted(no_alert)) or '(none)'}"
        )
    else:
        listed: set[str] = set()
        for page, reason in rows:
            listed.add(page)
            if page not in pages:
                problems.append(
                    f"docs/runbooks/README.md: the register lists `{page}`, which "
                    f"does not exist. Remove the row."
                )
            elif page not in no_alert:
                naming = sorted(
                    name for name, _, runbook in alerts
                    if runbook == f"docs/runbooks/{page}"
                )
                problems.append(
                    f"docs/runbooks/README.md: the register lists `{page}` "
                    f"({', '.join(naming)} now name it with runbook:), which is no "
                    f"longer a page no alert names. Remove the row."
                )
            elif len(reason) < MIN_REASON_LENGTH:
                problems.append(
                    f"docs/runbooks/README.md: the register row for `{page}` gives "
                    f"no reason. Say why no alert sends anybody there."
                )
        for page in sorted(no_alert - listed):
            problems.append(
                f"docs/runbooks/README.md: `{page}` is named by no alert and is not "
                f'listed under "Pages with no alert here". Add a row saying why, or '
                f"point an alert at it."
            )

    print(
        f"{len(page_paths) + 1} file(s), {citations} citation(s) of "
        f"{len(cited_names)} defined alert(s), {len(alerts)} alert(s) in "
        f"{rule_files} rule file(s), {len(named_pages)} page(s) named by an alert, "
        f"{len(no_alert)} named by none"
    )
    for problem in problems:
        print(f"  FAIL {problem}", file=sys.stderr)
    return 1 if problems else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
