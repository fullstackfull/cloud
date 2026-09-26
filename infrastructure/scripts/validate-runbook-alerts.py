#!/usr/bin/env python3
r"""Keep the runbooks pointed at alerts that exist.

A runbook's "What you are seeing" names the alert that brought the operator to
the page. When that alert does not exist, the page describes a warning that can
never arrive, and the operator who trusts it waits for a page that is not
coming. F-39 found fifteen of them. Every other check in this directory walks
one way -- `validate-monitoring.py` and `EveryAlertNamesARunbookThatExistsTest`
go from a rule to the runbook it names -- and nothing walked back. This does.

What counts as a citation
-------------------------
A word on a page in `docs/runbooks/` is read as an alert name when it is the
whole content of a code span as this gate reads one, surrounding spaces
ignored, and it is UpperCamelCase (at least two capitalised segments, letters
and digits only) of at least MIN_CITATION_LENGTH (eight) characters. Each word
read that way must be an alert some rule file defines.

What this gate reads is a grammar of its own, applied one line at a time, not a
Markdown parser:

  - a code span (CODE_SPAN) is, on a single line, a run of backticks with no
    backtick either side of it, closed by the next run of exactly the same
    length;
  - a fence (FENCE_OPEN) opens at a line of up to three spaces and then three
    or more backticks, or three or more tildes, where a backtick run is not
    followed by another backtick on that line. It closes only at a line of up
    to three spaces, then a run of the same character at least as long, then
    nothing but spaces or tabs. A fence never closed runs to the end of the
    page. No line of a fence is read, its own two included.

Where a Markdown renderer shows a code span that this grammar does not read, a
citation there passes unread. These are the places attack has found. They are
where the attacks stopped, not the boundary, and nobody here has established
the boundary; what can be measured is how often each occurs. On the pages in
`docs/runbooks/` none of them occurs today, as these commands from the
repository root show:

  - A code span whose backticks are on different lines, or whose pairing a
    backslash-escaped backtick or a backtick inside an HTML tag shifts. Outside
    fence lines, no line has an odd number of backticks or a run of two or
    more, and none has an escaped backtick; one line has a backtick inside an
    HTML tag, the README's counts comment, around a word not of citation shape:

      grep -hvE '^ {0,3}(`{3,}|~{3,})' docs/runbooks/*.md | awk -F'`' 'NF > 1 && NF % 2 == 0' | wc -l    # 0
      grep -hvE '^ {0,3}(`{3,}|~{3,})' docs/runbooks/*.md | grep -c '``'                                 # 0
      grep -h '\\`' docs/runbooks/*.md | wc -l                                                           # 0
      grep -nE '<[A-Za-z!/][^>]*`' docs/runbooks/*.md                                                    # README.md:11

  - A fence line inside an HTML block that spans lines (a comment, a `<pre>`),
    which this gate reads as a fence. Four lines open an HTML block, and each
    is a comment that closes on the same line:

      grep -nE '^ {0,3}<' docs/runbooks/*.md | wc -l                     # 4
      grep -nE '^ {0,3}<' docs/runbooks/*.md | grep -vc -- '-->'          # 0

  - A fence inside a list item, two ways. One opened on the list marker's own
    line (a `-`, `*`, `+` or `1.` and then the backticks) is not a fence to
    this gate, which then reads its closing line as an opening one and skips
    what follows; no such line exists. One opened on a line of its own that
    the list item ends before the fence's closing line is read on to that
    line; only a fence opened with one to three spaces can be inside a list
    item and read as a fence here, there are seven, fourteen fence lines, and
    no line inside any of them is indented less than its opening line, which
    is what would end the item:

      grep -nE '^ *([-*+]|[0-9]+[.)]) +(`{3,}|~{3,})' docs/runbooks/*.md | wc -l    # 0
      grep -hcE '^ {1,3}(`{3,}|~{3,})' docs/runbooks/*.md | paste -sd+ | bc        # 14
      awk 'FNR == 1 { open = 0 } /^  ? ?(```|~~~)/ { if (open) open = 0; else { open = 1; ind = match($0, /[`~]/) - 1 }; next } open && NF && match($0, /[^ ]/) - 1 < ind { n++ } END { print n + 0 }' docs/runbooks/*.md   # 0

The other way round -- text a renderer shows as something other than a code
span, which this grammar reads as one: the lines of an indented code block, of
a fence in a block quote or of one indented four spaces or more, an HTML
comment, a link destination -- is read as a citation. On a page such a word is
checked like any citation, so it can add a refusal, or count as the use that
keeps a declaration naming it; it excuses nothing else. Under the README's
"Alerts with no page here" it would also count as listing the alert; that
section holds none of those forms today:

      sed -n '/^## Alerts with no page here/,/^## Pages with no alert here/p' docs/runbooks/README.md | grep -cE '^    |^ *>|<|\]\('   # 0

As a cross-check, not a proof: on 2026-09-26 the 59 citations this gate read in
`docs/runbooks/` were, page by page, exactly the code spans whose trimmed
content is of citation shape that league/commonmark 2.10.0 -- the control
plane's own Markdown parser, with its GitHub-flavoured extension -- found
there.

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
  - no code or configuration file outside `docs/` names the word. A word
    that is not an alert and is worth backticking on an operator page -- a
    class, a status, a command -- is usually defined or used in the code or
    the configuration, so one of those files names it; one that is not has to
    be written some other way than as a citation. What is searched is decided
    by the last suffix of a file's name alone (SEARCH_SUFFIXES, code and
    configuration only): a name ending `.md`, `.txt` or `.rst` is not searched,
    in `docs/` or anywhere else, because an invented alert name can be written
    into any document and calling it something else there does not make it
    real. Only the last suffix is looked at, so a Markdown template named
    `notes.md.j2` would be searched as a `.j2` file. There is none today:

      git ls-files | grep -cE '\.(md|markdown|txt|rst)\.[^/.]+$'    # 0

    Top-level `docs/` is excluded whatever the file type, because the
    remediation ledger and its briefs quote the invented names this gate was
    written to catch, and so are this gate and its self-test, which quote them
    as fixtures. Naming means as a word of its own, with no letter, digit or
    underscore on either side: a rule defining `QueueBacklogGrowing` does not
    name `QueueBacklog`, because a truncation that matches two real alerts is
    still not an alert;
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

The same holds the other way round for "Alerts with no page here": the alerts
no rule points at a page here with `runbook:`, derived from the rule files and
compared as a set with the citations this gate reads in that section. An alert
that gains a page fails until it leaves the section, and a new alert with none
fails until it is listed. `--write` touches neither list.

What it does not cover
----------------------
Only `docs/runbooks/`. A document elsewhere that claims an alert exists is not
read -- a convention-shaped sweep of the rest of `docs/` finds hundreds of class
names and one false claim, which F-39 corrected by hand.

Prose inside a code or configuration file is not told apart from code. Such a
file is read whole, comments, docstrings and message strings included, because
where prose ends inside one cannot be drawn by syntax: a docstring and an
exception message are string literals like any other. So an occurrence
anywhere in a code or configuration file vouches for a declared word -- a line
`# TODO: add QueueStalled` in a rule file is enough for `QueueStalled` to pass
as not an alert. The review of that line is the other half of this check, and
the self-test pins the behaviour so this paragraph cannot go stale unnoticed.

Exit status 0 when each citation it reads resolves and the README agrees with
the tree, 1 when anything does not, 2 when PyYAML is missing.
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
# A code span as this gate reads one: on a single line, a run of backticks
# with no backtick either side of it, closed by the next run of exactly the
# same length. Its content is stripped of surrounding whitespace before it is
# read, so `` Word `` and ``Word`` are the same citation as `Word`. It pairs
# backtick runs the way CommonMark does, on one line, and reads nothing else of
# CommonMark's inline grammar -- not backslash escapes, not HTML, not
# autolinks. The module docstring lists where the two are known to part
# company, and how often each occurs on the pages today.
CODE_SPAN = re.compile(r"(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)")
# A fence as this gate reads one. It opens at a line of up to three spaces and
# then a run of three or more backticks, or three or more tildes; a backtick
# run followed by another backtick anywhere on the line does not open one. It
# closes only at a line of up to three spaces, then a run of the SAME character
# at least as long as the opening run, then nothing but spaces or tabs. So a
# tilde fence is not closed by backticks, nor a backtick fence by tildes, a
# shorter run or a run with a word after it. A fence never closed runs to the
# end of the page. The lines of a fence, its own two included, are not read.
FENCE_OPEN = re.compile(r"^ {0,3}(`{3,}|~{3,})(.*)$")
DECLARATION = re.compile(r"<!--\s*not-an-alert\b(.*?)-->")
DECLARATION_BODY = re.compile(r"^:\s*([A-Za-z0-9]+)\s+-\s+(.*?)\s*$")

COUNTS_BEGIN = "<!-- counts:begin -->"
COUNTS_END = "<!-- counts:end -->"
REGISTER_HEADING = "## Pages with no alert here"
REGISTER_ROW = re.compile(r"^-\s+`([^`]+)`\s*(?:—|-|:)?\s*(.*?)\s*$")
PAGELESS_HEADING = "## Alerts with no page here"

# Where a declared word is looked for. Top-level `docs/` is excluded on purpose
# (see the module docstring); the rest are dependencies, build output and
# runtime state, none of which is the platform describing itself. Directories
# whose name starts with a dot are skipped too: a git worktree of another
# branch lives under `.claude/worktrees/`, and that branch's code does not
# vouch for this one's pages.
SKIP_DIRS = frozenset({"node_modules", "vendor", "storage", "dist", "build", "coverage", "__pycache__"})
# Code and configuration only, compared with the last suffix of a file's name
# and nothing else (so `notes.md.j2` is searched as `.j2`; the module docstring
# counts such files). No document suffix belongs here -- not `.md`, not
# `.txt`, not `.rst` -- because a document can vouch for any word by naming
# it, which is exactly what an invented alert name needs.
SEARCH_SUFFIXES = frozenset({
    ".php", ".py", ".ts", ".tsx", ".js", ".jsx", ".json", ".yml", ".yaml",
    ".sh", ".j2", ".tf", ".toml", ".xml", ".neon", ".conf", ".ini",
})
# This gate and its self-test name invented words as fixtures; they cannot vouch
# for them.
SELF = frozenset({"validate-runbook-alerts.py", "test_validate_runbook_alerts.py"})


def is_citation(word: str) -> bool:
    """Whether a backticked word on a runbook page is read as an alert name."""
    return len(word) >= MIN_CITATION_LENGTH and CITATION_SHAPE.fullmatch(word) is not None


def opens_fence(line: str) -> str | None:
    """The opening run, if `line` opens a fence as FENCE_OPEN describes."""
    opened = FENCE_OPEN.match(line)
    if opened is None:
        return None
    run, rest = opened.groups()
    if run[0] == "`" and "`" in rest:
        return None
    return run


def closes_fence(line: str, run: str) -> bool:
    """Whether `line` closes the fence that `run` opened."""
    return re.fullmatch(rf" {{0,3}}{re.escape(run[0])}{{{len(run)},}}[ \t]*", line) is not None


def read_page(text: str) -> tuple[list[tuple[int, str]], list[tuple[int, str]]]:
    """(line, word) for each code span of citation shape, and (line, body) for
    each not-an-alert declaration, on the lines outside the fences this gate
    reads (FENCE_OPEN)."""
    citations: list[tuple[int, str]] = []
    declarations: list[tuple[int, str]] = []
    fence: str | None = None  # the opening run of the fence this line is in
    for number, line in enumerate(text.splitlines(), start=1):
        if fence is not None:
            if closes_fence(line, fence):
                fence = None
            continue
        fence = opens_fence(line)
        if fence is not None:
            continue
        for _, content in CODE_SPAN.findall(line):
            word = content.strip()
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


def names_word(text: str, word: str) -> bool:
    """Whether `text` names `word` as a word of its own.

    A letter, digit or underscore on either side makes it part of a longer
    identifier, which is a different name: `QueueBacklogGrowing` contains
    `QueueBacklog` and does not name it.
    """
    return re.search(rf"(?<![A-Za-z0-9_]){re.escape(word)}(?![A-Za-z0-9_])", text) is not None


def named_outside_docs(repo_root: Path, words: set[str]) -> set[str]:
    """The subset of `words` that some code or configuration file outside
    `docs/` names as a word, anywhere in it."""
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
            found.update(word for word in words - found if names_word(text, word))
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


def section(text: str, heading: str) -> list[str] | None:
    """The lines under `heading`, up to the next `## `, or None if the README
    has no such heading."""
    lines = text.splitlines()
    try:
        start = lines.index(heading)
    except ValueError:
        return None
    body: list[str] = []
    for line in lines[start + 1:]:
        if line.startswith("## "):
            break
        body.append(line)
    return body


def register_rows(text: str) -> list[tuple[str, str]] | None:
    """(page, reason) for each row under the register heading, or None if the
    README has no register."""
    lines = section(text, REGISTER_HEADING)
    if lines is None:
        return None
    rows: list[tuple[str, str]] = []
    for line in lines:
        match = REGISTER_ROW.match(line)
        if match:
            rows.append((match.group(1), match.group(2)))
    return rows


def pageless_listed(text: str) -> set[str] | None:
    """Each citation this gate reads under the "Alerts with no page here"
    heading, or None if the README has no such section."""
    lines = section(text, PAGELESS_HEADING)
    if lines is None:
        return None
    found, _ = read_page("\n".join(lines))
    return {word for _, word in found}


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

    # Each citation this gate reads, on each page, the README included.
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
                f"{where}:{line}: declares `{word}` not an alert, and no code or "
                f"configuration file outside docs/ names it. A .md, .txt or .rst "
                f"file is not searched, wherever it is, because any document can "
                f"name an invented alert. Cite the alert that sends the operator "
                f"here, or say that nothing will."
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

    # The other list, alerts with no page here, compared as a set the same way.
    # A listed word no rule defines is already refused above as a citation.
    pageless = {
        name for name, _, runbook in alerts
        if not (isinstance(runbook, str) and runbook.startswith("docs/runbooks/"))
    }
    listed_alerts = pageless_listed(readme)
    if listed_alerts is None:
        problems.append(
            f'docs/runbooks/README.md has no "Alerts with no page here" section. '
            f"These alerts name no page here, and each needs listing there: "
            f"{', '.join(sorted(pageless)) or '(none)'}"
        )
    else:
        for name in sorted((listed_alerts & set(defined)) - pageless):
            naming = sorted({str(runbook) for other, _, runbook in alerts if other == name})
            problems.append(
                f'docs/runbooks/README.md: "Alerts with no page here" lists `{name}`, '
                f"which names {', '.join(naming)} with runbook:. Remove it from the list."
            )
        for name in sorted(pageless - listed_alerts):
            problems.append(
                f"docs/runbooks/README.md: `{name}` names no page here and is not listed "
                f'under "Alerts with no page here". List it, or give it a page with runbook:.'
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
