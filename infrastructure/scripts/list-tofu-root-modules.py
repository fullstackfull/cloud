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
or `source = "../..."` left once code_text has removed what HCL does not read
as code -- comments (`#` and `//` to the end of the line, `/* */` blocks,
wherever they stand outside a quoted string) and heredoc bodies. So a call
commented out is not a call: the module it names is a root of its own, and
validated, rather than counted as reached through a root that never loads it.
A `#`, `//` or `/*` inside a quoted string is text. A `source = "./..."`
written as an attribute outside a `module` block is still read as a call.
The self-test pins these readings.

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
# A heredoc opening at the end of a line: `<<EOT` or `<<-EOT`.
HEREDOC = re.compile(r"<<-?\s*([A-Za-z_][A-Za-z0-9_]*)[ \t]*(?:\n|$)")


class CommentError(ValueError):
    """A block comment this cannot read as HCL does."""


def code_text(text: str) -> str:
    """`text` with HCL's comments and heredoc bodies removed.

    Read as HCL's native syntax reads it: `#` and `//` start a comment to the
    end of the line, and `/*` one to the next `*/`, wherever they stand
    outside a quoted string -- a comment after code on the same line
    included. Inside a quoted string, and in the `${...}` or `%{...}` of one,
    they are text; a string's backslash escapes are followed. A heredoc
    (`<<EOT` or `<<-EOT` ending a line) is dropped through the line holding
    only its marker. Raises CommentError on a `/*` never closed, and on a
    `/*` inside a block comment: HCL ends the comment at the first `*/` and
    leaves the rest as code, and reading that shape any way at all here
    would be a guess.
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


def root_modules(repo_root: Path) -> tuple[list[Path], list[str]]:
    """(root module directories, problems) for the tree at `repo_root`."""
    tree = repo_root / "infrastructure" / "tofu"
    if not tree.is_dir():
        return [], [f"{tree} does not exist, so there is no OpenTofu configuration to validate"]

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


def main(argv: list[str]) -> int:
    repo_root = (Path(argv[1]) if len(argv) > 1 else Path(__file__).resolve().parents[2]).resolve()
    roots, problems = root_modules(repo_root)
    if problems:
        for problem in problems:
            print(problem, file=sys.stderr)
        return 1
    for root in roots:
        print(root.relative_to(repo_root).as_posix())
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
