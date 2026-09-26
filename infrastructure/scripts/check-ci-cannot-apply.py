#!/usr/bin/env python3
"""CI validates infrastructure; a person applies it.

A pipeline that can reimage a node on merge is a pipeline that eventually will,
on a branch nobody meant to merge. This parses the workflow files -- `.yml` and
`.yaml` alike, because GitHub Actions runs both -- and reads the `run:` text of
every step for the commands in `APPLYING`.

A step bash runs (`reads_as_bash`) is read as bash reads it (`code_lines`):
quotes, escapes and comments are followed from the top of the text, comments
are removed and line continuations joined, so a comment neither disarms the
check nor trips it, and a line that only looks like one -- a `#` inside a
quote an earlier line left open -- is read as the command it is. From the first
construct that reading does not follow, and in every step another shell runs,
no comment is removed: one there is read as code, a false red, the safe
direction.

It reads text; it does not execute or follow anything. A `uses:` step runs an
action's code, a job-level `uses:` runs a reusable workflow, and a `run:` step
can call a script or a Makefile target (`make deploy-staging` runs
ansible-playbook without --check). None of those is opened here, except a
reusable workflow that is itself a file in `.github/workflows`, which is read
like any other. What passing establishes is therefore narrower than "no
workflow applies infrastructure": no step's `run:` text, read as above, matches
a pattern in `APPLYING`, a list that names the known applying commands and
cannot name every way to reach one.

Exit status 0 when no step's `run:` text, read as above, matches a pattern in
`APPLYING`; 1 when one does, and 1 when there is no workflow file, or no `run:`
text, to read.
"""

from __future__ import annotations

import re
import shlex
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover
    print("PyYAML is required: pip install pyyaml", file=sys.stderr)
    raise SystemExit(2)

# OpenTofu and Terraform accept global options before the subcommand --
# `tofu -chdir=deploy apply` is an apply -- so any run of `-option` words, a
# quoted value included, may stand between the binary and the verb.
_GLOBAL_OPTIONS = r"""(?:\s+-(?:[^\s"']|"[^"]*"|'[^']*')+)*"""

CHECK_MODE_FLAGS = {"--check", "-C", "--syntax-check"}

# After one of these -- bash's metacharacters -- a word begins, and a word that
# begins with `#` is a comment, running to the end of its line.
_BREAKS = " \t\n;&|<>()"

_IDENTIFIER = re.compile(r"[A-Za-z_][A-Za-z0-9_]*")

# A `${...}` holding none of these reads as one opaque word wherever it stands:
# bash reads no comment inside it (`${x:- #}` is `#`). One holding a quote, an
# escape, a nested expansion or a brace of its own -- a GitHub `${{ }}`, whose
# value is not in the text -- is where following stops.
_PLAIN_EXPANSION = re.compile(r"\$\{[^{}'\"`\\$\n]*\}")

# A backslash-newline that is not itself escaped.
_CONTINUATION = re.compile(r"(?<!\\)((?:\\\\)*)\\\n")


def code_lines(text: str) -> str:
    """`text` as bash reads it: comments removed, line continuations joined.

    Quotes ('...', "..." and $'...'), backslash escapes and comments are
    followed from the top as bash follows them, so a `#` is a comment only
    where bash takes one: beginning a word, outside quotes. A line inside a
    quote an earlier line left open is kept, and so is one a trailing backslash
    joins to the word before it (`a\\` then `#b` is the word `a#b`).

    Bash's reading of some constructs depends on context this does not track:
    a heredoc, a backtick, `((`, `$[`, a `$(` inside double quotes, a `${...}`
    other than a plain one, an array subscript or compound assignment, and a
    `#` right after `(` or `)` (`(true)#x` is a comment, `$(true)#x` is not).
    At the first of them following stops, and the rest of the text is kept as
    it is -- comments included, continuations joined unless escaped.
    """
    out: list[str] = []
    quote = ""  # the quote open here: "", "'", '"' or "$'"
    word = ""  # the word read so far, "" where one begins
    at = 0
    while at < len(text):
        char, pair = text[at], text[at:at + 2]
        if quote in ("'", "$'"):
            step = 2 if quote == "$'" and char == "\\" else 1
            out.append(text[at:at + step])
            quote = "" if char == "'" else quote
            at += step
            continue
        if pair == "\\\n":  # outside quotes and inside "...", bash removes it
            at += 2
            continue
        if char == "\\":
            out.append(pair)
            word += pair
            at += 2
            continue
        plain = _PLAIN_EXPANSION.match(text, at)
        if plain:
            out.append(plain.group())
            word += plain.group()
            at = plain.end()
            continue
        if char == "`" or pair in ("${", "$[") or (quote == '"' and pair == "$("):
            break
        if quote == '"':
            out.append(char)
            word += char
            quote = "" if char == '"' else quote
            at += 1
            continue
        if text.startswith("<<<", at):  # a here-string, not a heredoc
            out.append("<<<")
            word = ""
            at += 3
            continue
        if pair in ("<<", "((") or (char == "(" and word.endswith("=")) or (
            char == "[" and _IDENTIFIER.fullmatch(word)
        ):
            break
        if char == "#" and not word:
            if out and out[-1][-1:] in ("(", ")"):
                break
            end = text.find("\n", at)
            at = len(text) if end < 0 else end
            continue
        if char in ("'", '"') or pair == "$'":
            quote = pair if pair == "$'" else char
            out.append(quote)
            word += quote
            at += len(quote)
            continue
        out.append(char)
        word = "" if char in _BREAKS else word + char
        at += 1
    else:
        return "".join(out)
    return "".join(out) + _CONTINUATION.sub(r"\1", text[at:])


def reads_as_bash(workflow: dict, job: dict, step: dict) -> bool:
    """Whether GitHub runs this step's `run:` with bash: the `shell:` in force
    -- the step's, else the job's default, else the workflow's -- names bash,
    or none is set and the job runs outside a container on one GitHub-hosted
    Linux or macOS runner label (`ubuntu-*`, `macos-*`), where bash is the
    default. Any other step may be run by a shell whose comments and quotes
    are not bash's."""
    for scope in (
        step,
        (job.get("defaults") or {}).get("run") or {},
        (workflow.get("defaults") or {}).get("run") or {},
    ):
        if scope.get("shell") is not None:
            words = str(scope["shell"]).split()
            return bool(words) and words[0].rsplit("/", 1)[-1] == "bash"
    if job.get("container") is not None:
        return False
    labels = job.get("runs-on")
    labels = [labels] if isinstance(labels, str) else labels
    return (
        isinstance(labels, list)
        and len(labels) == 1
        and isinstance(labels[0], str)
        and re.fullmatch(r"(?:ubuntu|macos)-[\w.-]+", labels[0]) is not None
    )


def command_words(body: str, start: int) -> list[str] | None:
    """The words of the shell command that continues from `start`.

    It ends at the first `;`, `&`, `|`, `)` or newline outside quotes, or at a
    `#` that begins a word, which starts a comment. Quotes are removed as the
    shell removes them, so `-e "x --check"` is one word and not a flag. None
    when the quoting does not close, which the caller treats as no check flag.
    """
    quote = None
    at = start
    while at < len(body):
        char = body[at]
        if quote:
            if char == "\\" and quote == '"':
                at += 2
                continue
            if char == quote:
                quote = None
        elif char == "\\":
            at += 2
            continue
        elif char in "'\"":
            quote = char
        elif char in ";&|)\n" or (char == "#" and body[at - 1].isspace()):
            break
        at += 1
    try:
        return shlex.split(body[start:at])
    except ValueError:
        return None


def runs_playbook_outside_check_mode(body: str) -> bool:
    """True if any `ansible-playbook` in the body lacks --check, -C or
    --syntax-check among its own command's words. A flag in the next command,
    in a quoted argument or in a trailing comment is not that command's."""
    for found in re.finditer(r"ansible-playbook", body):
        words = command_words(body, found.end())
        if words is None or not CHECK_MODE_FLAGS & set(words):
            return True
    return False


# Commands that change real infrastructure rather than describing it.
APPLYING = [
    (re.compile(rf"\b(tofu|terraform){_GLOBAL_OPTIONS}\s+apply\b").search, "applies OpenTofu"),
    (re.compile(rf"\b(tofu|terraform){_GLOBAL_OPTIONS}\s+destroy\b").search, "destroys OpenTofu resources"),
    (re.compile(r"scripts/apply\.sh").search, "runs the apply verb"),
    # A check-mode or syntax-check run writes nothing and is fine; a playbook
    # run without either flag among its own words is not. Matching on the
    # mode rather than on playbook names means a new playbook is covered the
    # day it is added, and a legitimate --check step in CI does not have to be
    # argued about.
    (runs_playbook_outside_check_mode, "runs a playbook outside check mode"),
]

# No step is exempt, including the one that runs this script. It used to be:
# any step whose body merely CONTAINED this file's name was skipped before it
# was inspected -- and before comments were stripped -- so a comment naming the
# gate disarmed it over the apply on the next line, and the skipped step did
# not even appear in the "N run step(s) inspected" count. The exemption was
# never load-bearing: the invocation that runs this gate matches none of the
# patterns above, so it is inspected like every other step and passes.


def steps_of(workflow: dict):
    for job_name, job in (workflow.get("jobs") or {}).items():
        for step in job.get("steps") or []:
            yield job_name, job, step


def main(argv: list[str]) -> int:
    root = Path(argv[1]) if len(argv) > 1 else Path.cwd()
    directory = root / ".github" / "workflows"
    workflows = sorted([*directory.glob("*.yml"), *directory.glob("*.yaml")])
    if not workflows:
        print("no workflow files found", file=sys.stderr)
        return 1

    problems: list[str] = []
    checked = 0

    for path in workflows:
        workflow = yaml.safe_load(path.read_text()) or {}
        for job_name, job, step in steps_of(workflow):
            command = step.get("run")
            if not command:
                continue
            checked += 1
            # A comment explaining why we do not apply is not an apply, and a
            # command split over three lines with backslashes is still one
            # command -- reading it as three is how `ansible-playbook ... \
            # --syntax-check` gets mistaken for a real run. Both are bash's
            # rules, so they are applied only where bash runs the step.
            if reads_as_bash(workflow, job, step):
                body = code_lines(command)
            else:
                body = _CONTINUATION.sub(r"\1", command)
            for applies, what in APPLYING:
                if applies(body):
                    problems.append(
                        f"{path.name}: job '{job_name}', step "
                        f"'{step.get('name', '<unnamed>')}' {what}"
                    )

    print(f"{len(workflows)} workflow file(s), {checked} run step(s) inspected")

    # A gate that inspected nothing is not a gate that found nothing. There are
    # workflow files here and not one of them has a non-empty `run:` step,
    # which means either the parse produced nothing usable or every step has
    # become a `uses:` (or a `run: ""`) -- and an action, or a reusable
    # workflow kept anywhere but `.github/workflows`, is code this check never
    # opens, so the applies would have moved somewhere it cannot see while it
    # went on printing a green line. Refuse, and say which it is.
    if checked == 0:
        print(
            f"inspected no run steps across {len(workflows)} workflow file(s). "
            f"Either the workflows did not parse, or every step is now a `uses:` "
            f"and the commands moved into actions or workflows this check does "
            f"not read. Neither is a pass.",
            file=sys.stderr,
        )
        return 1

    for problem in problems:
        print(f"  FAIL {problem}", file=sys.stderr)
    if problems:
        print(
            "CI validates infrastructure; a person applies it from the "
            "deployment controller.",
            file=sys.stderr,
        )

    return 1 if problems else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
