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
or `source = "../..."` on a line that does not begin with `#` or `//` and is
not inside a heredoc (`<<EOT` or `<<-EOT` ending a line, through the line
holding only `EOT`). A call commented out inside a `/* */` block, or after
code on the same line, is still read as a call; it would make its module look
called, and if nothing else called it, the module would be refused below as
reached from no root rather than dropped. The self-test pins these readings.

What it refuses
---------------
Exit status 1, printing nothing to stdout, when infrastructure/tofu holds no
configuration at all, when it holds a symbolic link to a directory, when a
local `source` names a directory that holds none, or when a module is reached
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
HEREDOC = re.compile(r"<<-?\s*([A-Za-z_][A-Za-z0-9_]*)\s*$")


def code_text(text: str) -> str:
    """`text` without whole-line `#` and `//` comments and heredoc bodies."""
    kept: list[str] = []
    closing: str | None = None
    for line in text.splitlines():
        if closing is not None:
            if line.strip() == closing:
                closing = None
            continue
        if line.lstrip().startswith(("#", "//")):
            continue
        opened = HEREDOC.search(line)
        if opened:
            closing = opened.group(1)
            line = line[: opened.start()]
        kept.append(line)
    return "\n".join(kept)


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
            for source in local_sources(path):
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
