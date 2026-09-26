#!/usr/bin/env python3
"""Proof that the no-apply gate refuses what its name claims to refuse.

Why this exists
---------------
`check-ci-cannot-apply.py` is one of four validators the Infrastructure job
runs as a gate. Of the four, one had a self-test. The other three -- this one,
`validate-monitoring.py` and `validate-runbooks.py` -- were trusted on the
strength of being green, which is the exact reasoning F-38 was opened about: a
gate that scans an empty subject passes silently and reads, in review, exactly
like a gate that found nothing wrong.

Being green is not evidence. This drives the validator over synthetic
workflow trees and checks it goes RED for each failure in the table below, and
stays GREEN for each legitimate shape there that superficially resembles one --
because a gate that cannot tell `ansible-playbook --check` from
`ansible-playbook` is a gate somebody will disable. The table holds the shapes
found so far, attack included; it is not every way to hide an apply from a
reading of text, and the validator's docstring says which ways it knows of.

Run: python3 infrastructure/scripts/test_check_ci_cannot_apply.py
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
    "check_ci_cannot_apply", HERE / "check-ci-cannot-apply.py"
)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)

# A workflow whose every step describes infrastructure rather than changing it.
GOOD = """
name: ci
on: [push]
jobs:
  infrastructure:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
      - name: OpenTofu is formatted and valid
        run: |
          tofu fmt -check -recursive infrastructure/tofu
          tofu validate
      - name: Ansible syntax
        run: ansible-playbook -i inventories/staging/hosts.yml playbooks/site.yml --syntax-check
"""


def run(workflows: dict[str, str]) -> tuple[int, str]:
    """Build a tree with these workflow files and return (exit code, stderr)."""
    with tempfile.TemporaryDirectory() as tmp:
        directory = Path(tmp) / ".github" / "workflows"
        directory.mkdir(parents=True)
        for name, body in workflows.items():
            (directory / name).write_text(body)
        err = io.StringIO()
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(err):
            code = gate.main(["check-ci-cannot-apply.py", tmp])
        return code, err.getvalue()


def bare() -> tuple[int, str]:
    """No `.github/workflows` at all -- the empty-subject case."""
    with tempfile.TemporaryDirectory() as tmp:
        err = io.StringIO()
        with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(err):
            code = gate.main(["check-ci-cannot-apply.py", tmp])
        return code, err.getvalue()


def step(run_body: str) -> str:
    """GOOD with one more step carrying `run_body`."""
    return GOOD + f"""      - name: the step under test
        run: {run_body}
"""


def block(run_text: str, shell: str | None = None) -> str:
    """GOOD with one more step whose `run: |` block is `run_text`, verbatim,
    and whose `shell:` is `shell` when one is given."""
    lines = "".join(f"          {line}\n" if line else "\n" for line in run_text.split("\n"))
    chosen = f"        shell: {shell}\n" if shell else ""
    return GOOD + f"      - name: the step under test\n{chosen}        run: |\n{lines}"


def job(run_text: str, header: str) -> str:
    """GOOD with a second job, whose settings are `header`, holding one step
    whose `run: |` block is `run_text`, verbatim."""
    lines = "".join(f"          {line}\n" if line else "\n" for line in run_text.split("\n"))
    return GOOD + f"  other:\n{header}    steps:\n      - name: the step under test\n        run: |\n{lines}"


CASES: list[tuple[str, dict[str, str] | None, str | tuple[str, ...] | None]] = [
    ("a workflow that only validates passes", {"ci.yml": GOOD}, None),
    (
        "`tofu apply` is caught",
        {"ci.yml": step("tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "`terraform apply` is caught under its other name",
        {"ci.yml": step("terraform apply")},
        "applies OpenTofu",
    ),
    (
        "`tofu destroy` is caught",
        {"ci.yml": step("tofu destroy -auto-approve")},
        "destroys OpenTofu resources",
    ),
    (
        "the apply script is caught by path",
        {"ci.yml": step("infrastructure/scripts/apply.sh production")},
        "runs the apply verb",
    ),
    (
        "a bare ansible-playbook is caught",
        {"ci.yml": step("ansible-playbook -i inventories/staging/hosts.yml playbooks/site.yml")},
        "runs a playbook outside check mode",
    ),
    (
        "ansible-playbook --check is not an apply",
        {"ci.yml": step("ansible-playbook -i hosts.yml site.yml --check")},
        None,
    ),
    (
        "an apply in a SECOND file is caught, not just the first",
        {"ci.yml": GOOD, "release.yml": step("tofu apply")},
        "applies OpenTofu",
    ),
    (
        "a pattern inside a comment is not an apply",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          # We deliberately never run `tofu apply` here; a person does that.
          tofu plan
"""
        },
        None,
    ),
    (
        "a comment does not launder the apply on the line below it",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          # tofu apply is forbidden in CI
          tofu apply -auto-approve
"""
        },
        "applies OpenTofu",
    ),
    (
        "a --syntax-check split across a line continuation is not an apply",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          ansible-playbook -i inventories/staging/hosts.yml \\
            playbooks/site.yml \\
            --syntax-check
"""
        },
        None,
    ),
    (
        "a continuation that does NOT reach a check flag is still an apply",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          ansible-playbook -i inventories/staging/hosts.yml \\
            playbooks/site.yml
"""
        },
        "runs a playbook outside check mode",
    ),
    (
        "the step that runs this gate is not itself an apply",
        {"ci.yml": step("python3 infrastructure/scripts/check-ci-cannot-apply.py .")},
        None,
    ),
    # F-38. The gate used to skip, before inspecting anything, every step whose
    # body merely CONTAINED its own file name -- and it did so before stripping
    # comments, so a comment was enough. Measured against that version: the
    # first two cases below went green over an apply. The third went red, but
    # only by accident -- both of its steps were skipped, nothing was
    # inspected, and the empty-subject refusal fired without either apply
    # being named. The fourth holds no apply; it pins that an empty `run:` is
    # not counted as a step inspected.
    (
        "a comment naming this gate does not exempt the apply below it",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          # guarded by check-ci-cannot-apply.py elsewhere
          tofu apply -auto-approve
"""
        },
        "applies OpenTofu",
    ),
    (
        "a real command that mentions this gate is still inspected",
        {"ci.yml": step("python3 infrastructure/scripts/check-ci-cannot-apply.py . && tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "two applies that each mention this gate are both caught",
        {
            "ci.yml": """
name: ci
on: [push]
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - name: first apply
        run: |
          # check-ci-cannot-apply.py has approved this workflow
          tofu apply -auto-approve
      - name: second apply
        run: python3 check-ci-cannot-apply.py . ; terraform apply
"""
        },
        ("step 'first apply' applies OpenTofu", "step 'second apply' applies OpenTofu"),
    ),
    (
        "a step whose `run:` is empty reaches the empty-subject refusal",
        {
            "ci.yml": """
name: ci
on: [push]
jobs:
  hollow:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
      - name: nothing to run
        run: ""
"""
        },
        "inspected no run steps",
    ),
    # F-38. Actions runs `.yaml` exactly as it runs `.yml`, and the gate used
    # to glob only the latter: a `deploy.yaml` that applied infrastructure was
    # a file this check never opened, beside a `ci.yml` it passed.
    (
        "an apply in a `.yaml` workflow is caught",
        {"ci.yml": GOOD, "deploy.yaml": step("tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a tree whose only workflow is `.yaml` is inspected, not refused as empty",
        {"ci.yaml": GOOD},
        None,
    ),
    (
        "a `uses:` step with no `run:` is skipped rather than crashed on",
        {
            "ci.yml": """
name: ci
on: [push]
jobs:
  only-actions:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
      - uses: opentofu/setup-opentofu@v1
"""
        },
        # Documented below: this is the empty-subject case and the gate must
        # refuse it rather than pass it.
        "inspected no run steps",
    ),
    ("no workflow directory at all is refused", None, "no workflow files found"),
    # F-38, round five. OpenTofu and Terraform take global options before the
    # subcommand, so `tofu -chdir=deploy apply` applies exactly as `tofu apply`
    # does -- and matched no pattern here.
    (
        "`tofu -chdir=... apply` is caught",
        {"ci.yml": step("tofu -chdir=deploy apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "`terraform -chdir=... destroy` is caught",
        {"ci.yml": step("terraform -chdir=deploy destroy -auto-approve")},
        "destroys OpenTofu resources",
    ),
    (
        "a quoted -chdir holding a space does not hide the apply after it",
        {"ci.yml": step("tofu -chdir='deploy dir' apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "`tofu -chdir=... validate` is not an apply",
        {"ci.yml": step("tofu -chdir=infrastructure/tofu validate")},
        None,
    ),
    # F-38, round five. The check-mode test used to look for `--check`
    # anywhere later on the same line, so a check flag belonging to the NEXT
    # command -- or to no command at all -- excused a real playbook run.
    (
        "a --check in a later command does not excuse the playbook before it",
        {"ci.yml": step("ansible-playbook -i inventories/staging playbooks/control-plane.yml && echo \"no --check needed\"")},
        "runs a playbook outside check mode",
    ),
    (
        "a bare --check belonging to the next command does not excuse the playbook",
        {"ci.yml": step("ansible-playbook -i inventories/staging playbooks/control-plane.yml && echo --check")},
        "runs a playbook outside check mode",
    ),
    (
        "a --check inside a quoted argument is not check mode",
        {"ci.yml": step("ansible-playbook -i hosts.yml site.yml -e \"msg=run --check later\"")},
        "runs a playbook outside check mode",
    ),
    (
        "a separator inside a quoted argument does not end the command",
        {"ci.yml": step("ansible-playbook -i hosts.yml site.yml -e \"a=1;b=2\" --check")},
        None,
    ),
    (
        "a --check in a trailing comment is not check mode",
        {
            "ci.yml": GOOD
            + """      - name: the step under test
        run: |
          ansible-playbook -i hosts.yml site.yml  # --check is for cowards
"""
        },
        "runs a playbook outside check mode",
    ),
    (
        "a second playbook run without --check is caught beside one with it",
        {"ci.yml": step("ansible-playbook site.yml --check; ansible-playbook site.yml")},
        "runs a playbook outside check mode",
    ),
    (
        "ansible-playbook --check followed by another command is still check mode",
        {"ci.yml": step("ansible-playbook -i hosts.yml site.yml --check && echo done")},
        None,
    ),
    # F-38, round six. Every line whose first non-blank character was `#` was
    # removed as a comment, whatever bash made of it, and continuations were
    # joined with a space bash does not put there. Each red case below passed
    # the gate before this round; each bash one reached its apply when bash ran
    # it with a stub standing in for the command. The PowerShell one follows
    # PowerShell's documented block comment and was not run: no pwsh here.
    (
        "a `#` line inside a quote an earlier line left open is not a comment",
        {"ci.yml": block('echo "deploying\n# "; tofu apply -auto-approve')},
        "applies OpenTofu",
    ),
    (
        "a playbook after a `#` line inside an open quote is not hidden either",
        {"ci.yml": block('echo "configuring\n# "; ansible-playbook -i inventories/production playbooks/control-plane.yml')},
        "runs a playbook outside check mode",
    ),
    (
        "a quote inside a comment opens nothing",
        {"ci.yml": block("true # it's fine\necho 'x\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a `#` a trailing backslash joins to the word before it is not a comment",
        {"ci.yml": block("echo a\\\n#b; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a comment after a line continuation ends the command; a flag below it is not its",
        {"ci.yml": block("ansible-playbook -i inventories/production playbooks/control-plane.yml \\\n# a note\n--check")},
        "runs a playbook outside check mode",
    ),
    (
        "a line continuation joins words as bash does, with no space between",
        {"ci.yml": block("ansible-playbook -i inventories/production/hosts.yml\\\n--check playbooks/control-plane.yml")},
        "runs a playbook outside check mode",
    ),
    (
        "a command split mid-word by a line continuation is read whole",
        {"ci.yml": block("tof\\\nu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a `#` inside ${...} starts no comment",
        {"ci.yml": block("echo ${x:- #} 'a\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "after a heredoc, which this does not follow, no comment line is removed",
        {"ci.yml": block("cat <<EOF\ndon't\nEOF\necho 'x\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a `#` right after `)`, where bash's reading depends on context, is not guessed at",
        {"ci.yml": block("x=$(true)#'\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a step another shell runs keeps its comment lines",
        # PowerShell: `<# ... #>` is one comment, so the apostrophe inside it
        # opens nothing, and the last line runs tofu.
        {"ci.yml": block("<#\nit's\n#>\necho 'x\n# '; tofu apply -auto-approve", shell="pwsh")},
        "applies OpenTofu",
    ),
    # And the other direction: the reading must still find the comments bash
    # finds, or the gate goes red on prose.
    (
        "a `#` line after a quote closed on an earlier line is still a comment",
        {"ci.yml": block('echo "one\ntwo"\n# tofu apply is for a person, never CI\ntofu plan')},
        None,
    ),
    (
        "a pattern in a trailing comment is not an apply",
        {"ci.yml": block("tofu plan  # never tofu apply here")},
        None,
    ),
    (
        "ansible-playbook -C is check mode",
        {"ci.yml": step("ansible-playbook -i hosts.yml site.yml -C")},
        None,
    ),
    # F-38, round seven. One case per branch of the reading that no case above
    # needed: each red one below went green with that branch taken out, and
    # reached its apply when the shell named ran it with a stub standing in
    # for tofu or ansible-playbook. They pin the branches; they are not a
    # list of every way to misread a shell.
    (
        "a `\\'` inside $'...' does not close it, so the `#` line after is inside the quote",
        {"ci.yml": block("echo $'it\\'s\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a backtick is not followed, so no comment line after it is removed",
        {"ci.yml": block("echo `true #` 'x\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a $( inside double quotes is not followed, so no comment line after it is removed",
        {"ci.yml": block("echo \"$(echo \" #\")\" 'x\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a backslash-escaped quote outside quotes opens nothing",
        {"ci.yml": block("echo \\' 'x\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "after a heredoc, which is not followed, a line continuation is still joined",
        {"ci.yml": block("cat <<EOF\nx\nEOF\ntofu \\\n  apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "an array subscript is not followed: bash reads `a[k #]` as one word",
        {"ci.yml": block("declare -A a\na[k #]='x\n# '; tofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "a --check inside a $'...' argument is not check mode",
        {"ci.yml": step("ansible-playbook -i hosts.yml site.yml -e $'it\\'s --check'")},
        "runs a playbook outside check mode",
    ),
    (
        "a step in a container job is not read as bash",
        # GitHub's default shell inside a container is sh; on a Debian image
        # that is dash, which ends the quote at the backslash and runs tofu.
        {"ci.yml": job("echo $'a\\' '\n# '; tofu apply -auto-approve",
                       "    runs-on: ubuntu-latest\n    container: debian:bookworm-slim\n")},
        "applies OpenTofu",
    ),
    (
        "a step on a Windows runner with no shell set is not read as bash",
        # PowerShell is the default there; the block comment as above.
        {"ci.yml": job("<#\nit's\n#>\necho 'x\n# '; tofu apply -auto-approve",
                       "    runs-on: windows-latest\n")},
        "applies OpenTofu",
    ),
    # Past the point where `code_lines` stops, and in a step read as another
    # shell, a backslash that ends a comment line is still inside the comment:
    # bash does not join the next line to it, and runs that line. Joining the
    # two would hide the apply inside the comment (`# deploytofu apply`).
    (
        "after a GitHub expression stops the reading, a comment ending in a backslash does not swallow the apply",
        {"ci.yml": block('echo "${{ github.sha }}"\n# deploy\\\ntofu apply -auto-approve')},
        "applies OpenTofu",
    ),
    (
        "after `(true)#x` stops the reading, its trailing backslash does not swallow the apply",
        {"ci.yml": block("(true)#x\\\ntofu apply -auto-approve")},
        "applies OpenTofu",
    ),
    (
        "in a step another shell runs, a comment ending in a backslash does not swallow the apply",
        {"ci.yml": block("# deploy\\\ntofu apply -auto-approve", shell="sh")},
        "applies OpenTofu",
    ),
    (
        "after the reading stops, a command continued over lines is still read joined",
        {"ci.yml": block('echo "${{ github.sha }}"\ntofu \\\n  apply -auto-approve')},
        "applies OpenTofu",
    ),
    # And the other direction: these keep a comment line bash drops, which
    # would turn a mention of an apply in prose into a red.
    (
        "a plain ${...} is read through, and the comment line after it is still one",
        {"ci.yml": block("echo ${HOME}\n# tofu apply is for a person, never CI\ntofu plan")},
        None,
    ),
    (
        "a here-string is not a heredoc, and the comment line after it is still one",
        {"ci.yml": block("cat <<< \"x\"\n# tofu apply is for a person, never CI\ntofu plan")},
        None,
    ),
]


# The table above is this self-test's subject, and a self-test over an emptied
# table prints `0/0 passed` and exits 0 -- the very shape the gate it proves
# exists to refuse. The count is literal source in this file, maintained by
# whoever edits the table, so adding or removing a case is a deliberate edit
# of this number too.
EXPECTED_CASES = 61


def main() -> int:
    if len(CASES) != EXPECTED_CASES:
        print(
            f"the case table holds {len(CASES)} case(s) and this file says "
            f"{EXPECTED_CASES}; change both together or neither"
        )
        return 1
    failures = 0
    for name, workflows, expected in CASES:
        code, err = bare() if workflows is None else run(workflows)
        if expected is None:
            ok = code == 0 and not err.strip()
        else:
            wanted = (expected,) if isinstance(expected, str) else expected
            ok = code == 1 and all(each in err for each in wanted)
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got exit {code} and stderr:\n{err}")
    print(f"\n{len(CASES) - failures}/{len(CASES)} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
