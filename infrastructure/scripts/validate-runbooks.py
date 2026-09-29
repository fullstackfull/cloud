#!/usr/bin/env python3
"""Keep the runbooks pointed at commands that exist.

A runbook is read by somebody who did not build the system, at an hour when
they are not at their best. A command in it that does not exist costs them the
minutes they have least of, and quietly tells them the rest of the document is
guesswork too.

This is the same check as validate-monitoring.py, aimed at a different kind of
dangling reference: it reads the artisan commands the application actually
defines out of the PHP source, and fails on any `php artisan` invocation in a
runbook that names something else.

It parses signatures rather than booting Laravel, so it runs in CI without PHP,
a database or a vendor directory.

Exit status 0 when every referenced command exists, 1 otherwise.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

# `php artisan foo:bar --baz` in a fenced block or inline.
INVOCATION = re.compile(r"php\s+artisan\s+([a-z][a-z0-9:_-]*)")

# `protected $signature = 'foo:bar {--baz}'` in a console command.
SIGNATURE = re.compile(r"\$signature\s*=\s*['\"]([a-z][a-z0-9:_-]*)")

# Framework and package commands. Listed explicitly rather than discovered,
# because discovering them means booting the application, and this check has to
# run in a job that has neither PHP nor a database. A command added here should
# be one somebody has actually seen `php artisan list` print.
FRAMEWORK = {
    "migrate", "migrate:status", "migrate:rollback", "migrate:fresh",
    "queue:work", "queue:listen", "queue:monitor", "queue:failed",
    "queue:retry", "queue:forget", "queue:flush", "queue:restart",
    "queue:clear", "queue:pause", "queue:resume", "queue:prune-failed",
    "schedule:run", "schedule:work", "schedule:list", "schedule:test",
    "schedule:interrupt", "schedule:clear-cache",
    "horizon:status", "horizon:pause", "horizon:continue", "horizon:terminate",
    "config:cache", "config:clear", "config:show",
    "cache:clear", "route:cache", "route:clear", "view:cache", "view:clear",
    "optimize", "optimize:clear", "about", "down", "up", "tinker",
}


def defined_commands(repo_root: Path) -> set[str]:
    found: set[str] = set()
    for path in (repo_root / "apps" / "control-plane" / "src").rglob("*.php"):
        found.update(SIGNATURE.findall(path.read_text(errors="replace")))
    for path in (repo_root / "apps" / "control-plane" / "app").rglob("*.php"):
        found.update(SIGNATURE.findall(path.read_text(errors="replace")))
    return found


def main(argv: list[str]) -> int:
    infra_root = Path(argv[1]) if len(argv) > 1 else Path(__file__).resolve().parent.parent
    repo_root = infra_root.parent

    defined = defined_commands(repo_root)
    if not defined:
        print("found no artisan command signatures in the source", file=sys.stderr)
        return 1

    known = defined | FRAMEWORK
    problems: list[str] = []
    referenced = 0

    # The runbooks live beside the rest of the operator documentation; the
    # playbooks that run artisan on a real host live in the infrastructure tree.
    # Both are read by somebody acting on a live system, so both are checked.
    corpora = (
        ("docs/runbooks/**/*.md", sorted((repo_root / "docs" / "runbooks").rglob("*.md"))),
        ("infrastructure/**/*.md", sorted(infra_root.rglob("*.md"))),
        ("infrastructure/ansible/**/*.yml", sorted((infra_root / "ansible").rglob("*.yml"))),
    )

    # Each corpus is asserted on its own, because a union stays non-empty
    # while any one of its parts does. With docs/runbooks moved aside this
    # read the other two, found the infrastructure tree's single invocation,
    # and printed a clean summary line -- having checked not one runbook, under
    # a step named for the runbooks. Non-emptiness, not size: a corpus
    # narrowed to one file still passes, and that is the bar here.
    empty = [label for label, files in corpora if not files]
    if empty:
        for label in empty:
            print(
                f"{label} matches no file. This gate reads it as one of its "
                f"subjects; a subject that has moved or been renamed is not a "
                f"subject that passed.",
                file=sys.stderr,
            )
        return 1

    targets = [path for _, files in corpora for path in files]

    for path in targets:
        for line_no, line in enumerate(path.read_text().splitlines(), start=1):
            for command in INVOCATION.findall(line):
                referenced += 1
                if command not in known:
                    problems.append(
                        f"{path.relative_to(repo_root)}:{line_no}: "
                        f"'php artisan {command}' — no such command"
                    )

    print(
        f"{len(targets)} file(s), {referenced} artisan invocation(s), "
        f"{len(defined)} command(s) defined by this application"
    )

    # A check with nothing to check is not a check that found nothing wrong.
    # The empty `defined` set is already refused above; this is the other
    # direction, and it is the one that has actually happened in this
    # repository before -- a step scanning a directory that had been moved,
    # printing a green line for years. A moved corpus is refused above, one
    # corpus at a time; this count alone would not see it, because the
    # infrastructure tree's own invocation keeps it above zero with every
    # runbook gone. What reaches here is the files all being present and the
    # invocation regex no longer matching the way they spell commands.
    if referenced == 0:
        print(
            f"found no `php artisan` invocation anywhere in {len(targets)} "
            f"documentation file(s). The documentation is there, and either "
            f"it names no command or it stopped spelling commands in a way "
            f"this recognises. A gate with no subject is not a pass.",
            file=sys.stderr,
        )
        return 1

    for problem in problems:
        print(f"  FAIL {problem}", file=sys.stderr)

    return 1 if problems else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
