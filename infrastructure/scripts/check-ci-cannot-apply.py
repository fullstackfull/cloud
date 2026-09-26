#!/usr/bin/env python3
"""CI validates infrastructure; a person applies it.

A pipeline that can reimage a node on merge is a pipeline that eventually will,
on a branch nobody meant to merge. This is a tripwire over what CI spells out,
not a proof that no workflow can apply, and what follows says what it reads.

What it reads. The `run:` text of each step of each job in each `.yml` and
`.yaml` file directly under `.github/workflows` (GitHub Actions runs both),
matched against the patterns in `APPLYING`: tofu or terraform apply and destroy
(global options allowed before the verb), `scripts/apply.sh`, and
ansible-playbook without --check, -C or --syntax-check among its own command's
words (`command_words`). Before matching, a step `reads_as_bash` picks out has
its comments removed and line continuations joined by `code_lines`, a reading
of a subset of bash's grammar that stops at the first construct outside the
subset and keeps the rest, comments included. Text kept that way, and the
whole of any other step, is read twice (`both_readings`): as written, and with
its continuations joined, since a backslash ending a comment line does not
join the next line in bash and one ending a command does. Kept text is the
safe direction -- a comment read as code is a false red -- and removed or
joined-away text that bash runs is the dangerous one.

What it does not read. A `uses:` step runs an action's code, a job-level
`uses:` runs a reusable workflow, and a `run:` step can call a script or a
Makefile target (`make deploy-staging` runs ansible-playbook without --check).
None of those is opened here, except a reusable workflow that is itself a file
in `.github/workflows`, which is read like any other. `APPLYING` names the
applying commands known here and cannot name every way to reach one; the
subset `code_lines` follows, the constructs it stops at and the rules
`reads_as_bash` applies are what attack on this gate has found so far, not a
boundary inside which bash and this reading agree.

Measured in the tree today, where this script prints 1 workflow file and 44
run steps (`python3 infrastructure/scripts/check-ci-cannot-apply.py .`):
20 `uses:` steps (`grep -E '^\\s+(- )?uses:' .github/workflows/*.yml`), of
seven actions -- actions/checkout, cache, setup-node, setup-python and
upload-artifact, opentofu/setup-opentofu and shivammathur/setup-php -- which
check out, cache, install a toolchain or upload a file; no job-level `uses:`;
no step read as another shell, every job being `runs-on: ubuntu-latest` with
no `shell:` or `container:`; no `make` call; 11 steps that run a script file
from this repository -- nine of the Python validators and self-tests here,
pint, and test_safety_gate.sh, which runs ansible-playbook without --check
against the runner itself through a throwaway `ansible_connection: local`
inventory, running only `assert` and `debug`; 5 bash steps where `code_lines`
stops early; and no step whose verdict removing comments changes, since no
step's text matches a pattern in `APPLYING` even with its comments left in.
The other steps run tools -- composer, npm and npx, php artisan, ansible-lint,
tofu fmt and validate, git -- whose code is not read either. No package.json
script names an applying command (`git ls-files '*package.json' | xargs grep
-lE 'tofu|terraform|ansible-playbook|apply\\.sh'` finds none); the backend
test suite that `php artisan test` runs holds application code that builds an
ansible-playbook command (AnsibleDeploymentController), and whether a test run
reaches it is that code's guard's question, not measured here.

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


def both_readings(text: str) -> str:
    """`text` as written, then again with its continuations joined, one after
    the other, for text whose comments are not known.

    Joined alone would be wrong: a backslash that ends a comment line is inside
    the comment, bash does not join the next line to it and runs that line,
    and joining turns `# deploy\\` then `tofu apply` into the one comment
    `# deploytofu apply`. As written alone would read `tofu \\` then `apply`
    as two lines. A pattern that matches either reading matches here; a match
    only one reading makes is a red where bash may not apply, which is the
    safe direction.
    """
    return text + "\n" + _CONTINUATION.sub(r"\1", text)


def code_lines(text: str) -> str:
    """`text` with comments removed and line continuations joined, by a
    reading of a subset of bash's grammar.

    The subset: quotes ('...', "..." and $'...'), backslash escapes, a plain
    `${...}` read as one word, and a here-string, followed from the top of the
    text. A `#` is taken as a comment where it begins a word outside quotes,
    which is bash's rule. A line inside a quote an earlier line left open is
    kept, and so is one a trailing backslash joins to the word before it
    (`a\\` then `#b` is the word `a#b`).

    It stops following at the first construct found to be read by bash in a
    way that depends on context this does not track: a heredoc, a backtick,
    `((`, `$[`, a `$(` inside double quotes, a `${...}` other than a plain one,
    an array subscript or compound assignment, and a `#` right after `(` or `)`
    (`(true)#x` is a comment, `$(true)#x` is not). From there the rest of the
    text is kept, comments included, and read twice by `both_readings`: as
    written, and with continuations joined. Those are the constructs attack
    has found so far, not every place bash and this reading part: one not
    listed that bash reads differently
    could make this remove a line bash runs. In the tree today it stops early
    in 5 of the 44 `run:` steps, and in none does removing comments change the
    verdict (the module docstring has the measurement).
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
    return "".join(out) + both_readings(text[at:])


def reads_as_bash(workflow: dict, job: dict, step: dict) -> bool:
    """Whether this reads the step's `run:` as bash: the `shell:` in force --
    the step's, else the job's default, else the workflow's -- names bash, or
    none is set and the job runs outside a container on a single runner label
    `ubuntu-*` or `macos-*`, which on GitHub-hosted runners defaults to bash.
    Any other step may be run by a shell whose comments and quotes are not
    bash's, and is not read as bash. The label is read as a name: a
    self-hosted runner carrying one of those labels is read as bash whatever
    shell it has. In the tree today every job is `runs-on: ubuntu-latest`
    with no `shell:` or `container:`, so every step is read as bash."""
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

    It ends at the first `;`, `&`, `|`, `)` or newline outside '...' or "..."
    quotes, or at a `#` that begins a word, which starts a comment. Quotes are
    removed by shlex's POSIX rules, so `-e "x --check"` is one word and not a
    flag. None when the quoting does not close under those rules -- `$'it\\'s'`
    is one such, being bash's and not POSIX's -- which the caller treats as no
    check flag. In the tree today there is one `ansible-playbook` in any
    `run:` text, with --syntax-check among its own words.
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
            # --syntax-check` gets mistaken for a real run. Comments are bash's
            # rule, so they are removed only in a step `reads_as_bash` picks
            # out, and only as far as `code_lines` follows.
            if reads_as_bash(workflow, job, step):
                body = code_lines(command)
            else:
                body = both_readings(command)
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
