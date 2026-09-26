#!/usr/bin/env python3
"""The OpenTofu root modules under infrastructure/tofu: where `tofu validate` runs.

Why this exists
---------------
CI's step "OpenTofu is formatted and valid" used to run `tofu init` and
`tofu validate` in each of infrastructure/tofu/environments/*/. Those
directories hold only `.example` templates and no configuration at all, and
OpenTofu 1.8.7 -- the version that step installed -- answers a directory with
no configuration with "OpenTofu initialized in an empty directory!" and
"Success! The configuration is valid.", exit 0 both times. So `validate`
never parsed a line of this repository's configuration, and passed (F-38).

A directory named in a workflow can be emptied by a move and go on passing.
So the step no longer names one: it validates what this script prints, and
this script derives it from the tree.

What it reads
-------------
Every directory under infrastructure/tofu, except those whose name starts
with a dot (`.terraform/` is OpenTofu's own working directory). A symbolic
link to a directory is not followed: it is refused, so that what is validated
is not decided by where a link points. A directory is
a module when it holds a configuration file directly: a name ending `.tf`,
`.tofu`, `.tf.json` or `.tofu.json`, which is what OpenTofu loads -- it does
not read subdirectories, and an `.example` file is not configuration. A module
is called when another module's `module` block names it by a local `source`
(one starting `./` or `../`), read from the text of a `.tf` or `.tofu` file by
LOCAL_SOURCE and from the `module` object of a JSON one. A root module is one
that no other calls, and validating it validates each module it calls, with
the inputs it passes, and each module those call in turn. A module no other
calls is printed as a root of its own, so a module left behind by a refactor
is still validated, standalone.

LOCAL_SOURCE is a pattern, not an HCL parser: it takes any `source = "./..."`
or `source = "../..."` left once code_text has removed the comment forms it
recognises. It recognises exactly these, and claims nothing outside them: `#`
and `//` to the end of the line and `/* */` blocks, outside double-quoted
strings; double-quoted strings, with backslash escapes, `${ }` and `%{ }`
interpolations (which may hold strings of their own) and the `$${` and `%%{`
escapes, which are text; and heredocs opened by `<<EOT` or `<<-EOT` at the end
of a line, the marker an identifier of letters, digits, `_` and `-`, dropped
through the line holding only the marker. A call commented
out in one of those forms is not read as a call; one hidden by a construct
outside that list may be. A `source = "./..."` written as an attribute
outside a `module` block is read as a call. The self-test pins these
readings.

Because that reading is not HCL's and is not claimed complete, the step has a
backstop that does not depend on it: run with --loaded-by-tofu after `tofu
init` in each root, this reads the module manifest init writes
(`.terraform/modules/modules.json`) in every root and refuses any module
directory that is neither a root nor loaded by some root's init. The manifest
means that only if init wrote it afresh: OpenTofu 1.10.7 merges into a
manifest already there, so an entry left from an earlier init, or a committed
one, survives and reads as loaded. The CI step therefore removes each root's
`.terraform/` before its init, and refuses a tracked path under any
`.terraform/` before anything runs. Given that, a module this script took for
called and OpenTofu never loaded fails there. Run by hand in a tree where
init ran earlier, the check can pass on such a stale entry.

What it refuses
---------------
Exit status 1, printing nothing to stdout, when infrastructure/tofu holds no
configuration at all, when it holds a symbolic link to a directory, when a
configuration file holds a `/*` never closed or one inside another block
comment, when a local `source` names a directory that holds none, or when a module is reached
from no root -- modules that call only each other, which no validate would
ever load, whether or not a real root stands beside them. An empty answer would give the validate step nothing to run, which is
the silent pass this exists to end. Otherwise exit 0 and one root per line,
relative to the repository root, sorted.

Run: python3 infrastructure/scripts/list-tofu-root-modules.py [repository root]
     python3 infrastructure/scripts/list-tofu-root-modules.py [root] --loaded-by-tofu
"""

from __future__ import annotations

import json
import os
import re
import sys
from pathlib import Path

CONFIGURATION_SUFFIXES = (".tf", ".tofu", ".tf.json", ".tofu.json")

# `source = "./modules/x"` or `source = "../x"`, anywhere in the text.
LOCAL_SOURCE = re.compile(r"""\bsource\s*=\s*"(\.\.?/[^"]*)\"""")
# A heredoc opening at the end of a line: `<<EOT` or `<<-EOT`. The marker is
# an HCL identifier, which may hold `-` after its first character (`<<END-X`).
HEREDOC = re.compile(r"<<-?\s*([A-Za-z_][A-Za-z0-9_-]*)[ \t]*(?:\n|$)")


class CommentError(ValueError):
    """A block comment shape code_text refuses to guess at."""


def code_text(text: str) -> str:
    """`text` with the comment forms it recognises and heredoc bodies removed.

    What it recognises is listed here and nothing else is claimed: `#` and
    `//` start a comment to the end of the line, and `/*` one to the next
    `*/`, where they stand outside a double-quoted string -- after code on
    the same line included. A double-quoted string is followed through its
    backslash escapes, its `${ }` and `%{ }` interpolations (in which a quote
    opens a string of its own) and its `$${` and `%%{` escapes, which are
    text and open nothing; inside it the comment markers are text. A heredoc
    (`<<EOT` or `<<-EOT` ending a line) is dropped through the line holding
    only its marker. Raises CommentError on a `/*` never closed, and on a
    `/*` inside a block comment, whose reading here would be a guess. The
    module manifest check (loaded_problems) is the backstop for whatever this
    misreads.
    """
    out: list[str] = []
    stack: list[str] = []  # "string", or "brace" for a { opened in code or a template
    closing: str | None = None  # the marker of the heredoc being skipped
    at = 0
    while at < len(text):
        if closing is not None:
            end = text.find("\n", at)
            line = text[at:] if end < 0 else text[at:end]
            at = len(text) if end < 0 else end + 1
            if line.strip() == closing:
                closing = None
                out.append("\n")
            continue
        char, pair = text[at], text[at:at + 2]
        if stack and stack[-1] == "string":
            if char == "\\":
                out.append(text[at:at + 2])
                at += 2
            elif text.startswith(("$${", "%%{"), at):
                out.append(text[at:at + 3])  # an escaped `${` or `%{`: text
                at += 3
            elif pair in ("${", "%{"):
                stack.append("brace")
                out.append(pair)
                at += 2
            elif char == '"':
                stack.pop()
                out.append(char)
                at += 1
            else:
                out.append(char)
                at += 1
            continue
        if char == "#" or pair == "//":
            end = text.find("\n", at)
            at = len(text) if end < 0 else end
            continue
        if pair == "/*":
            end = text.find("*/", at + 2)
            if end < 0:
                raise CommentError("an unterminated /* comment")
            if "/*" in text[at + 2:end]:
                raise CommentError("a nested /* comment")
            out.append("\n" * text.count("\n", at, end))
            at = end + 2
            continue
        if pair == "<<":
            opened = HEREDOC.match(text, at)
            if opened:
                closing = opened.group(1)
                out.append("\n")
                at = opened.end()
                continue
        if char == '"':
            stack.append("string")
        elif char == "{":
            stack.append("brace")
        elif char == "}" and stack:
            stack.pop()
        out.append(char)
        at += 1
    return "".join(out)


def configuration_files(directory: Path) -> list[Path]:
    return sorted(
        path for path in directory.iterdir()
        if path.is_file() and path.name.endswith(CONFIGURATION_SUFFIXES)
    )


def local_sources(path: Path) -> list[str]:
    """The local module sources a configuration file names."""
    if path.name.endswith(".json"):
        try:
            document = json.loads(path.read_text())
        except ValueError:
            return []  # OpenTofu refuses the file; validate says so
        modules = document.get("module") if isinstance(document, dict) else None
        found: list[str] = []
        for block in (modules or {}).values() if isinstance(modules, dict) else []:
            for body in block if isinstance(block, list) else [block]:
                source = body.get("source") if isinstance(body, dict) else None
                if isinstance(source, str) and source.startswith(("./", "../")):
                    found.append(source)
        return found
    return LOCAL_SOURCE.findall(code_text(path.read_text()))


def module_directories(repo_root: Path) -> tuple[dict[Path, list[Path]], list[str]]:
    """({module directory: its configuration files}, symbolic-link problems)."""
    tree = repo_root / "infrastructure" / "tofu"
    modules: dict[Path, list[Path]] = {}
    links: list[str] = []
    for current, directories, _ in os.walk(tree):
        directories[:] = sorted(d for d in directories if not d.startswith("."))
        for name in directories:
            if (Path(current) / name).is_symlink():
                links.append(
                    f"{(Path(current) / name).relative_to(repo_root)} is a symbolic link to a "
                    f"directory; it is not followed, so a module behind it would never be "
                    f"validated. Move the module into the tree, or remove the link"
                )
        files = configuration_files(Path(current))
        if files:
            modules[Path(current).resolve()] = files
    return modules, links


def root_modules(repo_root: Path) -> tuple[list[Path], list[str]]:
    """(root module directories, problems) for the tree at `repo_root`."""
    tree = repo_root / "infrastructure" / "tofu"
    if not tree.is_dir():
        return [], [f"{tree} does not exist, so there is no OpenTofu configuration to validate"]
    modules, links = module_directories(repo_root)
    if not modules:
        return [], [
            f"no configuration file ({', '.join(CONFIGURATION_SUFFIXES)}) anywhere under "
            f"{tree}; `tofu validate` would have nothing to parse, and would say it is valid"
        ]

    problems: list[str] = list(links)
    called: set[Path] = set()
    calls: dict[Path, set[Path]] = {directory: set() for directory in modules}
    for directory, files in modules.items():
        for path in files:
            try:
                sources = local_sources(path)
            except CommentError as error:
                problems.append(f"{path.relative_to(repo_root)} holds {error}; its calls cannot be read")
                continue
            for source in sources:
                target = (directory / source).resolve()
                if target not in modules:
                    problems.append(
                        f"{path.relative_to(repo_root)} calls a module at {source!r}, "
                        f"which holds no configuration"
                    )
                elif target != directory:
                    called.add(target)
                    calls[directory].add(target)
    roots = sorted(set(modules) - called)
    if not roots and not problems:
        problems.append(
            "every module under infrastructure/tofu is called by another, so none is "
            "a root to validate from"
        )
    reached: set[Path] = set()
    pending = list(roots)
    while pending:
        module = pending.pop()
        if module not in reached:
            reached.add(module)
            pending.extend(calls[module])
    stranded = sorted(set(modules) - reached)
    if roots and stranded:
        problems.append(
            "reached from no root module, so no validate would load them: "
            + ", ".join(module.relative_to(repo_root.resolve()).as_posix() for module in stranded)
            + ". Modules that call only each other are a cycle OpenTofu refuses; call one "
            "from a root, or remove them"
        )
    return roots, problems


def loaded_problems(repo_root: Path, roots: list[Path]) -> tuple[int, list[str]]:
    """(module directories accounted for, problems): every module directory
    under infrastructure/tofu must be a root or appear in the module manifest
    `tofu init` wrote in some root (`.terraform/modules/modules.json`, whose
    `Dir` is relative to that root). Run after init. It depends on how
    OpenTofu resolved the calls, not on how this script read them, so a
    module the lexer took for called and OpenTofu never loaded is refused
    here. A root with no manifest loaded nothing beyond itself."""
    modules, _ = module_directories(repo_root)
    loaded = {root.resolve() for root in roots}
    problems: list[str] = []
    for root in roots:
        manifest = root / ".terraform" / "modules" / "modules.json"
        if not manifest.exists():
            continue
        try:
            entries = json.loads(manifest.read_text()).get("Modules") or []
        except (ValueError, AttributeError):
            problems.append(f"{manifest.relative_to(repo_root)} is not a module manifest this can read")
            continue
        for entry in entries:
            if isinstance(entry, dict) and isinstance(entry.get("Dir"), str):
                loaded.add((root / entry["Dir"]).resolve())
    for module in sorted(set(modules) - loaded):
        problems.append(
            f"{module.relative_to(repo_root.resolve()).as_posix()} holds configuration that is "
            f"not a root and that no root's `tofu init` loaded, so no validate parsed it"
        )
    return len(modules), problems


def main(argv: list[str]) -> int:
    flags = [arg for arg in argv[1:] if arg.startswith("--")]
    positional = [arg for arg in argv[1:] if not arg.startswith("--")]
    if set(flags) - {"--loaded-by-tofu"}:
        print(f"unknown option(s): {sorted(set(flags) - {'--loaded-by-tofu'})}", file=sys.stderr)
        return 2
    repo_root = (Path(positional[0]) if positional else Path(__file__).resolve().parents[2]).resolve()
    roots, problems = root_modules(repo_root)
    if problems:
        for problem in problems:
            print(problem, file=sys.stderr)
        return 1
    if flags:
        count, problems = loaded_problems(repo_root, roots)
        for problem in problems:
            print(problem, file=sys.stderr)
        if problems:
            return 1
        print(f"{count} module director(ies), each a root or loaded by a root's tofu init")
        return 0
    for root in roots:
        print(root.relative_to(repo_root).as_posix())
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
